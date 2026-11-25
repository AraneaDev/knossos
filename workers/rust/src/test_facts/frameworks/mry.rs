//! mry (Momus's `scanMry` and `collectMry`): `#[mry::mry]` on a struct and
//! its `impl` makes every value of the struct mockable, on a trait it
//! generates `MockT`, on a free function `mock_f`. `h.mock_m(…)` configures
//! the member `m` of a handle (`mry::new!(S { … })`, `S { … }`,
//! `S::default()`, `MockT::default()`), and `S::mock_m(…)` or `mock_f(…)`
//! a static mock, which every test shares and so gives identity only.
//!
//! `returns` pins. `returns_once`, `returns_with` and `calls_real_impl`
//! answer the call too, and leave the value unknown. A configuration only
//! answers calls its matchers match, and mry calls the real method for any
//! other, so a value counts only when every matcher is `mry::Any`.
//! `assert_called` asserts how often the member is called.

use std::collections::{BTreeMap, BTreeSet};

use proc_macro2::TokenTree;

use super::{Framework, FrameworkContext, Generated, Generator, Made, Role, Verb, Verbs};
use crate::resolve::ident_name;

/// The pattern of a mry mock (Decision 2).
const PATTERN: &str = "mry::mry";

/// mry's verbs: `returns` pins.
pub(in crate::test_facts) const VERBS: Verbs = Verbs {
    values: &[
        Verb {
            name: "returns",
            pins: true,
            once: false,
        },
        Verb {
            name: "returns_once",
            pins: false,
            once: true,
        },
        Verb {
            name: "returns_with",
            pins: false,
            once: false,
        },
        Verb {
            name: "calls_real_impl",
            pins: false,
            once: false,
        },
    ],
    times: &["assert_called"],
    answers: true,
};

/// What `h.method(…)` is on a mry handle.
pub(super) fn role(method: &str) -> Role {
    match method.strip_prefix("mock_") {
        Some(member) if !member.is_empty() => Role::Member(member.to_owned(), "mock"),
        _ => Role::Call,
    }
}

/// Record what a `#[mry::mry]` item, or a `mry::m!` block, generates.
pub(super) fn generators(
    context: &FrameworkContext<'_>,
    container: &str,
    item: &syn::Item,
    out: &mut BTreeMap<String, Generator>,
) {
    match item {
        syn::Item::Struct(node) if context.has_attribute(container, &node.attrs, "mry::mry") => {
            mockable(out, container, &ident_name(&node.ident));
        }
        syn::Item::Impl(node) if context.has_attribute(container, &node.attrs, "mry::mry") => {
            let syn::Type::Path(ty) = node.self_ty.as_ref() else {
                return;
            };
            let Some(last) = ty.path.segments.last() else {
                return;
            };
            let entry = mockable(out, container, &ident_name(&last.ident));
            entry
                .functions
                .extend(node.items.iter().filter_map(|member| match member {
                    syn::ImplItem::Fn(function) => Some(ident_name(&function.sig.ident)),
                    _ => None,
                }));
        }
        syn::Item::Trait(node) if context.has_attribute(container, &node.attrs, "mry::mry") => {
            let name = ident_name(&node.ident);
            out.insert(
                format!("{container}::Mock{name}"),
                Generator {
                    framework: Framework::Mry,
                    target: Some(("interface", format!("{container}::{name}"))),
                    functions: node
                        .items
                        .iter()
                        .filter_map(|member| match member {
                            syn::TraitItem::Fn(function) => Some(ident_name(&function.sig.ident)),
                            _ => None,
                        })
                        .collect(),
                    kind: Generated::Mock,
                },
            );
        }
        syn::Item::Fn(node) if context.has_attribute(container, &node.attrs, "mry::mry") => {
            out.insert(
                format!("{container}::{}", ident_name(&node.sig.ident)),
                Generator {
                    framework: Framework::Mry,
                    target: None,
                    functions: BTreeSet::new(),
                    kind: Generated::Function,
                },
            );
        }
        syn::Item::Macro(node)
            if context.full_path(container, &node.mac.path).as_deref() == Some("mry::m") =>
        {
            block(out, container, node.mac.tokens.clone());
        }
        _ => {}
    }
}

/// The mockable struct `name` of `container` in `out`, added when new.
fn mockable<'o>(
    out: &'o mut BTreeMap<String, Generator>,
    container: &str,
    name: &str,
) -> &'o mut Generator {
    let canonical = format!("{container}::{name}");
    out.entry(canonical.clone()).or_insert_with(|| Generator {
        framework: Framework::Mry,
        target: Some(("class", canonical)),
        functions: BTreeSet::new(),
        kind: Generated::Mockable,
    })
}

