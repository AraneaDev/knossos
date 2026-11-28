//! mockiato (Momus's `scanMockiato`): `#[mockable] trait Greeter`, with
//! `mockable` imported from mockiato, generates `GreeterMock` (a suffix, not
//! mockall's prefix); `GreeterMock::new()` makes a mock, and
//! `g.expect_greet(…).returns(v)` (or `expect_greet_calls_in_order`)
//! configures the member `greet`, as mockall's `expect_*` does. `returns`
//! and `returns_once` pin; `times` asserts how often it is called.
//!
//! Only a generator the file holds makes a double: a `FooMock` by name alone
//! may be a hand-written type.

use std::collections::BTreeMap;

use super::{Framework, FrameworkContext, Generated, Generator, Made, Role, Verb, Verbs};
use crate::resolve::ident_name;

/// The pattern of a mockiato mock (Decision 2).
const PATTERN: &str = "mockiato::mockable";

/// mockiato's verbs: `returns` and `returns_once` pin.
pub(in crate::test_facts) const VERBS: Verbs = Verbs {
    values: &[
        Verb {
            name: "returns",
            pins: true,
            once: false,
        },
        Verb {
            name: "returns_once",
            pins: true,
            once: true,
        },
        Verb {
            name: "panics",
            pins: false,
            once: false,
        },
        Verb {
            name: "panics_with_message",
            pins: false,
            once: false,
        },
    ],
    times: &["times"],
    answers: true,
};

/// What `h.method(…)` is on a mockiato handle.
pub(super) fn role(method: &str) -> Role {
    let Some(member) = method.strip_prefix("expect_") else {
        return Role::Call;
    };
    let member = member.strip_suffix("_calls_in_order").unwrap_or(member);
    if member.is_empty() {
        return Role::Call;
    }
    Role::Member(member.to_owned(), "expect")
}

/// Record the mock type a mockiato `#[mockable]` trait generates.
pub(super) fn generators(
    context: &FrameworkContext<'_>,
    container: &str,
    item: &syn::Item,
    out: &mut BTreeMap<String, Generator>,
) {
    let syn::Item::Trait(node) = item else {
        return;
    };
    if !context.has_attribute(container, &node.attrs, "mockiato::mockable") {
        return;
    }
    let name = ident_name(&node.ident);
    out.insert(
        format!("{container}::{name}Mock"),
        Generator {
            framework: Framework::Mockiato,
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

/// The mock `GreeterMock::new()` or `GreeterMock::default()`, written in
/// `container`, makes.
pub(in crate::test_facts) fn constructed(
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
    let mut prefix = func.path.clone();
    let last = ident_name(&prefix.segments.pop()?.ident);
    if !matches!(last.as_str(), "new" | "default") {
        return None;
    }
    prefix.segments.pop_punct();
    let (mock, generator) = context.generator(container, &prefix)?;
    if generator.framework != Framework::Mockiato || generator.functions.contains(&last) {
        return None;
    }
    Some(Made::instance(
        Framework::Mockiato,
        mock,
        PATTERN,
        generator.target.clone(),
    ))
}
