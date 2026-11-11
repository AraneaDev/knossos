//! Reading attributes: which items compile only under `test`, which
//! functions are tests, and the literal arguments a `mod` or route
//! attribute carries.
//!
//! A test marker removes code from the dead-code and hub budgets, so these
//! readers evaluate what an attribute says structurally instead of matching
//! its text.

use std::collections::{BTreeMap, BTreeSet};

use syn::Item;

use super::placement::mod_child;
use crate::layout::{modules_above, Layout};

/// What the crate's bodiless `mod name;` declarations say about the files
/// they load: which modules compile only under `cfg(test)`, and which only
/// under some other `cfg`.
///
/// Rust compiles such a file only under its declaration's `cfg`, but the
/// file is scanned as its own contribution and carries no attribute saying
/// so. The declaring file is the only place the fact exists, so it is
/// collected there and handed to the walk of every file in the request.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct ModuleGates {
    /// Canonical paths of modules declared `#[cfg(test)] mod name;`.
    pub test: BTreeSet<String>,
    /// Canonical paths of modules declared under a `cfg` that is not
    /// test-only (`#[cfg(windows)] mod scm;`), with the predicate as written.
    /// Code in one is a `cfg` alternative: a declaration another file makes
    /// at the same path compiles in other builds, not beside it.
    pub gated: BTreeMap<String, String>,
    /// The predicate of the declaration that loads each such file, by the
    /// file: `cfg` alternatives of one module (`#[cfg(unix)] #[path =
    /// "unix.rs"] mod native;` beside a Windows one) are two files in one
    /// module, each compiled under its own declaration's `cfg`.
    pub files: BTreeMap<String, String>,
    /// Canonical paths of modules declared under a `cfg` that holds only in
    /// a test build or with a test-support feature on (see
    /// [`is_cfg_test_support`]): code the tests use, not the product.
    pub support: BTreeSet<String>,
}

impl ModuleGates {
    /// No module gated.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// Add what `other` gates. A module two declarations gate under different
    /// predicates compiles under either.
    pub fn extend(&mut self, other: &Self) {
        self.test.extend(other.test.iter().cloned());
        self.support.extend(other.support.iter().cloned());
        for (module, predicate) in &other.gated {
            merge_gate(&mut self.gated, module, predicate);
        }
        for (file, predicate) in &other.files {
            merge_gate(&mut self.files, file, predicate);
        }
    }

    /// The `cfg` the file `relative`, placed in `module`, compiles under:
    /// as [`ModuleGates::gate_of`] has it, with the file's own declaration's
    /// predicate standing for its module's.
    #[must_use]
    pub fn gate_of_file(&self, relative: &str, module: &str) -> Option<String> {
        let Some(own) = self.files.get(relative) else {
            return self.gate_of(module);
        };
        let mut gates: Vec<&str> = modules_above(module)
            .into_iter()
            .filter_map(|path| self.gated.get(path).map(String::as_str))
            .collect();
        gates.push(own);
        gates.dedup();
        match gates.as_slice() {
            [one] => Some((*one).to_owned()),
            many => Some(format!("all({})", many.join(", "))),
        }
    }

    /// The `cfg` a file placed in `module` compiles under, from the
    /// declarations of it and of every module above it, or `None` when
    /// nothing gates it.
    #[must_use]
    pub fn gate_of(&self, module: &str) -> Option<String> {
        let mut gates: Vec<&str> = modules_above(module)
            .into_iter()
            .chain(std::iter::once(module))
            .filter_map(|path| self.gated.get(path).map(String::as_str))
            .collect();
        gates.dedup();
        match gates.as_slice() {
            [] => None,
            [one] => Some((*one).to_owned()),
            many => Some(format!("all({})", many.join(", "))),
        }
    }
}

/// Record that `module` compiles under `predicate`, or under either one when
/// an earlier declaration gated it otherwise.
fn merge_gate(gated: &mut BTreeMap<String, String>, module: &str, predicate: &str) {
    match gated.get(module) {
        Some(known) if known != predicate => {
            let merged = format!("any({known}, {predicate})");
            gated.insert(module.to_owned(), merged);
        }
        Some(_) => {}
        None => {
            gated.insert(module.to_owned(), predicate.to_owned());
        }
    }
}

