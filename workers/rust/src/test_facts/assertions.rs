//! Which macro invocations are assertions, and what they compare
//! (Decision 7).
//!
//! The names are a frozen list: the worker never expands a macro, so a
//! project's own assertion macro is not one. A message (`assert_eq!(a, b,
//! "m {}", c)`) is never an operand (R6 analog).

use syn::parse::{ParseStream, Parser};

/// The comparison macros, each with whether it asserts inequality.
const PAIRS: [(&str, bool); 6] = [
    ("assert_eq", false),
    ("assert_ne", true),
    ("debug_assert_eq", false),
    ("debug_assert_ne", true),
    ("prop_assert_eq", false),
    ("prop_assert_ne", true),
];

/// The macros that assert one boolean, whose comparison (`a == b`) is read
/// as one.
const BOOLEANS: [&str; 3] = ["assert", "debug_assert", "prop_assert"];

/// The macros that match one value against a pattern.
const MATCHERS: [&str; 2] = ["assert_matches", "debug_assert_matches"];

/// The comparison operators an asserted boolean is read through.
const COMPARISONS: [&str; 6] = ["==", "!=", "<", "<=", ">", ">="];

/// One assertion: its api as the wire names it, whether it asserts the
/// negation, the values it compares, and the macro it was read from.
pub(super) struct AssertionShape<'m> {
    /// `assert_eq`, `assert ==`, `matches`, …
    pub(super) api: String,
    /// `assert_ne!` and `assert!(a != b)`.
    pub(super) negated: bool,
    /// The compared values, messages left out.
    pub(super) operands: Vec<syn::Expr>,
    /// The invocation, whose path's line is the assertion's.
    pub(super) mac: &'m syn::Macro,
}

/// Whether a macro is one [`assertion`] reads, by its last path segment.
pub(super) fn is_assertion_macro(mac: &syn::Macro) -> bool {
    let name = macro_name(mac);
    PAIRS.iter().any(|(pair, _)| *pair == name)
        || BOOLEANS.contains(&name.as_str())
        || MATCHERS.contains(&name.as_str())
}

/// The assertion `mac` makes, or `None` for any other macro, or for an
/// assertion whose arguments do not parse.
pub(super) fn assertion(mac: &syn::Macro) -> Option<AssertionShape<'_>> {
    let name = macro_name(mac);
    let shape = |api: String, negated: bool, operands: Vec<syn::Expr>| AssertionShape {
        api,
        negated,
        operands,
        mac,
    };
    if let Some((_, negated)) = PAIRS.iter().find(|(pair, _)| *pair == name) {
        let arguments = arguments(mac)?;
        let operands: Vec<syn::Expr> = arguments.into_iter().take(2).collect();
        return (operands.len() == 2).then(|| shape(name, *negated, operands));
    }
    if MATCHERS.contains(&name.as_str()) {
        return Some(shape(
            name,
            false,
            vec![first_expression(mac.tokens.clone())?],
        ));
    }
    if !BOOLEANS.contains(&name.as_str()) {
        return None;
    }
    let asserted = arguments(mac)?.into_iter().next()?;
    if let syn::Expr::Binary(binary) = &asserted {
        let operator = operator_text(&binary.op);
        if COMPARISONS.contains(&operator.as_str()) {
            let negated = operator == "!=";
            let operands = vec![(*binary.left).clone(), (*binary.right).clone()];
            return Some(shape(format!("{name} {operator}"), negated, operands));
        }
    }
    if let syn::Expr::Macro(inner) = &asserted {
        if macro_name(&inner.mac) == "matches" {
            let value = first_expression(inner.mac.tokens.clone())?;
            return Some(shape("matches".to_owned(), false, vec![value]));
        }
    }
    Some(shape(name, false, vec![asserted]))
}

/// The last segment of a macro's path: `assert_eq` of `std::assert_eq!`.
pub(super) fn macro_name(mac: &syn::Macro) -> String {
    mac.path
        .segments
        .last()
        .map(|segment| segment.ident.to_string())
        .unwrap_or_default()
}

/// A macro's arguments as comma-separated expressions, or `None` when they
/// do not parse as such.
pub(super) fn arguments(mac: &syn::Macro) -> Option<Vec<syn::Expr>> {
    syn::punctuated::Punctuated::<syn::Expr, syn::Token![,]>::parse_terminated
        .parse2(mac.tokens.clone())
        .ok()
        .map(|arguments| arguments.into_iter().collect())
}

/// The expression before the first top-level comma (the value of
/// `matches!(x, P)`), whatever follows it.
fn first_expression(tokens: proc_macro2::TokenStream) -> Option<syn::Expr> {
    let parser = |input: ParseStream<'_>| {
        let value: syn::Expr = input.parse()?;
        input.parse::<syn::Token![,]>()?;
        input.parse::<proc_macro2::TokenStream>()?;
        Ok(value)
    };
    parser.parse2(tokens).ok()
}

/// A binary operator as written.
fn operator_text(op: &syn::BinOp) -> String {
    match op {
        syn::BinOp::Eq(_) => "==",
        syn::BinOp::Ne(_) => "!=",
        syn::BinOp::Lt(_) => "<",
        syn::BinOp::Le(_) => "<=",
        syn::BinOp::Gt(_) => ">",
        syn::BinOp::Ge(_) => ">=",
        _ => "",
    }
    .to_owned()
}
