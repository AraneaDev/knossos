//! Which macros invoked where an item may stand are known to declare none.
//!
//! Only a frozen list is: the macros the standard library's prelude gives
//! (`assert_eq!`, `println!`, `dbg!`…), each only when the path is the bare
//! name or `std::`/`core::` and nothing in the package may put another
//! macro under that name: no `macro_rules!` of it (which would shadow it,
//! unhygienically), no `use` binding it (another crate's macro, or a
//! renamed one), no `#[macro_use] extern crate`, which may bring in any
//! name, no `extern crate … as std|core|alloc` and no `mod std|core|alloc`,
//! which make another crate's macros the "standard" ones, and no `#[path]`
//! loading a file the scan does not hold. A glob `use` cannot: `rustc`
//! rejects a glob-imported macro under a name the standard prelude also
//! gives (E0659). That protects the prelude's names only, so an assertion
//! macro outside it (`prop_assert!`, `assert_matches!`) is never frozen. Any
//! macro not frozen may declare any name where it is invoked, so it leaves
//! the scope's literals unknown.

use std::collections::{BTreeMap, BTreeSet};

use syn::visit::Visit;

use super::assertions::{is_assertion_macro, macro_name};
use crate::layout::{Layout, ModDeclaration};
use crate::resolve::ident_name;
use crate::visit::{Declarations, ANY_PACKAGE};

/// The macros the standard prelude gives that expand to no item: a glob
/// cannot import another macro under one of these names (E0659).
const STANDARD_MACROS: [&str; 18] = [
    "assert",
    "assert_eq",
    "assert_ne",
    "debug_assert",
    "debug_assert_eq",
    "debug_assert_ne",
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
];

/// The macros of `pretty_assertions` that compare as the standard ones of
/// their names do, with a different message.
const PRETTY_ASSERTIONS: [&str; 2] = ["assert_eq", "assert_ne"];

/// The crate [`PRETTY_ASSERTIONS`] are of.
const PRETTY_CRATE: &str = "pretty_assertions";

/// The crates a frozen macro may be named through.
const STANDARD_CRATES: [&str; 2] = ["std", "core"];

/// The names an `extern crate … as` or a `mod` may take to stand in for a
/// standard crate.
const STANDARD_NAMES: [&str; 3] = ["std", "core", "alloc"];

/// What one file brings in that may put another macro under a standard
/// macro's name.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub(crate) struct MacroShadows {
    /// Every `macro_rules!` the file defines and every name a `use` of it
    /// binds (its leaf, or its rename), at any depth.
    pub(crate) names: BTreeSet<String>,
    /// Whether the file opens its crate to another crate's macros by any
    /// name: a `#[macro_use] extern crate`, or an `extern crate` or a `mod`
    /// named one of [`STANDARD_NAMES`].
    pub(crate) opens_all: bool,
    /// The names a `use` binds to `pretty_assertions`' `assert_eq` and
    /// `assert_ne` under their own names: frozen only in a package whose
    /// `pretty_assertions` is the real crate (G5-07, see
    /// [`MacroShadows::shadowing`]).
    pub(crate) pretty: BTreeSet<String>,
    /// Whether the file may bind `pretty_assertions` to something else: an
    /// `extern crate other as pretty_assertions`, a `mod` of that name, or
    /// a `use` binding it to another path.
    pub(crate) renames_pretty: bool,
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

    /// The names these may put another macro under, in a package whose
    /// `pretty_assertions` is the real crate when `pretty_real`.
    pub(crate) fn shadowing(&self, pretty_real: bool) -> BTreeSet<String> {
        let mut names = self.names.clone();
        if !pretty_real {
            names.extend(self.pretty.iter().cloned());
        }
        names
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
                let bound = if name == "self" {
                    prefix.last().cloned()
                } else {
                    Some(name.clone())
                };
                // `use ::pretty_assertions;` and `use pretty_assertions::{self}`
                // bind the crate itself.
                let itself = (prefix.is_empty() && name == PRETTY_CRATE)
                    || (prefix.as_slice() == [PRETTY_CRATE] && name == "self");
                self.renames_pretty |= bound.as_deref() == Some(PRETTY_CRATE) && !itself;
                if is_same_comparison(prefix, &name, &name) {
                    self.pretty.insert(name);
                } else {
                    self.names.extend(bound);
                }
            }
            syn::UseTree::Rename(node) => {
                let rename = ident_name(&node.rename);
                let leaf = ident_name(&node.ident);
                self.renames_pretty |=
                    rename == PRETTY_CRATE && !(prefix.is_empty() && leaf == PRETTY_CRATE);
                if is_same_comparison(prefix, &leaf, &rename) {
                    self.pretty.insert(rename);
                } else {
                    self.names.insert(rename);
                }
            }
            // A glob-imported macro under a standard name is ambiguous
            // (E0659), so it shadows nothing that compiles.
            syn::UseTree::Glob(_) => {}
            syn::UseTree::Group(node) => {
                for tree in &node.items {
                    self.add_use_tree(tree, prefix);
                }
            }
        }
    }
}