/// Whether `module`, or any module above it, was declared under a
/// test-support `cfg` (see [`ModuleGates::support`]).
#[must_use]
pub fn is_test_support_module_path(module: &str, gates: &ModuleGates) -> bool {
    std::iter::once(module)
        .chain(modules_above(module))
        .any(|path| gates.support.contains(path))
}

/// Whether `module`, or any module above it, was declared `#[cfg(test)]`.
#[must_use]
pub fn is_test_module_path(module: &str, gates: &ModuleGates) -> bool {
    if gates.test.contains(module) {
        return true;
    }
    let mut prefix = module;
    while let Some(cut) = prefix.rfind("::") {
        prefix = &prefix[..cut];
        if gates.test.contains(prefix) {
            return true;
        }
    }

    false
}

/// Record every out-of-line `#[cfg(..)] mod name;` in one file's items, by
/// the module its file is placed in (see [`mod_child`]): test-only ones in
/// [`ModuleGates::test`], any other `cfg` in [`ModuleGates::gated`].
///
/// Bodiless modules only: one with a body is walked in place, where
/// `Walk::walk_mod` opens the scope directly. A declaration counts only when
/// `relative` is a file every file of that module already reads: one of the
/// paths a module above it could have (`src/net.rs` for `crate::net::tests`,
/// a binary root's `src/main.rs` for `crate::checks`). A `#[path]` that sends a test
/// module elsewhere (`src/net.rs` loading `src/net_tests.rs`) is not marked,
/// since adding or removing the attribute could not reach that file.
pub fn collect_test_modules(
    relative: &str,
    module: &str,
    items: &[Item],
    layout: &Layout,
    out: &mut ModuleGates,
) {
    collect_test_modules_in(relative, module, module, items, layout, out);
}

/// [`collect_test_modules`] for the items of `container`, `module` itself or
/// an inline module in it.
fn collect_test_modules_in(
    relative: &str,
    module: &str,
    container: &str,
    items: &[Item],
    layout: &Layout,
    out: &mut ModuleGates,
) {
    for item in items {
        let Item::Mod(node) = item else {
            continue;
        };
        let (canonical, file) = mod_child(relative, module, container, node, layout);
        if let Some((_, inner)) = &node.content {
            collect_test_modules_in(relative, module, &canonical, inner, layout, out);
            continue;
        }
        let test = is_cfg_test(&node.attrs);
        let support = is_cfg_test_support(&node.attrs);
        let gate = cfg_predicate(&node.attrs);
        if !test && gate.is_none() {
            continue;
        }
        let read_by_it = modules_above(&canonical).iter().any(|parent| {
            layout
                .module_files(parent)
                .iter()
                .any(|file| file == relative)
        });
        if !read_by_it {
            continue;
        }
        if support {
            out.support.insert(canonical.clone());
        }
        if test {
            out.test.insert(canonical);
        } else if let Some(gate) = gate {
            merge_gate(&mut out.gated, &canonical, &gate);
            if let Some(file) = file {
                merge_gate(&mut out.files, &file, &gate);
            }
        }
    }
}

/// The `cfg` an item's attributes compile it under, other than one that
/// holds only under `test` (see [`is_cfg_test`]), as its predicate is
/// written: `target_os = "macos"`, or `all(..)` of several. `None` for an
/// item no such `cfg` gates.
pub(super) fn cfg_predicate(attrs: &[syn::Attribute]) -> Option<String> {
    if is_cfg_test(attrs) {
        return None;
    }
    let predicates: Vec<String> = attrs
        .iter()
        .filter(|attr| attr.path().is_ident("cfg"))
        .filter_map(|attr| attr.meta.require_list().ok())
        .map(|list| list.tokens.to_string())
        .filter(|predicate| !predicate.is_empty())
        .collect();
    match predicates.as_slice() {
        [] => None,
        [one] => Some(one.clone()),
        many => Some(format!("all({})", many.join(", "))),
    }
}

