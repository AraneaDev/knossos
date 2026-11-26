//! The mock types mockall generates, by the name each generator gives its
//! mock (Decision 3): `#[automock]` on a trait `T` makes `MockT`, on an
//! `impl S` or `impl T for S` makes `MockS`, on a `mod m` makes `mock_m`;
//! `mock! { S … }` makes `MockS`. The name sits in the generator's module.
//!
//! Only the generator is read, never expanded: what the mock holds is
//! mockall's, and the compiler checks every use of it (Decision 1). These
//! names never enter the declaration index's name index, so nothing the
//! walk resolves changes because a file generates a mock.

use std::collections::BTreeMap;

use proc_macro2::{Delimiter, TokenTree};
use syn::Item;

use super::cfg::{is_cfg_test, requires_test};
use super::declaration_paths;
use super::state::Walk;
use super::Declarations;
use crate::facts::Facts;
use crate::layout::Layout;
use crate::resolve::ident_name;

/// The pattern of a mock `#[automock]` generates.
pub const AUTOMOCK: &str = "mockall::automock";

/// The pattern of a mock `mock!` generates.
pub const MOCK_MACRO: &str = "mockall::mock";

/// One mock type a generator makes.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct GeneratedMock {
    /// The canonical path of what the mock stands in for, when it is known:
    /// the trait, or the `impl` block's self type.
    pub target: Option<String>,
    /// The node kind of `target`: `interface` for a trait, `class` for a
    /// type.
    pub target_kind: &'static str,
    /// [`AUTOMOCK`] or [`MOCK_MACRO`].
    pub pattern: &'static str,
    /// Whether a `cfg` that requires `test` gates the generator (its own,
    /// a `cfg_attr(test, automock)`, or an enclosing inline module's): the
    /// mock then exists only in its own crate's test build.
    pub test_gated: bool,
    /// Whether the mock is an `#[automock] mod`'s, whose functions give
    /// static contexts only.
    pub contexts_only: bool,
    /// The associated functions the mocked type declares among `new` and
    /// `default`: mockall mocks such a function as a static method, so
    /// `MockS::new()` returns whatever its context's expectation does and
    /// makes no fresh mock.
    pub mocked_constructors: Vec<String>,
    /// The project-relative path of the generating file, whose crate a
    /// test gate keeps the mock to.
    pub file: String,
    /// The canonical path of each trait the mock implements, `None` for
    /// one the generating file's own imports cannot place: none for a
    /// mock of inherent methods alone.
    pub traits: Vec<Option<String>>,
}

/// The mocks the items of the file `relative`, placed in `module`,
/// generate, by canonical path, each target resolved through the file's own
/// imports alone: no lookup reaches the scan's index, so asking changes no
/// file's reads.
#[must_use]
pub fn generated_mocks(
    relative: &str,
    module: &str,
    items: &[Item],
    layout: &Layout,
) -> BTreeMap<String, GeneratedMock> {
    let mut facts = Facts::new(relative);
    let declarations = Declarations::new();
    let mut walker = Walk::new(&mut facts, module, &[], &declarations, layout);
    walker.own_declarations = declaration_paths(module, items);
    walker.collect_uses(module, items);
    walker.resolve_globs();
    let mut out = BTreeMap::new();
    walker.collect_generated_mocks(module, items, false, &mut out);
    out
}