/// Whether `use PREFIX::LEAF as BOUND` brings in a comparison macro that
/// compares as the standard one of the same name: `pretty_assertions`'s
/// `assert_eq` and `assert_ne`, under their own names. Such a `use`
/// leaves the name frozen where `pretty_assertions` is the real crate.
fn is_same_comparison(prefix: &[String], leaf: &str, bound: &str) -> bool {
    prefix == [PRETTY_CRATE] && leaf == bound && PRETTY_ASSERTIONS.contains(&leaf)
}

impl Visit<'_> for MacroShadows {
    fn visit_item_macro(&mut self, node: &syn::ItemMacro) {
        if let Some(ident) = &node.ident {
            self.names.insert(ident_name(ident));
        }
        syn::visit::visit_item_macro(self, node);
    }

    fn visit_item_mod(&mut self, node: &syn::ItemMod) {
        // `mod core { pub use other::*; }` makes `core::todo!` another
        // crate's: a path-qualified macro is no glob import.
        let name = ident_name(&node.ident);
        self.opens_all |= STANDARD_NAMES.contains(&name.as_str());
        self.renames_pretty |= name == PRETTY_CRATE;
        syn::visit::visit_item_mod(self, node);
    }

    fn visit_item_use(&mut self, node: &syn::ItemUse) {
        self.add_use_tree(&node.tree, &mut Vec::new());
    }

    fn visit_item_extern_crate(&mut self, node: &syn::ItemExternCrate) {
        // `extern crate other as core;` makes `core::todo!` another crate's.
        self.opens_all |= node
            .rename
            .as_ref()
            .is_some_and(|(_, rename)| STANDARD_NAMES.contains(&ident_name(rename).as_str()));
        // `extern crate other as pretty_assertions;` puts another crate
        // under the name (G5-07).
        self.renames_pretty |= node.rename.as_ref().is_some_and(|(_, rename)| {
            ident_name(rename) == PRETTY_CRATE && ident_name(&node.ident) != PRETTY_CRATE
        });
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

/// The macros one file and its package may put under a standard name,
/// which a frozen name may be shadowed by.
pub(super) struct Frozen<'d> {
    /// What the walked file brings in.
    own: MacroShadows,
    /// The walked file's path, whose package the index is asked about.
    relative: &'d str,
    /// The scan-wide index, which knows every other file's.
    declarations: &'d Declarations,
}

impl<'d> Frozen<'d> {
    /// The frozen names as `items`, the walked file's at `relative`, and
    /// its package's other files leave them.
    pub(super) fn of(
        items: &[syn::Item],
        relative: &'d str,
        declarations: &'d Declarations,
    ) -> Self {
        Self {
            own: MacroShadows::of(items),
            relative,
            declarations,
        }
    }

    /// Whether `mac` is an assertion the worker reads: an assertion
    /// macro's name, still the standard one. A project macro under that
    /// name (`macro_rules! assert_eq`) may assert anything, so it is an
    /// ordinary macro.
    pub(super) fn is_assertion(&self, mac: &syn::Macro) -> bool {
        is_assertion_macro(mac) && !self.declares_items(mac)
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
        let frozen = STANDARD_MACROS.contains(&name.as_str())
            && standard_path
            && !self.own.shadow(&name)
            && !self.declarations.may_shadow_macro(self.relative, &name);
        !frozen
    }
}

