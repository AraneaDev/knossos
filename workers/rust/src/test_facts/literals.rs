//! The text of a literal operand (Decision 7's literal set).
//!
//! A literal is a Rust literal token, `-` on a number literal, or a tuple or
//! array of literals. A number's text is `syn`'s `base10_digits()`, so `42u32`
//! and `42` are both `42`, `0x10` is `16` and `1_000` is `1000`: two texts are
//! equal only when the values are. Any other token keeps its source text, so
//! `"a"` and `r"a"` stay apart, which can only miss an equality, never invent
//! one. `&lit`, arithmetic (`1 + 1`) and parentheses are not literals.

/// The normalised text of `expr` when it is a literal, else `None`.
pub(super) fn literal_text(expr: &syn::Expr) -> Option<String> {
    match expr {
        syn::Expr::Lit(literal) => lit_text(&literal.lit),
        syn::Expr::Unary(unary) if matches!(unary.op, syn::UnOp::Neg(_)) => {
            let syn::Expr::Lit(literal) = unary.expr.as_ref() else {
                return None;
            };
            match &literal.lit {
                syn::Lit::Int(_) | syn::Lit::Float(_) => {
                    Some(format!("-{}", lit_text(&literal.lit)?))
                }
                _ => None,
            }
        }
        syn::Expr::Tuple(tuple) => {
            let parts = elements(tuple.elems.iter())?;
            Some(if parts.len() == 1 {
                format!("({},)", parts[0])
            } else {
                format!("({})", parts.join(", "))
            })
        }
        syn::Expr::Array(array) => Some(format!("[{}]", elements(array.elems.iter())?.join(", "))),
        _ => None,
    }
}

/// The texts of a tuple's or an array's elements, when every one is a literal.
fn elements<'e>(items: impl Iterator<Item = &'e syn::Expr>) -> Option<Vec<String>> {
    items.map(literal_text).collect()
}

/// The text of one literal token: a number's decimal digits, anything else
/// as written.
fn lit_text(lit: &syn::Lit) -> Option<String> {
    match lit {
        syn::Lit::Int(int) => Some(int.base10_digits().to_owned()),
        syn::Lit::Float(float) => Some(float.base10_digits().to_owned()),
        syn::Lit::Bool(boolean) => Some(boolean.value.to_string()),
        syn::Lit::Str(text) => Some(text.token().to_string()),
        syn::Lit::ByteStr(bytes) => Some(bytes.token().to_string()),
        syn::Lit::CStr(text) => Some(text.token().to_string()),
        syn::Lit::Byte(byte) => Some(byte.token().to_string()),
        syn::Lit::Char(character) => Some(character.token().to_string()),
        _ => None,
    }
}
