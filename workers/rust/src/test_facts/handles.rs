//! What one function does with its doubles: the locals it binds them to,
//! the `expect_*` chains on each, the calls and hand-ons it makes, and the
//! writes the code may not show (Decision 9's defeats).
//!
//! A call `h.m(…)` (or `h.m(…).await`) on a handle is `mock-config`, and
//! carries the value its expectation returns only when nothing may have
//! changed what `h.m` returns out of sight:
//!
//! - `h` is bound once, directly, to a mock's constructor;
//! - the function has exactly one `expect_m` chain on `h`, a top-level
//!   statement before the call, with exactly one value verb whose value is
//!   a literal;
//! - nothing defeats it: `&mut h`, an assignment to `h`, a move of `h`, a
//!   closure or a macro (other than an assertion) naming it,
//!   `h.checkpoint()`, a call on `h` of a member no `expect_*` configures,
//!   or `unsafe` anywhere in the function.
//!
//! Every other call rooted at a handle is `mock-call`.

use std::collections::{BTreeMap, BTreeSet};

use syn::spanned::Spanned;

/// One `expect_m` chain on a handle.
#[derive(Debug)]
pub(super) struct Chain {
    /// Whether it is a top-level statement of the function's body.
    pub(super) top_level: bool,
    /// Where its `expect_m` starts (line, column).
    pub(super) at: (usize, usize),
    /// Each value verb's value: the literal it returns, or `None`.
    pub(super) values: Vec<Option<String>>,
}

/// Where an operand rooted at a double gets its value.
#[derive(Debug, PartialEq, Eq)]
pub(super) enum MockOperand {
    /// A configured member called on a handle: `h.m(…)`, with the literal
    /// its expectation returns when Decision 9 shows it.
    Config {
        /// The double's canonical name.
        double: String,
        /// The pinned literal.
        value: Option<String>,
    },
    /// Any other call rooted at a double.
    Call {
        /// The double's canonical name.
        double: String,
    },
}

/// The doubles of one function and what the function does with them.
#[derive(Debug, Default)]
pub(super) struct Handles {
    /// The canonical name of each double the function makes, in order.
    pub(super) doubles: Vec<String>,
    /// Each local bound to a double, to the double's position in `doubles`
    /// (the latest binding).
    pub(super) bound: BTreeMap<String, usize>,
    /// How many times each name is bound anywhere in the function.
    pub(super) bindings: BTreeMap<String, usize>,
    /// The handles something may change out of sight.
    pub(super) defeated: BTreeSet<String>,
    /// The members each handle is called by, besides its `expect_*` chains
    /// and `checkpoint`.
    pub(super) called: BTreeMap<String, BTreeSet<String>>,
    /// Each `expect_m` chain, by handle and member.
    pub(super) chains: BTreeMap<(String, String), Vec<Chain>>,
    /// Each static call on a mock (`MockS::bar(…)`), by where its path
    /// starts, to the context double of that member, when the function made
    /// one.
    pub(super) statics: BTreeMap<(usize, usize), Option<usize>>,
    /// Each constructor and context call, by where it starts, to its double.
    pub(super) made: BTreeMap<(usize, usize), usize>,
    /// The lines each configuration chain spans, whose calls are not the
    /// test's own.
    pub(super) config_lines: Vec<(usize, usize)>,
    /// Whether `unsafe` appears anywhere in the function.
    pub(super) unsafe_code: bool,
    /// Whether a macro stands where an item may in the function, which may
    /// configure any handle out of sight.
    pub(super) opaque: bool,
    /// The double each member of a fake handle belongs to, when the fake
    /// has more than one trait impl, by handle and member.
    pub(super) member_doubles: BTreeMap<(String, String), usize>,
    /// The unit fakes whose own name is a handle (`FakeClock.now()`): no
    /// local binds it.
    pub(super) unit_handles: BTreeSet<String>,
    /// Each handle that configures a double without being it (mockers'
    /// `handle` of `let (mock, handle) = …`), to the double and the name
    /// of the local the double is bound to.
    pub(super) config_of: BTreeMap<String, (usize, Option<String>)>,
    /// The members a static mock of the function configures (mry's
    /// `Cat::mock_meow(…)`): which configuration answers a handle's call of
    /// one is not told.
    pub(super) static_members: BTreeSet<String>,
}

impl Handles {
    /// The double the handle `name` holds.
    pub(super) fn double_of(&self, name: &str) -> Option<&str> {
        self.bound
            .get(name)
            .and_then(|index| self.doubles.get(*index))
            .map(String::as_str)
    }

    /// Whether a call on `line` sits inside a configuration chain.
    pub(super) fn configures(&self, line: usize) -> bool {
        self.config_lines
            .iter()
            .any(|(start, end)| (*start..=*end).contains(&line))
    }

