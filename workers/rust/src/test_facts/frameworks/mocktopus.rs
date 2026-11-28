//! mocktopus (Momus's `scanMocktopus`): `f.mock_safe(|…| MockResult::Return(v))`
//! (or `mock_raw`) replaces the function `f` for the thread. The double is
//! the function's, a `global` target, made at the call; each
//! `MockResult::Return(…)` in the closure pins (R5-15). It replaces a
//! function, not a member of a handle, so no operand is ever `mock-config`:
//! it gives identity only.

use syn::spanned::Spanned;
use syn::visit::Visit;

use super::super::doubles::Pin;
use super::{Framework, Made};
use crate::resolve::ident_name;

/// The pattern of a mocktopus mock (Decision 2).
const PATTERN: &str = "mocktopus::mock_safe";

/// The double `f.mock_safe(…)` or `f.mock_raw(…)` makes, with its pins.
pub(in crate::test_facts) fn made(call: &syn::ExprMethodCall) -> Option<(Made, Vec<Pin>)> {
    if call.method != "mock_safe" && call.method != "mock_raw" {
        return None;
    }
    let syn::Expr::Path(receiver) = call.receiver.as_ref() else {
        return None;
    };
    if receiver.qself.is_some() {
        return None;
    }
    let written = super::written(&receiver.path);
    let member = ident_name(&receiver.path.segments.last()?.ident);
    let mut returns = Returns(Vec::new());
    for argument in &call.args {
        returns.visit_expr(argument);
    }
    let made = Made {
        framework: Framework::Mocktopus,
        mock: written.clone(),
        pattern: PATTERN,
        target: None,
        target_kind: "global",
        member: Some((member, "mock_fn")),
        invoked_by: Some(written),
    };
    Some((made, returns.0))
}

/// Each `MockResult::Return(…)` in a closure, as a pin.
struct Returns(Vec<Pin>);

impl Visit<'_> for Returns {
    fn visit_expr_call(&mut self, node: &syn::ExprCall) {
        if let syn::Expr::Path(func) = node.func.as_ref() {
            let names: Vec<String> = func
                .path
                .segments
                .iter()
                .map(|segment| ident_name(&segment.ident))
                .collect();
            if names.ends_with(&["MockResult".to_owned(), "Return".to_owned()]) {
                self.0.push(Pin {
                    api: "MockResult::Return".to_owned(),
                    line: func.span().start().line,
                    once: false,
                });
            }
        }
        syn::visit::visit_expr_call(self, node);
    }
}
