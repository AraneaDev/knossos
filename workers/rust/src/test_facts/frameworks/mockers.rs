//! mockers (Momus's `scanMockers` and `collectMockers`):
//! `let (mock, handle) = scenario.create_mock_for::<dyn A>();` makes a
//! mock and the handle that configures it, and
//! `scenario.expect(handle.m(…).and_return(v))` configures the member `m`.
//! `create_mock::<AMock>()` names the mock type `#[mocked] trait A` or a
//! mockers `mock! { AMock, self, trait A { … } }` generates.
//!
//! The configuration goes through the handle and the scenario, which a
//! helper may be handed, so no value a call on the mock returns is ever
//! told (Decision 9); `and_return` still pins.

use std::collections::{BTreeMap, BTreeSet};

use proc_macro2::TokenTree;

use super::{Framework, FrameworkContext, Generated, Generator, Made, Verb, Verbs};
use crate::resolve::ident_name;

/// The pattern of a mockers mock (Decision 2).
const PATTERN: &str = "mockers::Scenario::create_mock";

/// The scenario's methods that make a mock and its handle.
const CREATE: [&str; 4] = [
    "create_mock",
    "create_mock_for",
    "create_named_mock",
    "create_named_mock_for",
];

/// mockers' verbs: `and_return` pins.
pub(in crate::test_facts) const VERBS: Verbs = Verbs {
    values: &[
        Verb {
            name: "and_return",
            pins: true,
            once: false,
        },
        Verb {
            name: "and_return_default",
            pins: false,
            once: false,
        },
        Verb {
            name: "and_return_clone",
            pins: false,
            once: false,
        },
        Verb {
            name: "and_call",
            pins: false,
            once: false,
        },
        Verb {
            name: "and_panic",
            pins: false,
            once: false,
        },
    ],
    times: &[],
    answers: false,
};

/// Record the mock types a `#[mocked]` trait or a mockers `mock!` names.
pub(super) fn generators(
    context: &FrameworkContext<'_>,
    container: &str,
    item: &syn::Item,
    out: &mut BTreeMap<String, Generator>,
) {
    match item {
        syn::Item::Trait(node) => {
            let Some(attr) = node.attrs.iter().find(|attr| {
                context.full_path(container, attr.path()).as_deref()
                    == Some("mockers_derive::mocked")
            }) else {
                return;
            };
            let name = ident_name(&node.ident);
            let custom = attr
                .parse_args::<syn::Ident>()
                .ok()
                .map(|ident| ident_name(&ident));
            let mock = custom.unwrap_or_else(|| format!("{name}Mock"));
            for generated in [mock.clone(), format!("{mock}Static")] {
                out.insert(
                    format!("{container}::{generated}"),
                    mock_type(Some(("interface", format!("{container}::{name}")))),
                );
            }
        }
        syn::Item::Macro(node)
            if node
                .mac
                .path
                .segments
                .last()
                .is_some_and(|last| last.ident == "mock") =>
        {
            let Some((name, traits)) = mock_block(node.mac.tokens.clone()) else {
                return;
            };
            let target = match traits.as_slice() {
                [only] => context.declared_trait(container, &syn::Path::from(only.clone())),
                _ => None,
            };
            out.insert(format!("{container}::{name}"), mock_type(target));
        }
        _ => {}
    }
}

/// A mockers mock type standing in for `target`.
fn mock_type(target: Option<(&'static str, String)>) -> Generator {
    Generator {
        framework: Framework::Mockers,
        target,
        functions: BTreeSet::new(),
        kind: Generated::Mock,
    }
}

/// The name and traits of a mockers `mock! { Name, self, trait A { … } }`;
/// `None` for any other shape (mockall's `mock! { Name { … } }`).
fn mock_block(tokens: proc_macro2::TokenStream) -> Option<(String, Vec<syn::Ident>)> {
    let trees: Vec<TokenTree> = tokens.into_iter().collect();
    let (Some(TokenTree::Ident(name)), Some(TokenTree::Punct(comma))) =
        (trees.first(), trees.get(1))
    else {
        return None;
    };
    if comma.as_char() != ',' {
        return None;
    }
    let traits = trees
        .windows(2)
        .filter_map(|pair| match pair {
            [TokenTree::Ident(keyword), TokenTree::Ident(name)] if keyword == "trait" => {
                Some(name.clone())
            }
            _ => None,
        })
        .collect();
    Some((ident_name(name), traits))
}

/// The mock `scenario.create_mock_for::<dyn A>()` (or another `create_*`)
/// makes, written in `container`.
pub(in crate::test_facts) fn constructed(
    context: &FrameworkContext<'_>,
    container: &str,
    call: &syn::ExprMethodCall,
) -> Option<Made> {
    let method = ident_name(&call.method);
    if !CREATE.contains(&method.as_str()) {
        return None;
    }
    let turbofish = call.turbofish.as_ref()?;
    let [syn::GenericArgument::Type(ty)] = turbofish.args.iter().collect::<Vec<_>>().as_slice()
    else {
        return None;
    };
    let (path, target) = match ty {
        syn::Type::TraitObject(object) => {
            let path = object.bounds.iter().find_map(|bound| match bound {
                syn::TypeParamBound::Trait(bound) => Some(bound.path.clone()),
                _ => None,
            })?;
            let target = context.declared_trait(container, &path);
            (path, target)
        }
        syn::Type::Path(ty) if ty.qself.is_none() => {
            let target = context
                .generator(container, &ty.path)
                .filter(|(_, generator)| generator.framework == Framework::Mockers)
                .and_then(|(_, generator)| generator.target.clone());
            (ty.path.clone(), target)
        }
        _ => return None,
    };
    Some(Made::instance(
        Framework::Mockers,
        super::written(&path),
        PATTERN,
        target,
    ))
}