    /// The double `expr` is rooted at, through method calls, fields,
    /// `.await`, `?`, `&` and parentheses: a handle, or a constructor,
    /// context or static call on a mock.
    pub(super) fn root_double(&self, expr: &syn::Expr) -> Option<&str> {
        match expr {
            syn::Expr::MethodCall(call) => self.root_double(&call.receiver),
            syn::Expr::Field(field) => self.root_double(&field.base),
            syn::Expr::Await(awaited) => self.root_double(&awaited.base),
            syn::Expr::Try(attempt) => self.root_double(&attempt.expr),
            syn::Expr::Paren(paren) => self.root_double(&paren.expr),
            syn::Expr::Reference(reference) => self.root_double(&reference.expr),
            syn::Expr::Path(path) => match self.made.get(&start(path)) {
                Some(index) => self.doubles.get(*index).map(String::as_str),
                None => {
                    let name = handle_name(path)?;
                    self.double_of(&name).or_else(|| {
                        let (index, _) = self.config_of.get(&name)?;
                        self.doubles.get(*index).map(String::as_str)
                    })
                }
            },
            syn::Expr::Macro(mac) => self.made_at(start(&mac.mac.path)),
            syn::Expr::Struct(literal) => self.made_at(start(&literal.path)),
            syn::Expr::Call(call) => {
                let at = start(&call.func);
                let index = self
                    .made
                    .get(&at)
                    .copied()
                    .or_else(|| self.statics.get(&at).copied().flatten())
                    .or_else(|| self.ufcs(call))?;
                self.doubles.get(index).map(String::as_str)
            }
            _ => None,
        }
    }

    /// The double the construct starting at `at` makes.
    fn made_at(&self, at: (usize, usize)) -> Option<&str> {
        self.made
            .get(&at)
            .and_then(|index| self.doubles.get(*index))
            .map(String::as_str)
    }

    /// The double a fully qualified call takes as its receiver
    /// (`<MockFoo as Foo>::foo(&mock, 1)`). A plain path call handed the
    /// handle (`Foo::foo(&mock)`, `elapsed(&clock)`) may be production's,
    /// and is left to the production count.
    fn ufcs(&self, call: &syn::ExprCall) -> Option<usize> {
        let syn::Expr::Path(func) = call.func.as_ref() else {
            return None;
        };
        func.qself.as_ref()?;
        let mut first = call.args.first()?;
        while let syn::Expr::Reference(reference) = first {
            first = &reference.expr;
        }
        let syn::Expr::Path(path) = first else {
            return None;
        };
        self.bound.get(&handle_name(path)?).copied()
    }

    /// Where `expr` gets its value when it is rooted at a double.
    pub(super) fn operand(&self, expr: &syn::Expr) -> Option<MockOperand> {
        let direct = match expr {
            syn::Expr::Await(awaited) => awaited.base.as_ref(),
            other => other,
        };
        if let syn::Expr::MethodCall(call) = direct {
            if let Some(name) = receiver_handle(&call.receiver) {
                let member = crate::resolve::ident_name(&call.method);
                let double = match self.member_doubles.get(&(name.clone(), member.clone())) {
                    Some(index) => self.doubles.get(*index)?,
                    None => self.double_of(&name)?,
                }
                .to_owned();
                if self.chains.contains_key(&(name.clone(), member.clone())) {
                    let value = self.value(&name, &member, start(&call.method));
                    return Some(MockOperand::Config { double, value });
                }
                return Some(MockOperand::Call { double });
            }
        }
        if let syn::Expr::Call(call) = direct {
            if let Some(Some(index)) = self.statics.get(&start(&call.func)) {
                return Some(MockOperand::Config {
                    double: self.doubles.get(*index)?.clone(),
                    value: None,
                });
            }
        }
        let double = self.root_double(expr)?.to_owned();
        Some(MockOperand::Call { double })
    }

    /// The literal `handle.member(…)`, called at `at`, returns for certain,
    /// as Decision 9 shows it.
    fn value(&self, handle: &str, member: &str, at: (usize, usize)) -> Option<String> {
        // A unit fake's own name is bound by no local; any other handle by
        // exactly one.
        let bound_once = if self.unit_handles.contains(handle) {
            None
        } else {
            Some(1)
        };
        if self.unsafe_code
            || self.opaque
            || self.defeated.contains(handle)
            || self.static_members.contains(member)
            || self.bindings.get(handle).copied() != bound_once
        {
            return None;
        }
        let configured: BTreeSet<&str> = self
            .chains
            .keys()
            .filter(|(owner, _)| owner == handle)
            .map(|(_, name)| name.as_str())
            .collect();
        if self.called.get(handle).is_some_and(|called| {
            called
                .iter()
                .any(|name| !configured.contains(name.as_str()))
        }) {
            return None;
        }
        let [chain] = self
            .chains
            .get(&(handle.to_owned(), member.to_owned()))?
            .as_slice()
        else {
            return None;
        };
        if !chain.top_level || chain.at >= at {
            return None;
        }
        match chain.values.as_slice() {
            [Some(value)] => Some(value.clone()),
            _ => None,
        }
    }
}

/// The name a single-identifier path is, `h` of `h`.
pub(super) fn handle_name(path: &syn::ExprPath) -> Option<String> {
    if path.qself.is_some() {
        return None;
    }
    path.path.get_ident().map(crate::resolve::ident_name)
}

/// The handle a receiver is, through parentheses.
pub(super) fn receiver_handle(receiver: &syn::Expr) -> Option<String> {
    match receiver {
        syn::Expr::Paren(paren) => receiver_handle(&paren.expr),
        syn::Expr::Path(path) => handle_name(path),
        _ => None,
    }
}

/// Where a syntax node starts (line, column).
pub(super) fn start(node: &impl Spanned) -> (usize, usize) {
    let at = node.span().start();
    (at.line, at.column)
}
