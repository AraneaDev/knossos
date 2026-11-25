//! mockall's doubles in one function (Decision 3), ported from Momus's
//! `collect`, `walkExpr`, `recordMockMacro` and `ufcsStaticKey` and
//! Nemesis's Rust doubles, every mock type resolved through the worker
//! ([`MockResolver`]), never by its name alone.
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

use syn::spanned::Spanned;
use syn::visit::Visit;

use super::assertions::arguments;
use super::doubles::{DoubleFact, Pin};
use super::generated::MockResolver;
use super::handles::{handle_name, receiver_handle, start, Chain, Handles};
use super::macros::Frozen;
use super::syntax::{
    identifiers, innermost_call, returned_literal, strip_parens, BodyNames, Named,
};
use crate::resolve::ident_name;
use crate::visit::GeneratedMock;

/// The verbs that pin a value, each with whether it returns once only.
const VALUE_VERBS: [(&str, bool); 6] = [
    ("return_const", false),
    ("return_const_st", false),
    ("returning", false),
    ("returning_st", false),
    ("return_once", true),
    ("return_once_st", true),
];

/// The verbs that assert how often a member is called.
const TIMES_VERBS: [&str; 3] = ["times", "once", "never"];

/// What one function's pass found.
#[derive(Debug, Default)]
pub(super) struct MockallPass {
    /// Its doubles, in source order.
    pub(super) doubles: Vec<DoubleFact>,
    /// What it does with them.
    pub(super) handles: Handles,
    /// Each `times` assertion: its line and its double.
    pub(super) times: Vec<(usize, String)>,
}

/// Where one function sits and what it is.
pub(super) struct Site<'s> {
    /// The file's project-relative path.
    pub(super) relative: &'s str,
    /// The module the function is declared in.
    pub(super) container: &'s str,
    /// The case's canonical name, none for a helper.
    pub(super) test_case: Option<String>,
    /// Which macros are the standard ones.
    pub(super) frozen: &'s Frozen<'s>,
}

/// The mockall doubles of one function, with its signature and body.
pub(super) fn mockall_doubles(
    signature: &syn::Signature,
    block: &syn::Block,
    site: &Site<'_>,
    resolver: &MockResolver<'_>,
) -> MockallPass {
    let top_level = block
        .stmts
        .iter()
        .filter_map(|statement| match statement {
            syn::Stmt::Expr(expr, _) => Some(expr),
            _ => None,
        })
        .filter_map(innermost_call)
        .map(|call| start(&call.method))
        .collect();
    let mut hidden = BodyNames::default();
    hidden.visit_block(block);
    let mut pass = Pass {
        site,
        resolver,
        found: MockallPass::default(),
        top_level,
        statics: Vec::new(),
        hidden: hidden.0,
    };
    pass.visit_signature(signature);
    pass.visit_block(block);
    pass.finish()
}

/// The visitor over one function.
struct Pass<'p> {
    /// Where the function is.
    site: &'p Site<'p>,
    /// Resolves the mock types it names.
    resolver: &'p MockResolver<'p>,
    /// What it found so far.
    found: MockallPass,
    /// Where each top-level statement's innermost method call starts.
    top_level: std::collections::BTreeSet<(usize, usize)>,
    /// Each static call on a mock: where it starts, the mock and the member.
    statics: Vec<((usize, usize), String, String)>,
    /// Every name an item or `use` in the body declares: it hides a
    /// generated mock of that name (a local `struct MockClock`).
    hidden: std::collections::BTreeSet<String>,
}