impl Walk<'_> {
    /// Add the mocks `items`, in `container`, generate to `out`; `gated`
    /// when a `cfg` requiring `test` gates an enclosing module.
    pub(super) fn collect_generated_mocks(
        &self,
        container: &str,
        items: &[Item],
        gated: bool,
        out: &mut BTreeMap<String, GeneratedMock>,
    ) {
        for item in items {
            match item {
                Item::Trait(node) => {
                    if let Some(test_gated) = automock(&node.attrs) {
                        let name = ident_name(&node.ident);
                        let mock = GeneratedMock {
                            target: Some(format!("{container}::{name}")),
                            target_kind: "interface",
                            pattern: AUTOMOCK,
                            test_gated: gated || test_gated || is_cfg_test(&node.attrs),
                            contexts_only: false,
                            mocked_constructors: constructors(node.items.iter().filter_map(
                                |item| match item {
                                    syn::TraitItem::Fn(function) => Some(&function.sig.ident),
                                    _ => None,
                                },
                            )),
                            file: self.relative.clone(),
                            traits: vec![Some(format!("{container}::{name}"))],
                        };
                        out.insert(format!("{container}::Mock{name}"), mock);
                    }
                }
                Item::Impl(node) => {
                    let Some(test_gated) = automock(&node.attrs) else {
                        continue;
                    };
                    let syn::Type::Path(self_type) = node.self_ty.as_ref() else {
                        continue;
                    };
                    let Some(last) = self_type.path.segments.last() else {
                        continue;
                    };
                    let mock = GeneratedMock {
                        target: self.path_target(container, &self_type.path),
                        target_kind: "class",
                        pattern: AUTOMOCK,
                        test_gated: gated || test_gated || is_cfg_test(&node.attrs),
                        contexts_only: false,
                        mocked_constructors: constructors(node.items.iter().filter_map(|item| {
                            match item {
                                syn::ImplItem::Fn(function) => Some(&function.sig.ident),
                                _ => None,
                            }
                        })),
                        file: self.relative.clone(),
                        traits: node
                            .trait_
                            .iter()
                            .map(|(path, _)| self.path_target(container, path))
                            .collect(),
                    };
                    out.insert(
                        format!("{container}::Mock{}", ident_name(&last.ident)),
                        mock,
                    );
                }
                Item::Mod(node) => {
                    let name = ident_name(&node.ident);
                    let inner_gated = gated || is_cfg_test(&node.attrs);
                    if let Some(test_gated) = automock(&node.attrs) {
                        let mock = GeneratedMock {
                            target: None,
                            target_kind: "class",
                            pattern: AUTOMOCK,
                            test_gated: inner_gated || test_gated,
                            contexts_only: true,
                            mocked_constructors: Vec::new(),
                            file: self.relative.clone(),
                            traits: Vec::new(),
                        };
                        out.insert(format!("{container}::mock_{name}"), mock);
                    }
                    if let Some((_, inner)) = &node.content {
                        let nested = format!("{container}::{name}");
                        self.collect_generated_mocks(&nested, inner, inner_gated, out);
                    }
                }
                Item::Macro(node) if is_mock_macro(&node.mac.path) => {
                    let Some(shape) = MockMacro::of(node.mac.tokens.clone()) else {
                        continue;
                    };
                    let target = match shape.traits.as_slice() {
                        [only] => self.path_target(container, only),
                        _ => None,
                    };
                    let mock = GeneratedMock {
                        target_kind: if target.is_some() {
                            "interface"
                        } else {
                            "class"
                        },
                        target,
                        pattern: MOCK_MACRO,
                        test_gated: gated || is_cfg_test(&node.attrs),
                        contexts_only: false,
                        mocked_constructors: shape.constructors,
                        file: self.relative.clone(),
                        traits: shape
                            .traits
                            .iter()
                            .map(|path| self.path_target(container, path))
                            .collect(),
                    };
                    out.insert(format!("{container}::Mock{}", shape.name), mock);
                }
                _ => {}
            }
        }
    }
}

/// Which of `new` and `default` are among the associated functions
/// `names`, in that order.
fn constructors<'i>(names: impl Iterator<Item = &'i syn::Ident>) -> Vec<String> {
    let names: Vec<String> = names.map(ident_name).collect();
    known_constructors(&names)
}

/// Which of `new` and `default` are among `names`, in that order.
fn known_constructors(names: &[String]) -> Vec<String> {
    ["new", "default"]
        .into_iter()
        .filter(|name| names.iter().any(|declared| declared == name))
        .map(str::to_owned)
        .collect()
}

/// Every name a `fn` declares at any depth of `tokens` (a `mock!` body).
fn declared_fns(tokens: proc_macro2::TokenStream, names: &mut Vec<String>) {
    let mut after_fn = false;
    for tree in tokens {
        let is_fn = matches!(&tree, TokenTree::Ident(ident) if ident == "fn");
        match tree {
            TokenTree::Group(group) => declared_fns(group.stream(), names),
            TokenTree::Ident(ident) if after_fn => names.push(ident_name(&ident)),
            _ => {}
        }
        after_fn = is_fn;
    }
}

/// Whether `attrs` carry `#[automock]` (or `#[mockall::automock]`), and if
/// so whether only a test build applies it: `Some(true)` for a
/// `cfg_attr(PREDICATE, automock)` whose predicate requires `test`.
fn automock(attrs: &[syn::Attribute]) -> Option<bool> {
    let mut found = None;
    for attr in attrs {
        if is_automock(attr.path()) {
            return Some(false);
        }
        if !attr.path().is_ident("cfg_attr") {
            continue;
        }
        let Ok(arguments) = attr.parse_args_with(
            syn::punctuated::Punctuated::<syn::Meta, syn::Token![,]>::parse_terminated,
        ) else {
            continue;
        };
        let mut arguments = arguments.into_iter();
        let Some(predicate) = arguments.next() else {
            continue;
        };
        if arguments
            .next_back()
            .is_some_and(|last| is_automock(last.path()))
        {
            found = Some(requires_test(&predicate));
        }
    }
    found
}

