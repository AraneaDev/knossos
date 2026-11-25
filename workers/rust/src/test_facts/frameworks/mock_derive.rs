//! mock_derive (Momus's `scanMockDerive` and `collectMockDerive`): `#[mock]
//! trait T`, with `mock` imported from mock_derive, generates `MockT`;
//! `MockT::new()` makes a mock, `m.method_x()…set_result(v)` builds the
//! member `x`'s configuration and `m.set_x(…)` hands it over. `#[mock]
//! extern "C" { fn f(…); }` generates the static `ExternCMocks`, whose
//! `ExternCMocks::method_f()` configures `f` for every test.
//!
//! The configuration is a value of its own, built apart and handed over, and
//! it answers by call number (`first_call()`, `nth_call(n)`), so no value a
//! call returns is ever told (Decision 9); `set_result` and
//! `return_result_of` still pin.

use std::collections::BTreeMap;

use super::{Framework, FrameworkContext, Generated, Generator, Made, Role, Verb, Verbs};
use crate::resolve::ident_name;

/// The pattern of a mock_derive mock (Decision 2).
const PATTERN: &str = "mock_derive::mock";

/// mock_derive's verbs: `set_result` and `return_result_of` pin.
pub(in crate::test_facts) const VERBS: Verbs = Verbs {
    values: &[
        Verb {
            name: "set_result",
            pins: true,
            once: false,
        },
        Verb {
            name: "return_result_of",
            pins: true,
            once: false,
        },
    ],
    times: &[],
    answers: false,
};

/// What `h.method(…)` is on a mock_derive handle.
pub(super) fn role(method: &str) -> Role {
    if let Some(member) = method
        .strip_prefix("method_")
        .filter(|member| !member.is_empty())
    {
        return Role::Member(member.to_owned(), "method");
    }
    if method.starts_with("set_") {
        return Role::Install;
    }
    Role::Call
}

/// Record what a mock_derive `#[mock]` trait or extern block generates.
pub(super) fn generators(
    context: &FrameworkContext<'_>,
    container: &str,
    item: &syn::Item,
    out: &mut BTreeMap<String, Generator>,
) {
    match item {
        syn::Item::Trait(node)
            if context.has_attribute(container, &node.attrs, "mock_derive::mock") =>
        {
            let name = ident_name(&node.ident);
            out.insert(
                format!("{container}::Mock{name}"),
                Generator {
                    framework: Framework::MockDerive,
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
        syn::Item::ForeignMod(node)
            if context.has_attribute(container, &node.attrs, "mock_derive::mock") =>
        {
            let abi = node
                .abi
                .name
                .as_ref()
                .map_or_else(|| "C".to_owned(), syn::LitStr::value);
            out.insert(
                format!("{container}::Extern{abi}Mocks"),
                Generator {
                    framework: Framework::MockDerive,
                    target: None,
                    functions: node
                        .items
                        .iter()
                        .filter_map(|member| match member {
                            syn::ForeignItem::Fn(function) => Some(ident_name(&function.sig.ident)),
                            _ => None,
                        })
                        .collect(),
                    kind: Generated::Statics,
                },
            );
        }
        _ => {}
    }
}

/// The mock `MockT::new()` (or `MockT::<A>::new()`), written in
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
    if prefix.segments.pop()?.ident != "new" {
        return None;
    }
    prefix.segments.pop_punct();
    let (mock, generator) = context.generator(container, &prefix)?;
    if generator.framework != Framework::MockDerive
        || generator.kind != Generated::Mock
        || generator.functions.contains("new")
    {
        return None;
    }
    Some(Made::instance(
        Framework::MockDerive,
        mock,
        PATTERN,
        generator.target.clone(),
    ))
}

/// The static mock `ExternCMocks::method_f()`, written in `container`,
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
    let mut prefix = func.path.clone();
    let last = ident_name(&prefix.segments.pop()?.ident);
    let member = last.strip_prefix("method_")?.to_owned();
    prefix.segments.pop_punct();
    let (mock, generator) = context.generator(container, &prefix)?;
    if generator.framework != Framework::MockDerive
        || generator.kind != Generated::Statics
        || !generator.functions.contains(&member)
    {
        return None;
    }
    Some(Made {
        framework: Framework::MockDerive,
        mock,
        pattern: PATTERN,
        target: None,
        target_kind: "class",
        member: Some((member.clone(), "method")),
        invoked_by: Some(member),
    })
}
