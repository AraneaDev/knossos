//! A file's `use` lines, read before anything else: each name they bind,
//! where it is placed, and the `imports` edges they leave.

use std::collections::BTreeMap;

use syn::spanned::Spanned;
use syn::Item;

use super::cfg::cfg_predicate;
use super::globs::Globbed;
use super::placement::mod_child;
use super::state::Walk;
use crate::facts::reference;
use crate::resolve::{
    flatten_use, glob_prefixes, ident_name, is_root, parent_module, rebase, UseLeaf,
};

impl Walk<'_> {
    /// Record every `use` in this item list, emitting one `imports` edge each.
    ///
    /// Imports are collected before declarations are walked, because a call in
    /// the first function may name something imported at the bottom of the file.
    ///
    /// `container` only drives recursion into nested `mod` blocks — it builds
    /// the canonical path passed to a further-nested `collect_uses` call — and
    /// never the edge source, which is always `self.module`, the file's own
    /// module, regardless of how deep the `use` line is nested.
    ///
    /// A nested `mod` block's `use` lines are still collected into the same
    /// file-wide alias map. That is wider than Rust's own scoping, which would
    /// hide them from the outer module, and it is the deliberate trade: this
    /// worker resolves names to emit edges, and a name that resolves in the
    /// file it appears in produces a true edge whichever block declared it.
    /// That flatness has a cost the map itself owns up to: two different `use`
    /// lines in different modules that import different full paths under the
    /// same local name collide. See `Aliases::insert` for how that collision
    /// is handled without ever emitting a silently wrong edge.
    ///
    /// `flatten_use` renders a `self`- or `super`-rooted leaf literally
    /// (`self::foo`, `super::foo`); [`rebase`] resolves that against
    /// `container`, the module the `use` line actually appears in, before the
    /// leaf becomes an edge target or an alias. A leaf `rebase` cannot place —
    /// a `super` chain longer than `container` has segments to give up —
    /// contributes neither an edge nor an alias.
    ///
    /// The edge points at the MODULE the imported name lives in, not at the
    /// name itself: `use a::b::C;` emits `imports` to `rust:module:a::b`, and
    /// `C` goes into the alias map, exactly as `visit_ImportFrom` in
    /// `workers/python/bin/knossos_python/collector.py` splits the two. A `use`
    /// overwhelmingly names a struct, trait, or function, whose real node kind
    /// is `class`, `interface`, or `function`, so targeting
    /// `rust:module:a::b::C` resolved against nothing and made the reconciler
    /// synthesise an external module that shadowed the very symbol the file had
    /// already declared. The module is a node the graph genuinely holds. Two
    /// shapes need no truncation: a single-segment `use foo;` already names a
    /// module (see [`parent_module`]), and a `self` leaf already names its
    /// prefix module (see [`crate::resolve::UseLeaf::names_module`]).
    ///
    /// Several symbols imported from the same module each produce an edge to
    /// that module; the core collapses them to one per kind, source, target
    /// and owner, so a module importing from the same place on ten separate
    /// lines still stores one row.
    pub(super) fn collect_uses(&mut self, container: &str, items: &[Item]) {
        // `use policy::Policy;` beside `mod policy;` names that child module:
        // since the 2018 edition a path's first segment may be any name in
        // scope, and a module declared here is one. Left as written, the path
        // read as an external crate's.
        let mut children: BTreeMap<String, Option<String>> = BTreeMap::new();
        for item in items {
            if let Item::Mod(node) = item {
                let (child, file) =
                    mod_child(&self.relative, &self.module, container, node, self.layout);
                self.placed.extend(file);
                let name = ident_name(&node.ident);
                match children.get(&name) {
                    Some(Some(existing)) if *existing != child => {
                        children.insert(name, None);
                    }
                    Some(_) => {}
                    None => {
                        children.insert(name, Some(child));
                    }
                }
            }
        }
        for (name, child) in &children {
            if child.as_deref() != Some(format!("{container}::{name}").as_str()) {
                self.renamed_children
                    .insert((container.to_owned(), name.clone()), child.clone());
            }
        }
        self.module_children
            .insert(container.to_owned(), children.clone());
        // `pub extern crate grep_searcher as searcher;` binds and re-exports
        // the crate as `use ::grep_searcher as searcher;` would, which is how
        // a facade crate exposes its parts. Each is read as that `use`.
        let externs: Vec<(syn::ItemUse, proc_macro2::Span)> = items
            .iter()
            .filter_map(|item| match item {
                Item::ExternCrate(node) => {
                    extern_crate_use(node).map(|use_item| (use_item, item.span()))
                }
                _ => None,
            })
            .collect();
        let mut written: Vec<((UseLeaf, bool), &syn::ItemUse, proc_macro2::Span)> = Vec::new();
        for (node, span) in &externs {
            let mut leaves = Vec::new();
            flatten_use(&node.tree, "", &mut leaves);
            for leaf in leaves {
                written.push(((leaf, false), node, *span));
            }
        }
        for item in items {
            match item {
                Item::Use(node) => {
                    let mut leaves = Vec::new();
                    flatten_use(&node.tree, "", &mut leaves);
                    for leaf in leaves {
                        written.push(((leaf, node.leading_colon.is_none()), node, item.span()));
                    }
                    let mut globs = Vec::new();
                    glob_prefixes(&node.tree, "", &mut globs);
                    for glob in globs {
                        let written =
                            match through_child(&children, node.leading_colon.is_none(), &glob) {
                                Some(Some(written)) => written,
                                Some(None) => continue,
                                None => glob,
                            };
                        self.pending_globs.push((
                            container.to_owned(),
                            written,
                            node.leading_colon.is_some(),
                            item.span(),
                            matches!(node.vis, syn::Visibility::Inherited),
                        ));
                    }
                }
                Item::Mod(node) => {
                    if let Some((_, inner)) = &node.content {
                        let nested = format!("{container}::{}", ident_name(&node.ident));
                        self.collect_uses(&nested, inner);
                    }
                }
                _ => {}
            }
        }
        let leaves: Vec<(UseLeaf, bool)> = written
            .iter()
            .map(|((leaf, unrooted), _, _)| (leaf.clone(), *unrooted))
            .collect();
        let gated: Vec<bool> = written
            .iter()
            .map(|(_, node, _)| cfg_predicate(&node.attrs).is_some())
            .collect();
        let placed = self.place_scope_leaves(&leaves, &gated, |leaf, unrooted| {
            let head = leaf.full.split("::").next().unwrap_or(&leaf.full);
            let own = format!("{container}::{head}");
            if unrooted
                && !is_root(head)
                && !children.contains_key(head)
                && self.own_declarations.contains(&own)
            {
                return self.renamed(format!("{container}::{}", leaf.full));
            }
            self.imported_path(container, &children, unrooted, &leaf.full)
        });
        let source = reference("module", &self.module);
        for (((leaf, _), node, span), alternatives) in written.into_iter().zip(placed) {
            // A leaf placed through a name `cfg` alternatives bind
            // (`pub use platform::open;`) binds each of their paths.
            let gated = alternatives.len() > 1 || cfg_predicate(&node.attrs).is_some();
            let private = matches!(node.vis, syn::Visibility::Inherited);
            for full in alternatives {
                let binding = Binding {
                    leaf: leaf.clone(),
                    full,
                    gated,
                    private,
                };
                self.bind_module_leaf(container, &source, span, binding);
            }
        }
    }

    /// Record one module-level `use` leaf `container` imports `full` by:
    /// its `imports` edge, its re-export when the `use` is visible, and the
    /// name it binds.
    pub(super) fn bind_module_leaf(
        &mut self,
        container: &str,
        source: &str,
        span: proc_macro2::Span,
        binding: Binding,
    ) {
        let Binding {
            leaf,
            full,
            gated,
            private,
        } = binding;
        let module = if leaf.names_module {
            full.clone()
        } else {
            parent_module(&full).to_owned()
        };
        // The import names the module the source wrote; the alias, what that
        // name is declared as. A leaf naming an item of `container` itself
        // (`pub use LazyCell as LazyLock;`) imports no other module.
        if module != container {
            self.import(source, &module, span);
        }
        // A name `container` declares an item by as well as imports belongs
        // to `#[cfg]` alternatives (`pub use LazyCell as LazyLock;` beside a
        // `struct LazyLock` under another `cfg`): which one compiles is
        // unknown, so the name re-exports nothing and keeps naming the item
        // declared here.
        let name = format!("{container}::{}", leaf.alias);
        if self.own_declarations.contains(&name) {
            self.exports.insert(name, None);
            return;
        }
        // A private `use` is a path too, from the module's descendants:
        // `use super::platform;` in a child names what the parent's own
        // `use unix as platform;` imports. Compiling code only goes through
        // one where it is visible, so every binding is recorded.
        if leaf.alias != "_" {
            if private {
                self.private_exports.insert(name.clone());
            } else {
                self.public_exports.insert(name.clone());
            }
            let alternatives = self
                .export_alternatives
                .entry(name.clone())
                .or_insert_with(|| Some(Vec::new()));
            match alternatives {
                Some(known) if gated => {
                    if !known.contains(&full) {
                        known.push(full.clone());
                    }
                }
                _ => *alternatives = None,
            }
            match self.exports.get(&name) {
                Some(Some(existing)) if *existing != full => {
                    self.exports.insert(name, None);
                }
                Some(_) => {}
                None => {
                    self.exports.insert(name, Some(full.clone()));
                }
            }
        }
        let full = self.exported(full);
        let key = (container.to_owned(), leaf.alias.clone());
        match self.module_aliases.get(&key) {
            Some(Some(existing)) if existing != &full => {
                self.module_aliases.insert(key, None);
            }
            Some(_) => {}
            None => {
                self.module_aliases.insert(key, Some(full.clone()));
            }
        }
        self.aliases.insert_gated(leaf.alias, full, gated);
    }

    /// Where each `use` leaf of one scope (a module's items, or a block's)
    /// imports from, given with whether its path is unrooted.
    ///
    /// Since the 2018 edition a path's first segment may be any name in
    /// scope, another `use` of the same scope included, whichever line
    /// comes first: `use m::W;` beside `use crate::m;` names `crate::m::W`.
    /// A leaf whose unrooted head another leaf of the scope binds is
    /// expanded through what that leaf imports, once that is known, so the
    /// leaves are placed in a fixpoint bounded by their number. Every other
    /// leaf is placed by `place`. A leaf whose own path starts from the name
    /// it binds (`alloc` in `use alloc::alloc::{alloc, dealloc};`) binds no
    /// other leaf's head: that head is the outer name its own path starts
    /// from. A head two leaves bind apart, or a chain of leaves that never
    /// settles, places nothing.
    ///
    /// Each leaf is placed at no path, at one, or, when the head it starts
    /// from is bound apart only by `use` items `gated` marks as gated by a
    /// `cfg`, at one path per alternative binding.
    pub(super) fn place_scope_leaves(
        &self,
        leaves: &[(UseLeaf, bool)],
        gated: &[bool],
        mut place: impl FnMut(&UseLeaf, bool) -> Option<String>,
    ) -> Vec<Vec<String>> {
        let binders: Vec<Vec<usize>> = leaves
            .iter()
            .enumerate()
            .map(|(index, (leaf, unrooted))| {
                let head = leaf.full.split("::").next().unwrap_or(&leaf.full);
                if !unrooted || is_root(head) {
                    return Vec::new();
                }
                leaves
                    .iter()
                    .enumerate()
                    .filter(|(other, (binder, _))| {
                        *other != index
                            && binder.alias == head
                            && binder.full.split("::").next() != Some(head)
                    })
                    .map(|(other, _)| other)
                    .collect()
            })
            .collect();
        let mut placed: Vec<Option<Vec<String>>> = leaves
            .iter()
            .zip(&binders)
            .map(|((leaf, unrooted), binders)| {
                binders
                    .is_empty()
                    .then(|| place(leaf, *unrooted).into_iter().collect())
            })
            .collect();
        for _ in 0..leaves.len() {
            let mut progress = false;
            for index in 0..leaves.len() {
                if placed[index].is_some()
                    || binders[index]
                        .iter()
                        .any(|&binder| placed[binder].is_none())
                {
                    continue;
                }
                let mut meanings: Vec<String> = Vec::new();
                let mut unplaced = false;
                for &binder in &binders[index] {
                    let binder_placed = placed[binder].clone().unwrap_or_default();
                    unplaced |= binder_placed.is_empty();
                    for full in binder_placed {
                        let full = self.exported(full);
                        if !meanings.contains(&full) {
                            meanings.push(full);
                        }
                    }
                }
                // Binders that agree bind one meaning; binders that differ
                // bind none, unless every one of them is a `cfg` alternative.
                let alternatives = binders[index].iter().all(|&binder| gated[binder]);
                let bound: Vec<String> = match meanings.len() {
                    _ if unplaced => Vec::new(),
                    1 => meanings,
                    _ if alternatives => meanings,
                    _ => Vec::new(),
                };
                let full = &leaves[index].0.full;
                placed[index] = Some(
                    bound
                        .into_iter()
                        .filter_map(|bound| {
                            self.renamed(match full.split_once("::") {
                                Some((_, rest)) => format!("{bound}::{rest}"),
                                None => bound,
                            })
                        })
                        .collect(),
                );
                progress = true;
            }
            if !progress {
                break;
            }
        }

        placed.into_iter().map(Option::unwrap_or_default).collect()
    }

    /// The path one `use` leaf written in `container` imports, before
    /// re-exports are followed: through a `mod` of `container` (`children`,
    /// unless the path is rooted at `::`), the `crate`, `self` and `super`
    /// roots and the project's renamed `mod` declarations. `None` when a
    /// child or a rename is ambiguous, or a `super` chain runs out.
    pub(super) fn imported_path(
        &self,
        container: &str,
        children: &BTreeMap<String, Option<String>>,
        unrooted: bool,
        written: &str,
    ) -> Option<String> {
        let written = match through_child(children, unrooted, written) {
            Some(Some(written)) => written,
            Some(None) => return None,
            None => written.to_owned(),
        };
        if let Some(through) = self.through_rooted_glob(container, children, unrooted, &written) {
            return self.renamed(through);
        }

        rebase(container, &self.anchor_crate(&written)).and_then(|full| self.renamed(full))
    }

    /// A `use` path whose first segment only a glob import of `container`
    /// brings into scope, rewritten through it: `use process::run;` under
    /// `use super::*;`, where the parent has `use super::process;`. Only a
    /// glob rooted at `self`, `super` or `crate` is read, which needs no
    /// other `use` of the file to place. `None` when the head is rooted, a
    /// child or this module's own item, or no such glob provides it.
    pub(super) fn through_rooted_glob(
        &self,
        container: &str,
        children: &BTreeMap<String, Option<String>>,
        unrooted: bool,
        written: &str,
    ) -> Option<String> {
        let (head, rest) = match written.split_once("::") {
            Some((head, rest)) => (head, Some(rest)),
            None => (written, None),
        };
        if !unrooted
            || is_root(head)
            || children.contains_key(head)
            || self
                .own_declarations
                .contains(&format!("{container}::{head}"))
        {
            return None;
        }
        let mut sources: Vec<String> = self
            .pending_globs
            .iter()
            .filter(|(declared_in, glob, absolute, _, _)| {
                declared_in == container
                    && !absolute
                    && is_root(glob.split("::").next().unwrap_or(glob))
            })
            .filter_map(|(_, glob, _, _, _)| rebase(container, &self.anchor_crate(glob)))
            .collect();
        sources.extend(self.glob_sources(container));
        sources.dedup();
        if sources.is_empty() {
            return None;
        }
        match self.through_globs(container, &sources, head) {
            Globbed::One(target) => Some(match rest {
                Some(rest) => format!("{target}::{rest}"),
                None => target,
            }),
            Globbed::Absent | Globbed::Ambiguous => None,
        }
    }

    /// The edge a `use` leaves from `source` to the scope it imports from.
    ///
    /// A scope whose last segment is UpperCamelCase is a type, not a module:
    /// `use crate::errors::Error::Io;` and `use Error::*;` take variants or
    /// associated items of `Error`, so they reference the type (speculative,
    /// struct or trait being unknown) instead of importing a module the graph
    /// would invent.
    pub(super) fn import(&mut self, source: &str, scope: &str, span: proc_macro2::Span) {
        let last = scope.rsplit("::").next().unwrap_or(scope);
        if scope.contains("::") && last.starts_with(char::is_uppercase) {
            for kind in ["class", "interface"] {
                self.facts
                    .speculative_edge("references", source, &reference(kind, scope), span);
            }
            return;
        }
        self.facts.edge(
            "imports",
            source,
            &reference("module", scope),
            "certain",
            span,
        );
    }
}