/// The outer attributes of an item, or none for an item kind the walk never
/// declares anything for.
pub(super) fn item_attrs(item: &Item) -> &[syn::Attribute] {
    match item {
        Item::Const(node) => &node.attrs,
        Item::Enum(node) => &node.attrs,
        Item::Fn(node) => &node.attrs,
        Item::Impl(node) => &node.attrs,
        Item::Mod(node) => &node.attrs,
        Item::Static(node) => &node.attrs,
        Item::Struct(node) => &node.attrs,
        Item::Trait(node) => &node.attrs,
        Item::Union(node) => &node.attrs,
        Item::Use(node) => &node.attrs,
        _ => &[],
    }
}

/// Whether an attribute list compiles its item only under `test`.
///
/// The `cfg(..)` predicate is evaluated structurally (see [`requires_test`]):
/// `cfg(test)` and `cfg(all(test, feature = "x"))` hold only in a test build,
/// while `cfg(not(test))` is production code and `cfg(any(test, feature =
/// "x"))` also compiles outside tests. A false positive here silently removes
/// production code from the dead-code and hub budgets; a false negative puts
/// test code back in them.
pub(super) fn is_cfg_test(attrs: &[syn::Attribute]) -> bool {
    attrs.iter().any(|attr| {
        attr.path().is_ident("cfg")
            && attr
                .meta
                .require_list()
                .ok()
                .and_then(|list| list.parse_args::<syn::Meta>().ok())
                .is_some_and(|predicate| requires_test(&predicate))
    })
}

/// Whether a `cfg` predicate can hold only when `test` is set (see
/// [`test_gate`]).
fn requires_test(predicate: &syn::Meta) -> bool {
    test_gate(predicate).0
}

/// Whether an attribute list compiles its item only for the tests, though
/// not only under `test`: in a build with a test-support feature on
/// (`cfg(feature = "test-support")`, `cfg(any(test, feature =
/// "native-catalog-test-support"))`). A crate exports such code for its own
/// and its dependents' tests, which are its only callers.
pub(super) fn is_cfg_test_support(attrs: &[syn::Attribute]) -> bool {
    !is_cfg_test(attrs)
        && attrs.iter().any(|attr| {
            attr.path().is_ident("cfg")
                && attr
                    .meta
                    .require_list()
                    .ok()
                    .and_then(|list| list.parse_args::<syn::Meta>().ok())
                    .is_some_and(|predicate| gate_with(&predicate, &is_test_or_support).0)
        })
}

/// Whether a `cfg` leaf holds only in a test build or with a test-support
/// feature: `test`, or a feature whose name says it supports tests.
fn is_test_or_support(predicate: &syn::Meta) -> bool {
    match predicate {
        syn::Meta::Path(path) => path.is_ident("test"),
        syn::Meta::NameValue(pair) if pair.path.is_ident("feature") => match &pair.value {
            syn::Expr::Lit(syn::ExprLit {
                lit: syn::Lit::Str(name),
                ..
            }) => {
                let name = name.value();
                name.contains("test-support") || name.contains("test_support")
            }
            _ => false,
        },
        _ => false,
    }
}

/// What a `cfg` predicate says about tests, as two answers: whether it can
/// hold only when `test` is set, and whether it holds in every build without
/// `test`.
///
/// `test` holds only under `test`. `all(..)` holds only under `test` when
/// any of its parts does, and outside tests when every part does; `any(..)`
/// holds only under `test` when every one of its (at least one) parts does,
/// and outside tests when any part does. `not(p)` swaps the two answers:
/// `not(test)` holds in every build without `test`, `not(not(test))` only
/// under it. Anything else, `unix` or `feature = "test"`, says neither.
fn test_gate(predicate: &syn::Meta) -> (bool, bool) {
    gate_with(
        predicate,
        &|leaf| matches!(leaf, syn::Meta::Path(path) if path.is_ident("test")),
    )
}