/// Every file compiled as part of another one wherever it sits: one a
/// `#[path]` declaration loads (a crate root's too), one below such a file
/// (`placements`), and one `include!` pulls in (`included`). It belongs to
/// the declaring file's package, which may not be the one around it.
pub(crate) fn compiled_elsewhere<'a>(
    layout: &Layout,
    mods: &BTreeMap<String, Vec<ModDeclaration>>,
    included: impl Iterator<Item = &'a String>,
) -> BTreeSet<String> {
    let mut files: BTreeSet<String> = included
        .cloned()
        .chain(layout.placements.keys().cloned())
        .collect();
    for (file, list) in mods {
        for declaration in list {
            if let Some(path) = declaration.path.as_deref() {
                files.extend(layout.child_file(
                    file,
                    &declaration.inline,
                    &declaration.name,
                    Some(path),
                ));
            }
        }
    }
    files
}

/// The package whose files may shadow a standard macro in `relative` and
/// be shadowed by it: the directory of the deepest manifest above it.
/// [`ANY_PACKAGE`] when that cannot be shown: no manifest owns the file, it
/// is one of the files `elsewhere` (see [`compiled_elsewhere`]), or its
/// package sits in another's module tree (see
/// [`Layout::in_nested_package`]).
pub(crate) fn macro_package(
    layout: &Layout,
    relative: &str,
    elsewhere: &BTreeSet<String>,
) -> String {
    if elsewhere.contains(relative) || layout.in_nested_package(relative) {
        return ANY_PACKAGE.to_owned();
    }
    layout
        .packages
        .iter()
        .map(|(directory, _)| directory)
        .filter(|directory| relative.starts_with(directory.as_str()))
        .max_by_key(|directory| directory.len())
        .map_or_else(
            || ANY_PACKAGE.to_owned(),
            |directory| format!("package:{directory}"),
        )
}

/// `module`, and the module each of its file's bodiless `mod` declarations
/// (`mods`) names, whether or not a file holds it: one created there may
/// start to shadow a standard macro. A `#[path]` declaration names a file,
/// not a module path, and is left to the file it loads.
pub(crate) fn macro_modules(module: &str, mods: &[ModDeclaration]) -> BTreeSet<String> {
    let mut modules = BTreeSet::from([module.to_owned()]);
    for declaration in mods.iter().filter(|declaration| declaration.path.is_none()) {
        let mut path = vec![module];
        path.extend(declaration.inline.iter().map(String::as_str));
        path.push(&declaration.name);
        modules.insert(path.join("::"));
    }
    modules
}

/// Whether one of `declared`, the bodiless `mod` declarations of the file
/// `relative`, has a `#[path]` to a file the scan does not hold (`known`):
/// outside the project root, or anywhere else it does not read. What that
/// file brings in is unseen, so it may put another macro under any name.
pub(crate) fn loads_unseen_file(
    layout: &Layout,
    relative: &str,
    declared: &[ModDeclaration],
    known: &BTreeMap<String, Vec<ModDeclaration>>,
) -> bool {
    declared.iter().any(|declaration| {
        declaration.path.as_deref().is_some_and(|path| {
            layout
                .child_file(relative, &declaration.inline, &declaration.name, Some(path))
                .is_none_or(|file| !known.contains_key(&file))
        })
    })
}

/// The package keys (see [`macro_package`]) whose `pretty_assertions` is
/// the real crate, from every indexed file's key and what it brings in
/// (`keyed`): the package's manifest depends on it under that name (see
/// [`Layout::pretty_assertions`]), and no file of the package, nor one
/// any package's may be, binds the name to something else. A file whose
/// package cannot be shown is in none (G5-07).
pub(crate) fn pretty_real(layout: &Layout, keyed: &[(String, MacroShadows)]) -> BTreeSet<String> {
    let renamed: BTreeSet<&str> = keyed
        .iter()
        .filter(|(_, shadows)| shadows.renames_pretty)
        .map(|(key, _)| key.as_str())
        .collect();
    if renamed.contains(ANY_PACKAGE) {
        return BTreeSet::new();
    }
    layout
        .pretty_assertions
        .iter()
        .map(|directory| format!("package:{directory}"))
        .filter(|key| !renamed.contains(key.as_str()))
        .collect()
}