/// One path a module-level `use` leaf binds its name to.
pub(super) struct Binding {
    /// The leaf as written.
    leaf: UseLeaf,
    /// The path it imports.
    full: String,
    /// Whether that path is one `cfg` alternative of the name: the `use`
    /// itself is gated, or it is written through a name gated `use` items
    /// bind apart.
    gated: bool,
    /// Whether the `use` is private, visible only in its module and the
    /// modules below it.
    private: bool,
}

/// A `use` path whose head names a `mod` of the module it is written in,
/// rewritten onto that module's path (see [`mod_child`]); `Some(None)` when
/// two declarations of that name load different modules, `None` for any
/// other path. A leading `::` (`unrooted` false) always names a crate.
#[allow(clippy::option_option)]
pub(super) fn through_child(
    children: &BTreeMap<String, Option<String>>,
    unrooted: bool,
    path: &str,
) -> Option<Option<String>> {
    if !unrooted {
        return None;
    }
    let (head, rest) = match path.split_once("::") {
        Some((head, rest)) => (head, Some(rest)),
        None => (path, None),
    };
    let child = children.get(head)?;

    Some(child.as_ref().map(|child| match rest {
        Some(rest) => format!("{child}::{rest}"),
        None => child.clone(),
    }))
}

/// The rooted `use` an `extern crate` item is equivalent to:
/// `extern crate a as b;` is `use ::a as b;`, `extern crate a;` is `use ::a;`.
/// `extern crate self as name;` names this crate, which no rooted path can,
/// so it is left out.
pub(super) fn extern_crate_use(node: &syn::ItemExternCrate) -> Option<syn::ItemUse> {
    if node.ident == "self" {
        return None;
    }
    let tree = match &node.rename {
        Some((as_token, rename)) => syn::UseTree::Rename(syn::UseRename {
            ident: node.ident.clone(),
            as_token: *as_token,
            rename: rename.clone(),
        }),
        None => syn::UseTree::Name(syn::UseName {
            ident: node.ident.clone(),
        }),
    };
    Some(syn::ItemUse {
        attrs: node.attrs.clone(),
        vis: node.vis.clone(),
        use_token: syn::token::Use::default(),
        leading_colon: Some(syn::token::PathSep::default()),
        tree,
        semi_token: node.semi_token,
    })
}
