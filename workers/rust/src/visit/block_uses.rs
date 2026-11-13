//! `use` items inside a block: the names and globs they bring in for the
//! rest of that block.

use std::collections::{BTreeMap, BTreeSet};

use syn::spanned::Spanned;
use syn::Item;

use super::cfg::cfg_predicate;
use super::state::Walk;
use crate::facts::reference;
use crate::resolve::{
    flatten_use, glob_prefixes, ident_name, is_root, parent_module, Aliases, UseLeaf,
};

impl Walk<'_> {
    /// Bring what the `use` items among a block's `statements` import into
    /// scope, over the file's own imports of the same names, returning the
    /// imports to restore when the block ends (see
    /// [`Walk::leave_block_uses`]); `None` when the block has no `use`.
    ///
    /// An item in a block is in scope in the whole block, so the `use`
    /// items are read before its first statement. `container` is the module
    /// the body is declared in, which a `self` or `super` path and a child
    /// module's name resolve against. Each leaf emits the `imports` edge a
    /// module-level `use` does. Two leaves of one block binding a name to
    /// different paths leave it bound to nothing there. A glob `use` in a
    /// block is not read.
    pub(super) fn enter_block_uses(
        &mut self,
        container: &str,
        statements: &[syn::Stmt],
    ) -> Option<(Aliases, usize)> {
        let uses: Vec<&syn::ItemUse> = statements
            .iter()
            .filter_map(|statement| match statement {
                syn::Stmt::Item(Item::Use(node)) => Some(node),
                _ => None,
            })
            .collect();
        if uses.is_empty() {
            return None;
        }
        let outer = self.aliases.clone();
        let children = self
            .module_children
            .get(container)
            .cloned()
            .unwrap_or_default();
        // Items the block declares besides its `use` items: a path through
        // one names something local to the body, which the graph does not
        // hold, so a leaf starting from one binds its name to nothing.
        let local: BTreeSet<String> = statements
            .iter()
            .filter_map(|statement| match statement {
                syn::Stmt::Item(item) => block_item_name(item),
                _ => None,
            })
            .collect();
        // A glob (`use theseus_host::services::systemd::*;`) brings in its
        // module's names for the rest of the block, as one at module level
        // does for the module: its source joins the module's globs until the
        // block ends. It is resolved once the block's own names are bound,
        // which Rust reads it through (`use crate::right as selected; use
        // selected::*;`).
        let globs_before = self.globs.len();
        let mut block_globs: Vec<(bool, String, proc_macro2::Span)> = Vec::new();
        for node in &uses {
            let mut globs = Vec::new();
            glob_prefixes(&node.tree, "", &mut globs);
            for glob in globs {
                let unrooted = node.leading_colon.is_none();
                let head = glob.split("::").next().unwrap_or(&glob);
                if unrooted && !is_root(head) && local.contains(head) {
                    continue;
                }
                block_globs.push((unrooted, glob, node.span()));
            }
        }
        let mut written: Vec<((UseLeaf, bool), proc_macro2::Span, bool)> = Vec::new();
        for node in uses {
            let mut leaves = Vec::new();
            flatten_use(&node.tree, "", &mut leaves);
            let gated = cfg_predicate(&node.attrs).is_some();
            for leaf in leaves {
                written.push(((leaf, node.leading_colon.is_none()), node.span(), gated));
            }
        }
        let leaves: Vec<(UseLeaf, bool)> =
            written.iter().map(|(leaf, _, _)| leaf.clone()).collect();
        let gated: Vec<bool> = written.iter().map(|(_, _, gated)| *gated).collect();
        let placed = self.place_scope_leaves(&leaves, &gated, |leaf, unrooted| {
            let head = leaf.full.split("::").next().unwrap_or(&leaf.full);
            if unrooted && !is_root(head) && local.contains(head) {
                return None;
            }
            self.block_use_path(container, &children, unrooted, &leaf.full)
        });
        let source = reference("module", &self.module);
        // Each name, with the paths it binds and whether every `use`
        // binding it is gated, so `cfg` alternatives keep all of theirs.
        let mut bound: BTreeMap<String, (Vec<String>, bool, bool)> = BTreeMap::new();
        for (((leaf, _), span, gated), alternatives) in written.into_iter().zip(placed) {
            // A leaf that places nothing still shadows the outer binding of
            // its name, which it hides in this block.
            let fulls: Vec<String> = alternatives
                .into_iter()
                .map(|full| {
                    let module = if leaf.names_module {
                        full.clone()
                    } else {
                        parent_module(&full).to_owned()
                    };
                    self.import(&source, &module, span);
                    self.exported(full)
                })
                .collect();
            if leaf.alias == "_" {
                continue;
            }
            let entry = bound
                .entry(leaf.alias)
                .or_insert_with(|| (Vec::new(), true, false));
            entry.1 &= gated;
            entry.2 |= fulls.is_empty();
            for full in fulls {
                if !entry.0.contains(&full) {
                    entry.0.push(full);
                }
            }
        }
        for (alias, (fulls, gated, unplaced)) in bound {
            match fulls.len() {
                _ if unplaced => self.aliases.shadow(alias, None),
                1 => self.aliases.shadow(alias, fulls.into_iter().next()),
                _ if gated => self.aliases.shadow_alternatives(alias, fulls),
                _ => self.aliases.shadow(alias, None),
            }
        }
        for (unrooted, glob, span) in block_globs {
            if let Some(found) = self.block_use_path(container, &children, unrooted, &glob) {
                let placed = self.exported(found);
                self.import(&source, &placed, span);
                self.globs.push((container.to_owned(), placed));
            }
        }

        Some((outer, globs_before))
    }

    /// The path one leaf of a `use` in a block imports. Its first segment
    /// may be any name in scope there, as since the 2018 edition: one the
    /// module or an enclosing block imports stands for what it imports,
    /// while a child module, a rooted path or any other name is taken as
    /// [`Walk::imported_path`] takes a module-level leaf. `None` when that
    /// name was imported ambiguously.
    pub(super) fn block_use_path(
        &self,
        container: &str,
        children: &BTreeMap<String, Option<String>>,
        unrooted: bool,
        written: &str,
    ) -> Option<String> {
        let head = written.split("::").next().unwrap_or(written);
        if unrooted && !is_root(head) && !children.contains_key(head) {
            if let Some(expanded) = self.aliases.expand(written) {
                return Some(expanded);
            }
            if self.aliases.is_ambiguous(head) {
                return None;
            }
        }

        self.imported_path(container, children, unrooted, written)
    }

    /// End the scope [`Walk::enter_block_uses`] opened, restoring the
    /// imports it returned.
    pub(super) fn leave_block_uses(&mut self, outer: Option<(Aliases, usize)>) {
        if let Some((outer, globs)) = outer {
            self.aliases = outer;
            self.globs.truncate(globs);
        }
    }
}

/// The name an item declared in a block binds in the type namespace a path
/// can start from, or None for an item that binds none (`use`, `impl`).
pub(super) fn block_item_name(item: &Item) -> Option<String> {
    let ident = match item {
        Item::Struct(node) => &node.ident,
        Item::Enum(node) => &node.ident,
        Item::Union(node) => &node.ident,
        Item::Trait(node) => &node.ident,
        Item::Mod(node) => &node.ident,
        Item::Type(node) => &node.ident,
        _ => return None,
    };

    Some(ident_name(ident))
}
