//! Which macros invoked where an item may stand are known to declare none.
//!
//! Only a frozen list is: the assertion macros and a few standard ones
//! (`println!`, `dbg!`…), each only when the project defines no
//! `macro_rules!` of that name (which would shadow it, unhygienically) and
//! the path is the bare name or `std::`/`core::`. Any other macro may
//! declare any name where it is invoked, so it leaves the scope's literals
//! unknown.

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

/// The `macro_rules!` names one file and the project define, which a frozen
/// name may be shadowed by.
pub(super) struct Frozen<'d> {
    /// The names the walked file defines.
    own: BTreeSet<String>,
    /// The scan-wide index, which knows every other file's.
    declarations: &'d Declarations,
}

impl<'d> Frozen<'d> {
    /// The frozen names as `items`, the walked file's, and the project's
    /// other files leave them.
    pub(super) fn of(items: &[syn::Item], declarations: &'d Declarations) -> Self {
        Self {
            own: macro_rules_names(items),
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
            && !self.own.contains(&name)
            && !self.declarations.defines_macro_rules(&name);
        !frozen
    }
}

/// The name of every `macro_rules!` the items define, at any depth.
pub(crate) fn macro_rules_names(items: &[syn::Item]) -> BTreeSet<String> {
    let mut names = MacroRules::default();
    for item in items {
        names.visit_item(item);
    }
    names.0
}

/// A visitor collecting [`macro_rules_names`].
#[derive(Default)]
struct MacroRules(BTreeSet<String>);

impl Visit<'_> for MacroRules {
    fn visit_item_macro(&mut self, node: &syn::ItemMacro) {
        if let Some(ident) = &node.ident {
            self.0.insert(ident_name(ident));
        }
        syn::visit::visit_item_macro(self, node);
    }
}
