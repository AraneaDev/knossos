//! mockall's doubles (Decision 3), ported from Momus's `collect`,
//! `walkExpr`, `recordMockMacro` and `ufcsStaticKey` and Nemesis's Rust
//! doubles, every mock type resolved through the worker
//! ([`super::generated::MockResolver`]), never by its name alone. The
//! function pass ([`super::pass`]) asks this file what a call is to a mock
//! type and which verbs configure one.
//!
//! - One double per mock instance: a constructor call (`MockS::new()`,
//!   `MockS::default()`, `MockS::<T>::new()`, `<MockS>::new()`, or
//!   `let m: MockS = Default::default()`), and one per static context
//!   (`MockS::bar_context()`), which gives identity only.
//! - Each `h.expect_m()` chain on a handle configures the member `m`; each
//!   value verb of it is a pin, and `.times(…)`, `.once()` and `.never()`
//!   an assertion `times` on the double (Decision 7).
//! - `invocations` counts the calls on a handle other than its `expect_*`
//!   chains and `checkpoint()`, and each hand-on: the handle as an
//!   argument, borrowed, moved, captured or returned.

use super::frameworks::{Verb, Verbs};
use crate::resolve::ident_name;
use crate::visit::GeneratedMock;

/// mockall's verbs: every value verb pins, `return_once` once only.
pub(super) const VERBS: Verbs = Verbs {
    values: &[
        Verb {
            name: "return_const",
            pins: true,
            once: false,
        },
        Verb {
            name: "return_const_st",
            pins: true,
            once: false,
        },
        Verb {
            name: "returning",
            pins: true,
            once: false,
        },
        Verb {
            name: "returning_st",
            pins: true,
            once: false,
        },
        Verb {
            name: "return_once",
            pins: true,
            once: true,
        },
        Verb {
            name: "return_once_st",
            pins: true,
            once: true,
        },
    ],
    times: &["times", "once", "never"],
    answers: true,
};

/// What a path call is to a mock type.
pub(super) enum MockCall<'m> {
    /// `MockS::new()` and the like.
    Constructor(String, &'m GeneratedMock),
    /// `MockS::bar_context()`: the mock and the member.
    Context(String, &'m GeneratedMock, String),
    /// `MockS::bar(…)`: the mock and the member.
    Static(String, String),
}

/// What the call `func` is to a mock type, if anything, the mock a path
/// names given by `resolve`.
pub(super) fn classify<'m>(
    func: &syn::Expr,
    resolve: &dyn Fn(&syn::Path) -> Option<(String, &'m GeneratedMock)>,
) -> Option<MockCall<'m>> {
    let syn::Expr::Path(path) = func else {
        return None;
    };
    let last = ident_name(&path.path.segments.last()?.ident);
    if let Some(qself) = &path.qself {
        // `<MockS>::new()`, `<MockS as Default>::default()`.
        if !matches!(last.as_str(), "new" | "default") {
            return None;
        }
        let syn::Type::Path(ty) = qself.ty.as_ref() else {
            return None;
        };
        let (mock, generated) = resolve(&ty.path)?;
        // `<MockS as Default>::default()` is the trait's: a fresh mock.
        // Any other spelling of a `new`/`default` the mocked type
        // declares itself is its mocked static method.
        let through_default =
            qself.position > 0 && path.path.segments[qself.position - 1].ident == "Default";
        if generated.contexts_only
            || (generated.mocked_constructors.contains(&last) && !through_default)
        {
            return Some(MockCall::Static(mock, last));
        }
        return Some(MockCall::Constructor(mock, generated));
    }
    let mut prefix = path.path.clone();
    prefix.segments.pop();
    prefix.segments.pop_punct();
    if prefix.segments.is_empty() {
        return None;
    }
    let (mock, generated) = resolve(&prefix)?;
    if let Some(member) = last.strip_suffix("_context") {
        return Some(MockCall::Context(mock, generated, member.to_owned()));
    }
    match last.as_str() {
        // A `new` the mocked type declares itself is mocked as a static
        // method: it returns what its context's expectation does, never a
        // fresh mock.
        "new" | "default"
            if !generated.contexts_only && !generated.mocked_constructors.contains(&last) =>
        {
            Some(MockCall::Constructor(mock, generated))
        }
        _ => Some(MockCall::Static(mock, last)),
    }
}

/// The mock type a `let NAME: MockS = Default::default();` names.
pub(super) fn defaulted<'l>(local: &'l syn::Local, init: &syn::Expr) -> Option<&'l syn::Path> {
    let syn::Pat::Type(typed) = &local.pat else {
        return None;
    };
    let syn::Type::Path(ty) = typed.ty.as_ref() else {
        return None;
    };
    let syn::Expr::Call(call) = init else {
        return None;
    };
    let syn::Expr::Path(func) = call.func.as_ref() else {
        return None;
    };
    let written: Vec<String> = func
        .path
        .segments
        .iter()
        .map(|segment| ident_name(&segment.ident))
        .collect();
    if written != ["Default", "default"] || !call.args.is_empty() {
        return None;
    }
    Some(&ty.path)
}

/// The member a call `method` on a mockall handle configures, `context`
/// the member of the static context it holds, if any: `expect_m()`, or a
/// context's `expect()`.
pub(super) fn member(context: Option<&str>, method: &str) -> Option<String> {
    match (context, method.strip_prefix("expect_")) {
        (Some(member), None) if method == "expect" => Some(member.to_owned()),
        (None, Some(member)) => Some(member.to_owned()),
        _ => None,
    }
}