/// [`test_gate`] with `leaf` deciding which leaf predicates hold only in
/// the builds asked about.
fn gate_with(predicate: &syn::Meta, leaf: &dyn Fn(&syn::Meta) -> bool) -> (bool, bool) {
    let syn::Meta::List(list) = predicate else {
        return (leaf(predicate), false);
    };
    let parts: Vec<(bool, bool)> = cfg_parts(list)
        .iter()
        .map(|part| gate_with(part, leaf))
        .collect();
    if list.path.is_ident("all") {
        (
            parts.iter().any(|part| part.0),
            parts.iter().all(|part| part.1),
        )
    } else if list.path.is_ident("any") {
        (
            !parts.is_empty() && parts.iter().all(|part| part.0),
            parts.iter().any(|part| part.1),
        )
    } else if let ([(requires, outside)], true) = (parts.as_slice(), list.path.is_ident("not")) {
        (*outside, *requires)
    } else {
        (false, false)
    }
}

/// The comma-separated predicates inside `all(..)`, `any(..)` or `not(..)`,
/// or none when the list does not parse as predicates.
fn cfg_parts(list: &syn::MetaList) -> Vec<syn::Meta> {
    list.parse_args_with(syn::punctuated::Punctuated::<syn::Meta, syn::Token![,]>::parse_terminated)
        .map(|parts| parts.into_iter().collect())
        .unwrap_or_default()
}

/// Whether an attribute list marks a test function.
///
/// Covers the bare `#[test]` and the framework spellings that wrap it
/// (`#[tokio::test]`, `#[async_std::test]`), which all end in a `test` segment.
pub(super) fn is_test_attribute(attrs: &[syn::Attribute]) -> bool {
    attrs.iter().any(|attr| {
        attr.path()
            .segments
            .last()
            .is_some_and(|segment| segment.ident == "test")
    })
}

/// The first string-literal argument of an attribute, `"/path"` of
/// `#[get("/path", rank = 1)]`, or `None` when the list does not open with
/// one (a variable, a macro — anything the worker cannot prove static).
pub(super) fn attr_path(attr: &syn::Attribute) -> Option<String> {
    for arg in attr_args(attr)? {
        if let syn::Expr::Lit(lit) = arg {
            if let syn::Lit::Str(text) = lit.lit {
                return Some(text.value());
            }
        } else {
            break;
        }
    }

    None
}

/// The `method` value of actix's `#[route("/path", method = "PUT")]`.
pub(super) fn attr_method(attr: &syn::Attribute) -> Option<String> {
    for arg in attr_args(attr)? {
        if let syn::Expr::Assign(assign) = arg {
            if let syn::Expr::Path(left) = &*assign.left {
                if left.path.is_ident("method") {
                    if let syn::Expr::Lit(lit) = &*assign.right {
                        if let syn::Lit::Str(string) = &lit.lit {
                            return Some(string.value().to_uppercase());
                        }
                    }
                }
            }
        }
    }

    None
}

/// The parsed argument list of a list attribute, or `None` for an empty,
/// path-only, or unparsable attribute (`#[get]`, `#[inline]`).
fn attr_args(
    attr: &syn::Attribute,
) -> Option<syn::punctuated::Punctuated<syn::Expr, syn::Token![,]>> {
    let syn::Meta::List(list) = &attr.meta else {
        return None;
    };
    list.parse_args_with(syn::punctuated::Punctuated::<syn::Expr, syn::Token![,]>::parse_terminated)
        .ok()
}

#[cfg(test)]
mod tests {
    /// `not(test)` and `any(test, ..)` hold in a production build too, so
    /// only a predicate no production build meets marks test code.
    #[test]
    fn a_cfg_predicate_requires_test_only_where_every_build_that_meets_it_is_a_test() {
        let requires = |predicate: &str| {
            super::requires_test(&syn::parse_str::<syn::Meta>(predicate).expect("parses"))
        };
        for test_only in [
            "test",
            "all(test, feature = \"x\")",
            "any(test, all(test, unix))",
            "not(not(test))",
            "not(any(not(test), unix))",
            "all(any(test, all(test, unix)), not(any(not(test), windows)))",
            "not(all(not(test), all()))",
        ] {
            assert!(requires(test_only), "{test_only}");
        }
        for production in [
            "not(test)",
            "any(test, feature = \"x\")",
            "all(not(test), unix)",
            "not(all(not(test), unix))",
            "any(not(not(test)), unix)",
            "not(test, unix)",
            "any()",
            "feature = \"test\"",
            "unix",
        ] {
            assert!(!requires(production), "{production}");
        }
    }
}
