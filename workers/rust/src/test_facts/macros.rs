//! Which macros invoked where an item may stand are known to declare none.
//!
//! Only a frozen list is: the assertion macros and a few standard ones
//! (`println!`, `dbg!`…), each only when the path is the bare name or
//! `std::`/`core::` and nothing in the crate may put another macro under
//! that name: no `macro_rules!` of it (which would shadow it,
//! unhygienically), no `use` binding it (another crate's macro, or a
//! renamed one), and no `#[macro_use] extern crate` or glob from outside the
//! crate, which may bring in any name. Any other macro may declare any name
//! where it is invoked, so it leaves the scope's literals unknown.

use std::collections::BTreeSet;

use syn::visit::Visit;

use super::assertions::{is_assertion_macro, macro_name};
use crate::resolve::ident_name;
use crate::visit::Declarations;

/// The standard macros, beside the assertions, that expand to no item.
const ITEMLESS_MACROS: [&str; 13] = [
    "println",
    "print",
    "eprintln",
    "eprint",
    "panic",
    "todo",
    "unimplemented",
    "unreachable",
    "dbg",
    "write",
    "writeln",
    "format",
    "drop",
];

/// The crates a frozen macro may be named through.
const STANDARD_CRATES: [&str; 2] = ["std", "core"];

/// The roots a glob `use` may import from without reaching another crate's
/// macros: this crate's own modules, and the standard crates, whose macros
/// are the standard ones.
const LOCAL_OR_STANDARD_ROOTS: [&str; 6] = ["crate", "self", "super", "std", "core", "alloc"];

/// What one file brings in that may put another macro under a standard
/// macro's name.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub(crate) struct MacroShadows {
    /// Every `macro_rules!` the file defines and every name a `use` of it
    /// binds (its leaf, or its rename), at any depth.
    pub(crate) names: BTreeSet<String>,
    /// Whether the file opens its crate to another crate's macros by any
    /// name: a `#[macro_use] extern crate`, or a glob `use` from a root
    /// other than [`LOCAL_OR_STANDARD_ROOTS`].
    pub(crate) opens_all: bool,
}

impl MacroShadows {
    /// What `items` bring in, at any depth.
    pub(crate) fn of(items: &[syn::Item]) -> Self {
        let mut shadows = Self::default();
        for item in items {
            shadows.visit_item(item);
        }
        shadows
    }

    /// Whether these may put another macro under `name`.
    fn shadow(&self, name: &str) -> bool {
        self.opens_all || self.names.contains(name)
    }

    /// Record the names `tree`, under the path segments `prefix`, binds.
    fn add_use_tree(&mut self, tree: &syn::UseTree, prefix: &mut Vec<String>) {
        match tree {
            syn::UseTree::Path(node) => {
                prefix.push(ident_name(&node.ident));
                self.add_use_tree(&node.tree, prefix);
                prefix.pop();
            }
            syn::UseTree::Name(node) => {
                let name = ident_name(&node.ident);
                if name == "self" {
                    self.names.extend(prefix.last().cloned());
                } else {
                    self.names.insert(name);
                }
            }
            syn::UseTree::Rename(node) => {
                self.names.insert(ident_name(&node.rename));
            }
            syn::UseTree::Glob(_) => {
                self.opens_all |= prefix
                    .first()
                    .is_none_or(|root| !LOCAL_OR_STANDARD_ROOTS.contains(&root.as_str()));
            }
            syn::UseTree::Group(node) => {
                for tree in &node.items {
                    self.add_use_tree(tree, prefix);
                }
            }
        }
    }
}

impl Visit<'_> for MacroShadows {
    fn visit_item_macro(&mut self, node: &syn::ItemMacro) {
        if let Some(ident) = &node.ident {
            self.names.insert(ident_name(ident));
        }
        syn::visit::visit_item_macro(self, node);
    }

    fn visit_item_use(&mut self, node: &syn::ItemUse) {
        let mut prefix = Vec::new();
        if node.leading_colon.is_some() {
            // `::name` always names another crate.
            prefix.push("::".to_owned());
        }
        self.add_use_tree(&node.tree, &mut prefix);
    }

    fn visit_item_extern_crate(&mut self, node: &syn::ItemExternCrate) {
        self.opens_all |= node.attrs.iter().any(|attr| {
            attr.path().is_ident("macro_use")
                || (attr.path().is_ident("cfg_attr")
                    && attr
                        .meta
                        .require_list()
                        .is_ok_and(|list| list.tokens.to_string().contains("macro_use")))
        });
    }
}

/// The macros one file and its crate may put under a standard name, which
/// a frozen name may be shadowed by.
pub(super) struct Frozen<'d> {
    /// What the walked file brings in.
    own: MacroShadows,
    /// The walked file's module, whose crate the index is asked about.
    module: &'d str,
    /// The scan-wide index, which knows every other file's.
    declarations: &'d Declarations,
}

impl<'d> Frozen<'d> {
    /// The frozen names as `items`, the walked file's in `module`, and the
    /// crate's other files leave them.
    pub(super) fn of(items: &[syn::Item], module: &'d str, declarations: &'d Declarations) -> Self {
        Self {
            own: MacroShadows::of(items),
            module,
            declarations,
        }
    }

    /// Whether `mac`, invoked where an item may stand, may declare one.
    pub(super) fn declares_items(&self, mac: &syn::Macro) -> bool {
        let name = macro_name(mac);
        let standard_path = match mac.path.segments.len() {
            1 => mac.path.leading_colon.is_none(),
            2 => mac
                .path
                .segments
                .first()
                .is_some_and(|head| STANDARD_CRATES.iter().any(|krate| head.ident == krate)),
            _ => false,
        };
        let frozen = (is_assertion_macro(mac) || ITEMLESS_MACROS.contains(&name.as_str()))
            && standard_path
            && !self.own.shadow(&name)
            && !self.declarations.may_shadow_macro(self.module, &name);
        !frozen
    }
}
