//! faux (Momus's `scanFaux`): `#[faux::create] struct Foo` lets
//! `Foo::faux()` make a mock, and `faux::when!(mock.method).then_return(v)`
//! configures one member. `then`, `then_unchecked` and
//! `then_unchecked_return` answer the call too, with a closure or unchecked,
//! so they leave the value unknown.

use super::{Framework, FrameworkContext, Generated, Generator, Made, Verb, Verbs};
use crate::resolve::ident_name;

/// The pattern of a faux mock (Decision 2).
const PATTERN: &str = "faux::create";

/// faux's verbs: `then_return` pins.
pub(in crate::test_facts) const VERBS: Verbs = Verbs {
    values: &[
        Verb {
            name: "then_return",
            pins: true,
            once: false,
        },
        Verb {
            name: "then",
            pins: false,
            once: false,
        },
        Verb {
            name: "then_unchecked",
            pins: false,
            once: false,
        },
        Verb {
            name: "then_unchecked_return",
            pins: false,
            once: false,
        },
    ],
    times: &[],
    answers: true,
};

/// Record a `#[faux::create]` struct among `out`.
pub(super) fn generators(
    context: &FrameworkContext<'_>,
    container: &str,
    item: &syn::Item,
    out: &mut std::collections::BTreeMap<String, Generator>,
) {
    let syn::Item::Struct(node) = item else {
        return;
    };
    if !context.has_attribute(container, &node.attrs, "faux::create") {
        return;
    }
    let canonical = format!("{container}::{}", ident_name(&node.ident));
    out.insert(
        canonical.clone(),
        Generator {
            framework: Framework::Faux,
            target: Some(("class", canonical)),
            functions: std::collections::BTreeSet::new(),
            kind: Generated::Mockable,
        },
    );
}

/// The mock `Foo::faux()` makes, written in `container`: one whatever the
/// file holds, since no other API spells a constructor so, but standing in
/// for `Foo` only when the file creates it.
pub(in crate::test_facts) fn constructed(
    context: &FrameworkContext<'_>,
    container: &str,
    call: &syn::ExprCall,
) -> Option<Made> {
    let syn::Expr::Path(func) = call.func.as_ref() else {
        return None;
    };
    if func.qself.is_some() || !call.args.is_empty() {
        return None;
    }
    let mut prefix = func.path.clone();
    if prefix.segments.pop()?.ident != "faux" || prefix.segments.is_empty() {
        return None;
    }
    prefix.segments.pop_punct();
    let target = context
        .generator(container, &prefix)
        .filter(|(_, generator)| generator.framework == Framework::Faux)
        .and_then(|(_, generator)| generator.target.clone());
    Some(Made::instance(
        Framework::Faux,
        super::written(&prefix),
        PATTERN,
        target,
    ))
}

/// The handle and member `faux::when!(h.m)` or `faux::when!(h.m(…))`,
/// written in `container`, configures.
pub(in crate::test_facts) fn configured(
    context: &FrameworkContext<'_>,
    container: &str,
    mac: &syn::Macro,
) -> Option<(String, String)> {
    if context.full_path(container, &mac.path).as_deref() != Some("faux::when") {
        return None;
    }
    let (receiver, member) = match syn::parse2::<syn::Expr>(mac.tokens.clone()).ok()? {
        syn::Expr::Field(field) => match field.member {
            syn::Member::Named(name) => (*field.base, ident_name(&name)),
            syn::Member::Unnamed(_) => return None,
        },
        syn::Expr::MethodCall(call) => (*call.receiver, ident_name(&call.method)),
        _ => return None,
    };
    let syn::Expr::Path(path) = receiver else {
        return None;
    };
    let handle = super::super::handles::handle_name(&path)?;
    Some((handle, member))
}