/// Record the structs and enums a `mry::m! { … }` block wraps, and the
/// functions of its `impl` blocks.
fn block(out: &mut BTreeMap<String, Generator>, container: &str, tokens: proc_macro2::TokenStream) {
    let trees: Vec<TokenTree> = tokens.into_iter().collect();
    for (at, tree) in trees.iter().enumerate() {
        let (TokenTree::Ident(keyword), Some(TokenTree::Ident(name))) = (tree, trees.get(at + 1))
        else {
            continue;
        };
        if keyword == "struct" || keyword == "enum" {
            mockable(out, container, &ident_name(name));
        } else if let (true, Some(TokenTree::Group(body))) = (keyword == "impl", trees.get(at + 2))
        {
            let mut names = Vec::new();
            fn_names(body.stream(), &mut names);
            mockable(out, container, &ident_name(name))
                .functions
                .extend(names);
        }
    }
}

/// The name after each `fn` in `tokens`.
fn fn_names(tokens: proc_macro2::TokenStream, out: &mut Vec<String>) {
    let mut after_fn = false;
    for tree in tokens {
        if let TokenTree::Ident(ident) = &tree {
            if after_fn {
                out.push(ident_name(ident));
            }
            after_fn = ident == "fn";
        } else {
            after_fn = false;
        }
    }
}

/// The handle a mry constructor makes, written in `container`:
/// `mry::new!(S { … })`, `S { … }` or `S::default()` of a mockable struct,
/// or `MockT::default()` of a mocked trait that declares no `default`.
pub(in crate::test_facts) fn constructed(
    context: &FrameworkContext<'_>,
    container: &str,
    expr: &syn::Expr,
) -> Option<Made> {
    let path = match expr {
        syn::Expr::Macro(mac)
            if context.full_path(container, &mac.mac.path).as_deref() == Some("mry::new") =>
        {
            syn::parse2::<syn::ExprStruct>(mac.mac.tokens.clone())
                .ok()?
                .path
        }
        syn::Expr::Struct(literal) => {
            let (_, generator) = context.generator(container, &literal.path)?;
            if generator.framework != Framework::Mry || generator.kind != Generated::Mockable {
                return None;
            }
            literal.path.clone()
        }
        syn::Expr::Call(call) if call.args.is_empty() => {
            let syn::Expr::Path(func) = call.func.as_ref() else {
                return None;
            };
            let mut prefix = func.path.clone();
            let last = prefix.segments.pop()?;
            if func.qself.is_some() || last.ident != "default" {
                return None;
            }
            prefix.segments.pop_punct();
            let (_, generator) = context.generator(container, &prefix)?;
            let instance = matches!(generator.kind, Generated::Mockable | Generated::Mock);
            if generator.framework != Framework::Mry
                || !instance
                || generator.functions.contains("default")
            {
                return None;
            }
            prefix
        }
        _ => return None,
    };
    let target = context
        .generator(container, &path)
        .filter(|(_, generator)| generator.framework == Framework::Mry)
        .and_then(|(_, generator)| generator.target.clone());
    Some(Made::instance(
        Framework::Mry,
        super::written(&path),
        PATTERN,
        target,
    ))
}

/// The static mock `S::mock_m(…)` or `mock_f(…)`, written in `container`,
/// configures.
pub(in crate::test_facts) fn static_mock(
    context: &FrameworkContext<'_>,
    container: &str,
    call: &syn::ExprCall,
) -> Option<Made> {
    let syn::Expr::Path(func) = call.func.as_ref() else {
        return None;
    };
    if func.qself.is_some() {
        return None;
    }
    let mut path = func.path.clone();
    let last = path.segments.pop()?;
    let member = ident_name(&last.ident).strip_prefix("mock_")?.to_owned();
    path.segments.pop_punct();
    let (owner, kind) = if path.segments.is_empty() {
        let mut function = func.path.clone();
        function.segments.last_mut()?.ident = syn::Ident::new(&member, last.ident.span());
        (function, Generated::Function)
    } else {
        (path, Generated::Statics)
    };
    let (_, generator) = context.generator(container, &owner)?;
    let matches = generator.framework == Framework::Mry
        && match kind {
            Generated::Function => generator.kind == Generated::Function,
            _ => generator.functions.contains(&member),
        };
    if !matches {
        return None;
    }
    let written = super::written(&owner);
    let invoked_by = if kind == Generated::Function {
        written.clone()
    } else {
        format!("{written}::{member}")
    };
    Some(Made {
        framework: Framework::Mry,
        mock: written,
        pattern: PATTERN,
        target: generator.target.clone(),
        target_kind: "class",
        member: Some((member, "mock")),
        invoked_by: Some(invoked_by),
    })
}

/// Whether every matcher of `h.mock_m(…)` is `mry::Any`, so the
/// configuration answers any call of `m`.
pub(in crate::test_facts) fn matches_any(
    context: &FrameworkContext<'_>,
    container: &str,
    call: &syn::ExprMethodCall,
) -> bool {
    call.args.iter().all(|argument| match argument {
        syn::Expr::Path(path) => {
            path.qself.is_none()
                && context.full_path(container, &path.path).as_deref() == Some("mry::Any")
        }
        _ => false,
    })
}