/// What a path call is to a mock type.
enum MockCall<'m> {
    /// `MockS::new()` and the like.
    Constructor(String, &'m GeneratedMock),
    /// `MockS::bar_context()`: the mock and the member.
    Context(String, &'m GeneratedMock, String),
    /// `MockS::bar(…)`: the mock and the member.
    Static(String, String),
}

impl<'p> Pass<'p> {
    /// The mock `path` names, unless the body declares its first name.
    fn resolve(&self, path: &syn::Path) -> Option<(String, &'p GeneratedMock)> {
        let head = ident_name(&path.segments.first()?.ident);
        if path.leading_colon.is_none() && self.hidden.contains(&head) {
            return None;
        }
        self.resolver.resolve(self.site.container, path)
    }

    /// What the call `func` is to a mock type, if anything.
    fn classify(&self, func: &syn::Expr) -> Option<MockCall<'p>> {
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
            let (mock, generated) = self.resolve(&ty.path)?;
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
        let (mock, generated) = self.resolve(&prefix)?;
        if let Some(member) = last.strip_suffix("_context") {
            return Some(MockCall::Context(mock, generated, member.to_owned()));
        }
        match last.as_str() {
            // A `new` the mocked type declares itself is mocked as a
            // static method: it returns what its context's expectation
            // does, never a fresh mock.
            "new" | "default"
                if !generated.contexts_only && !generated.mocked_constructors.contains(&last) =>
            {
                Some(MockCall::Constructor(mock, generated))
            }
            _ => Some(MockCall::Static(mock, last)),
        }
    }

    /// Make the double of the construct `node`, returning its position.
    fn make(&mut self, node: &syn::Expr, mock: &str, generated: &GeneratedMock) -> usize {
        let at = node.span().start();
        let canonical = format!(
            "{}#double:{}:{}",
            self.site.relative,
            at.line,
            at.column + 1
        );
        self.found.doubles.push(DoubleFact {
            canonical: canonical.clone(),
            mock: mock.to_owned(),
            span: node.span(),
            pattern: generated.pattern,
            target: generated
                .target
                .clone()
                .map(|target| (generated.target_kind, target)),
            members: Vec::new(),
            invocations: 0,
            test_case: self.site.test_case.clone(),
            module: self.site.container.to_owned(),
        });
        self.found.handles.doubles.push(canonical);
        let index = self.found.doubles.len() - 1;
        if let syn::Expr::Call(call) = node {
            self.found.handles.made.insert(start(&call.func), index);
        }
        index
    }

    /// The double `expr` makes when it is a constructor or a context call.
    fn constructed(&mut self, expr: &syn::Expr) -> Option<usize> {
        let syn::Expr::Call(call) = expr else {
            return None;
        };
        match self.classify(&call.func)? {
            MockCall::Constructor(mock, generated) => Some(self.make(expr, &mock, generated)),
            MockCall::Context(mock, generated, member) => {
                let index = self.make(expr, &mock, generated);
                let line = call.func.span().start().line;
                self.found.doubles[index].member(&member, "context", line);
                Some(index)
            }
            MockCall::Static(..) => None,
        }
    }

    /// The double a `let NAME: MockS = Default::default();` makes.
    fn defaulted(&mut self, local: &syn::Local, init: &syn::Expr) -> Option<usize> {
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
        let (mock, generated) = self.resolve(&ty.path)?;
        Some(self.make(init, &mock, generated))
    }

    /// Count one call on or hand-on of the double at `index`.
    fn invoke(&mut self, index: usize) {
        if let Some(double) = self.found.doubles.get_mut(index) {
            double.invocations += 1;
        }
    }

    /// Count a hand-on of the handle `name`, defeating its value when
    /// `defeats`.
    fn hand_on(&mut self, name: &str, defeats: bool) {
        if let Some(index) = self.found.handles.bound.get(name).copied() {
            self.invoke(index);
            if defeats {
                self.found.handles.defeated.insert(name.to_owned());
            }
        }
    }

    /// Read a method-call chain rooted at the handle `handle`: `calls` runs
    /// from the outermost call to the innermost, the one on the handle.
    fn chain(&mut self, handle: &str, calls: &[&syn::ExprMethodCall]) {
        let Some(index) = self.found.handles.bound.get(handle).copied() else {
            return;
        };
        let Some(innermost) = calls.last() else {
            return;
        };
        let method = ident_name(&innermost.method);
        let context = self.found.doubles[index]
            .members
            .first()
            .filter(|member| member.api == "context")
            .map(|member| member.name.clone());
        let member = match (&context, method.strip_prefix("expect_")) {
            (Some(member), None) if method == "expect" => member.clone(),
            (None, Some(member)) => member.to_owned(),
            _ => {
                if method == "checkpoint" {
                    self.found.handles.defeated.insert(handle.to_owned());
                } else {
                    self.invoke(index);
                    let called = self.found.handles.called.entry(handle.to_owned());
                    called.or_default().insert(method);
                }
                return;
            }
        };
        let line = innermost.method.span().start().line;
        let mut values = Vec::new();
        let mut pins = Vec::new();
        for call in calls.iter().rev().skip(1) {
            let verb = ident_name(&call.method);
            let verb_line = call.method.span().start().line;
            if let Some((_, once)) = VALUE_VERBS.iter().find(|(name, _)| *name == verb) {
                values.push(call.args.first().and_then(returned_literal));
                pins.push(Pin {
                    api: verb,
                    line: verb_line,
                    once: *once,
                });
            } else if TIMES_VERBS.contains(&verb.as_str()) {
                let double = self.found.doubles[index].canonical.clone();
                self.found.times.push((verb_line, double));
            }
        }
        let configured = self.found.doubles[index].member(&member, "expect", line);
        if context.is_none() {
            configured.returns.extend(pins);
        }
        let outer = calls.first().map_or(line, |call| call.span().end().line);
        self.found.handles.config_lines.push((line, outer));
        let at = start(&innermost.method);
        self.found
            .handles
            .chains
            .entry((handle.to_owned(), member))
            .or_default()
            .push(Chain {
                top_level: self.top_level.contains(&at),
                at,
                values,
            });
    }

    /// Settle the static calls against the contexts made, and hand over.
    fn finish(mut self) -> MockallPass {
        for (at, mock, member) in std::mem::take(&mut self.statics) {
            let context = self.found.doubles.iter().position(|double| {
                double.members.first().is_some_and(|first| {
                    first.api == "context" && first.name == member && double.mock == mock
                })
            });
            if let Some(index) = context {
                self.invoke(index);
            }
            self.found.handles.statics.insert(at, context);
        }
        self.found
    }
}

