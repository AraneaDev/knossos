//! Where an assertion's operand gets its value, inside one test: the wire
//! `{source, text?}` (Decision 7).
//!
//! - `literal`: a literal, or a name that holds one for certain
//!   ([`LocalLiterals`]), with that literal's text;
//! - `production`: a call the walk resolved to a project declaration outside
//!   test code, and what is read off its result;
//! - `parameter`: a parameter of the case (`rstest`, `test_case`,
//!   `sqlx::test`);
//! - `unknown`: everything else.
//!
//! Every Rust comparison goes through `PartialEq`, which need not be
//! reflexive (NaN, an `eq` under test), so only a literal carries `text`:
//! a Rust `self_comparison` can only be two equal literals (the P20 analog).

use std::collections::BTreeSet;

use serde_json::{json, Value};
use syn::spanned::Spanned;

use super::literals::literal_text;
use super::locals::LocalLiterals;
use super::production::Reach;
use crate::resolve::ident_name;

/// How many links an operand is followed through to the value it is read
/// off (`a.b().c`).
const MAX_DEPTH: usize = 12;

/// The source of a value, and a literal's text.
#[derive(Debug, PartialEq, Eq)]
enum Source {
    /// A literal, with its text.
    Literal(String),
    /// A call into production, or what is read off one.
    Production,
    /// A parameter of the case.
    Parameter,
    /// Anything else.
    Unknown,
}

/// Reads the operands of one case's assertions.
pub(super) struct OperandReader<'r> {
    /// The names that hold a literal.
    pub(super) locals: &'r LocalLiterals<'r>,
    /// The case's parameter names.
    pub(super) parameters: BTreeSet<String>,
    /// The production calls the case makes.
    pub(super) production: &'r Reach,
}

impl OperandReader<'_> {
    /// The wire form of `operand`.
    pub(super) fn wire(&self, operand: &syn::Expr) -> Value {
        match self.source(operand, 0) {
            Source::Literal(text) => json!({"source": "literal", "text": text}),
            Source::Production => json!({"source": "production"}),
            Source::Parameter => json!({"source": "parameter"}),
            Source::Unknown => json!({"source": "unknown"}),
        }
    }

    /// Where `expr`'s value comes from.
    fn source(&self, expr: &syn::Expr, depth: usize) -> Source {
        if depth > MAX_DEPTH {
            return Source::Unknown;
        }
        if let Some(text) = literal_text(expr) {
            return Source::Literal(text);
        }
        match expr {
            syn::Expr::Path(path) => self.name(path),
            syn::Expr::Call(call) => {
                let syn::Expr::Path(callee) = call.func.as_ref() else {
                    return Source::Unknown;
                };
                let Some(last) = callee.path.segments.last() else {
                    return Source::Unknown;
                };
                let called = callee.path.span().start().line;
                if self
                    .production
                    .is_production(called, &ident_name(&last.ident))
                {
                    Source::Production
                } else {
                    Source::Unknown
                }
            }
            syn::Expr::MethodCall(call) => {
                let called = call.method.span().start().line;
                if self
                    .production
                    .is_production(called, &ident_name(&call.method))
                {
                    Source::Production
                } else {
                    self.derived(&call.receiver, depth)
                }
            }
            syn::Expr::Field(field) => self.derived(&field.base, depth),
            syn::Expr::Try(attempt) => self.derived(&attempt.expr, depth),
            syn::Expr::Await(awaited) => self.derived(&awaited.base, depth),
            syn::Expr::Reference(reference) => self.derived(&reference.expr, depth),
            _ => Source::Unknown,
        }
    }

    /// A plain name: a literal it holds for certain where it is read, a
    /// case parameter, or unknown.
    fn name(&self, path: &syn::ExprPath) -> Source {
        let Some(ident) = path.path.get_ident().filter(|_| path.qself.is_none()) else {
            return Source::Unknown;
        };
        let name = ident_name(ident);
        let at = ident.span().start();
        if let Some(text) = self.locals.text(&name, (at.line, at.column)) {
            return Source::Literal(text.to_owned());
        }
        if self.parameters.contains(&name) {
            return Source::Parameter;
        }
        Source::Unknown
    }

    /// What is read off `base`: production's when `base` is production's.
    fn derived(&self, base: &syn::Expr, depth: usize) -> Source {
        match self.source(base, depth + 1) {
            Source::Production => Source::Production,
            _ => Source::Unknown,
        }
    }
}