/// Whether a path names mockall's `automock` attribute.
fn is_automock(path: &syn::Path) -> bool {
    let names: Vec<String> = path
        .segments
        .iter()
        .map(|segment| ident_name(&segment.ident))
        .collect();
    matches!(
        names
            .iter()
            .map(String::as_str)
            .collect::<Vec<_>>()
            .as_slice(),
        ["automock"] | ["mockall", "automock"]
    )
}

/// Whether a path names mockall's `mock!` macro.
fn is_mock_macro(path: &syn::Path) -> bool {
    let names: Vec<String> = path
        .segments
        .iter()
        .map(|segment| ident_name(&segment.ident))
        .collect();
    matches!(
        names
            .iter()
            .map(String::as_str)
            .collect::<Vec<_>>()
            .as_slice(),
        ["mock"] | ["mockall", "mock"]
    )
}

/// What a `mock! { … }` invocation says: the struct it names, and the trait
/// of each `impl Trait for Struct` section.
struct MockMacro {
    /// The mocked struct's name; the mock is `Mock<name>`.
    name: String,
    /// The trait of each `impl … for` section, as written.
    traits: Vec<syn::Path>,
    /// Which of `new` and `default` the body declares (see
    /// [`GeneratedMock::mocked_constructors`]).
    constructors: Vec<String>,
}

impl MockMacro {
    /// Read `mock!`'s tokens: attributes, a visibility, the struct's name,
    /// its generics and body, then any `impl` sections. `None` when no name
    /// comes first.
    fn of(tokens: proc_macro2::TokenStream) -> Option<Self> {
        let mut fns = Vec::new();
        declared_fns(tokens.clone(), &mut fns);
        let trees: Vec<TokenTree> = tokens.into_iter().collect();
        let mut at = 0;
        let mut name = None;
        while at < trees.len() {
            match &trees[at] {
                TokenTree::Punct(punct) if punct.as_char() == '#' => at += 2,
                TokenTree::Ident(ident) if ident == "pub" => {
                    at += 1;
                    if matches!(trees.get(at), Some(TokenTree::Group(group)) if group.delimiter() == Delimiter::Parenthesis)
                    {
                        at += 1;
                    }
                }
                TokenTree::Ident(ident) => {
                    name = Some(ident_name(ident));
                    at += 1;
                    break;
                }
                _ => return None,
            }
        }
        let name = name?;
        let mut traits = Vec::new();
        while at < trees.len() {
            if matches!(&trees[at], TokenTree::Ident(ident) if ident == "impl") {
                at += 1;
                at = skip_generics(&trees, at);
                let start = at;
                while at < trees.len()
                    && !matches!(&trees[at], TokenTree::Ident(ident) if ident == "for")
                {
                    at += 1;
                }
                if at < trees.len() {
                    let written: proc_macro2::TokenStream =
                        trees[start..at].iter().cloned().collect();
                    if let Ok(path) = syn::parse2::<syn::Path>(written) {
                        traits.push(path);
                    }
                }
            }
            at += 1;
        }
        Some(Self {
            name,
            traits,
            constructors: known_constructors(&fns),
        })
    }
}

/// The position past a generic parameter list (`<T: A<B>>`) opening at
/// `at`, or `at` itself when none opens there.
fn skip_generics(trees: &[TokenTree], mut at: usize) -> usize {
    if !matches!(trees.get(at), Some(TokenTree::Punct(punct)) if punct.as_char() == '<') {
        return at;
    }
    let mut depth = 0_usize;
    while let Some(tree) = trees.get(at) {
        if let TokenTree::Punct(punct) = tree {
            // The `>` of `->` (`F: Fn() -> u32`) closes nothing.
            let arrow = at > 0
                && matches!(&trees[at - 1], TokenTree::Punct(before) if before.as_char() == '-');
            match punct.as_char() {
                '<' => depth += 1,
                '>' if !arrow => {
                    depth = depth.saturating_sub(1);
                    if depth == 0 {
                        return at + 1;
                    }
                }
                _ => {}
            }
        }
        at += 1;
    }
    at
}