impl<'ast> Visit<'ast> for Pass<'_> {
    fn visit_item(&mut self, node: &'ast syn::Item) {
        // A nested item is a function of its own; but a macro defined or
        // invoked where an item stands (`macro_rules! setup`) may name
        // any handle of the function, hygiene notwithstanding.
        self.found.handles.opaque |= matches!(node, syn::Item::Macro(_));
    }

    fn visit_stmt_macro(&mut self, node: &'ast syn::StmtMacro) {
        // An unknown macro where an item may stand may expand to anything.
        self.found.handles.opaque |= self.site.frozen.declares_items(&node.mac);
        syn::visit::visit_stmt_macro(self, node);
    }

    fn visit_local(&mut self, node: &'ast syn::Local) {
        let made = node.init.as_ref().and_then(|init| {
            if init.diverge.is_some() {
                return None;
            }
            self.constructed(&init.expr)
                .or_else(|| self.defaulted(node, &init.expr))
        });
        let Some(index) = made else {
            syn::visit::visit_local(self, node);
            return;
        };
        self.visit_pat(&node.pat);
        let pattern = match &node.pat {
            syn::Pat::Type(typed) => typed.pat.as_ref(),
            other => other,
        };
        match pattern {
            syn::Pat::Ident(ident) if ident.by_ref.is_none() && ident.subpat.is_none() => {
                self.found
                    .handles
                    .bound
                    .insert(ident_name(&ident.ident), index);
            }
            // Bound to no one name: handed on.
            _ => self.invoke(index),
        }
        if let Some(init) = &node.init {
            if let syn::Expr::Call(call) = init.expr.as_ref() {
                for argument in &call.args {
                    self.visit_expr(argument);
                }
            }
        }
    }

    fn visit_expr_call(&mut self, node: &'ast syn::ExprCall) {
        let whole = syn::Expr::Call(node.clone());
        if let Some(index) = self.constructed(&whole) {
            // Made and not bound: handed on where it stands.
            self.invoke(index);
        } else if let Some(MockCall::Static(mock, member)) = self.classify(&node.func) {
            self.statics.push((start(&node.func), mock, member));
        }
        for argument in &node.args {
            self.visit_expr(argument);
        }
    }

    fn visit_expr_method_call(&mut self, node: &'ast syn::ExprMethodCall) {
        let mut calls = vec![node];
        let mut receiver = node.receiver.as_ref();
        while let syn::Expr::MethodCall(inner) = strip_parens(receiver) {
            calls.push(inner);
            receiver = inner.receiver.as_ref();
        }
        let handle =
            receiver_handle(receiver).filter(|name| self.found.handles.bound.contains_key(name));
        let Some(handle) = handle else {
            syn::visit::visit_expr_method_call(self, node);
            return;
        };
        self.chain(&handle, &calls);
        for call in calls {
            for argument in &call.args {
                self.visit_expr(argument);
            }
        }
    }

    fn visit_expr_reference(&mut self, node: &'ast syn::ExprReference) {
        if let syn::Expr::Path(path) = strip_parens(&node.expr) {
            if let Some(name) = handle_name(path) {
                if self.found.handles.bound.contains_key(&name) {
                    self.hand_on(&name, node.mutability.is_some());
                    return;
                }
            }
        }
        syn::visit::visit_expr_reference(self, node);
    }

    fn visit_expr_path(&mut self, node: &'ast syn::ExprPath) {
        if let Some(name) = handle_name(node) {
            // Moved, or read by value.
            self.hand_on(&name, true);
        }
    }

    fn visit_expr_assign(&mut self, node: &'ast syn::ExprAssign) {
        if let syn::Expr::Path(path) = strip_parens(&node.left) {
            if let Some(name) = handle_name(path) {
                if self.found.handles.bound.contains_key(&name) {
                    self.found.handles.defeated.insert(name);
                    self.visit_expr(&node.right);
                    return;
                }
            }
        }
        syn::visit::visit_expr_assign(self, node);
    }

    fn visit_expr_closure(&mut self, node: &'ast syn::ExprClosure) {
        let mut named = Named::default();
        named.visit_expr(&node.body);
        let captured: Vec<String> = named
            .0
            .into_iter()
            .filter(|name| self.found.handles.bound.contains_key(name))
            .collect();
        self.found.handles.defeated.extend(captured);
        syn::visit::visit_expr_closure(self, node);
    }

    fn visit_expr_unsafe(&mut self, node: &'ast syn::ExprUnsafe) {
        self.found.handles.unsafe_code = true;
        syn::visit::visit_expr_unsafe(self, node);
    }

    fn visit_signature(&mut self, node: &'ast syn::Signature) {
        self.found.handles.unsafe_code |= matches!(node.safety, syn::Safety::Unsafe(_));
        syn::visit::visit_signature(self, node);
    }

    fn visit_pat_ident(&mut self, node: &'ast syn::PatIdent) {
        *self
            .found
            .handles
            .bindings
            .entry(ident_name(&node.ident))
            .or_insert(0) += 1;
        syn::visit::visit_pat_ident(self, node);
    }

    fn visit_macro(&mut self, node: &'ast syn::Macro) {
        if self.site.frozen.is_assertion(node) {
            if let Some(arguments) = arguments(node) {
                for argument in &arguments {
                    self.visit_expr(argument);
                }
                return;
            }
        }
        let mut named = std::collections::BTreeSet::new();
        identifiers(node.tokens.clone(), &mut named);
        self.found.handles.unsafe_code |= named.contains("unsafe");
        let handed: Vec<String> = named
            .into_iter()
            .filter(|name| self.found.handles.bound.contains_key(name))
            .collect();
        for name in handed {
            self.hand_on(&name, true);
        }
    }
}
