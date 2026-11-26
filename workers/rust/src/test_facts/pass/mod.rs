//! One function's doubles and what it does with them (Decisions 3, 4 and
//! 9): every framework's, in one walk of the body, so each handle's
//! defeats are read the same way whatever made it.
//!
//! A double is made by a constructor ([`super::mockall`],
//! [`super::frameworks`]) or, for a function a test mocks, by its
//! configuration; a hand-written fake's doubles are the file's, and a local
//! bound to a value of the fake is a handle of them ([`super::fakes`]).
//! The pass reads each configuration chain on a handle, the calls on it and
//! the hand-ons of it, and the writes the code may not show
//! ([`Handles`]).

use std::collections::{BTreeMap, BTreeSet};

use syn::spanned::Spanned;
use syn::visit::Visit;

use super::doubles::DoubleFact;
use super::fakes::Fake;
use super::frameworks::{
    faux, galvanic, mock_derive, mockers, mockiato, mry, Framework, FrameworkContext, Made,
};
use super::generated::MockResolver;
use super::handles::{receiver_handle, start, Handles};
use super::macros::Frozen;
use super::mockall::{self, MockCall};
use super::syntax::{strip_parens, BodyNames};
use super::trait_rivals::TraitRivals;
use crate::resolve::ident_name;
use crate::visit::GeneratedMock;

mod chains;
mod fake_handles;
mod visit;

/// What one function's pass found.
#[derive(Debug, Default)]
pub(super) struct FunctionDoubles {
    /// Its doubles, in source order.
    pub(super) doubles: Vec<DoubleFact>,
    /// What it does with them.
    pub(super) handles: Handles,
    /// Each `times` assertion: its line and its double.
    pub(super) times: Vec<(usize, String)>,
    /// The calls on and hand-ons of each fake's handles, by the fake's
    /// canonical path.
    pub(super) fake_invocations: BTreeMap<String, usize>,
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
    /// The file's framework names.
    pub(super) frameworks: &'s FrameworkContext<'s>,
    /// The file's fakes.
    pub(super) fakes: &'s [Fake],
    /// What else may take a call on the file's trait mocks.
    pub(super) trait_rivals: &'s TraitRivals<'s>,
}

/// The doubles of one function, with its signature and body.
pub(super) fn function_doubles(
    signature: &syn::Signature,
    block: &syn::Block,
    site: &Site<'_>,
    resolver: &MockResolver<'_>,
) -> FunctionDoubles {
    let top_level = block
        .stmts
        .iter()
        .filter_map(|statement| match statement {
            syn::Stmt::Expr(expr, _) => chain_start(expr),
            _ => None,
        })
        .collect();
    let mut hidden = BodyNames::default();
    hidden.visit_block(block);
    let mut pass = Pass {
        site,
        resolver,
        found: FunctionDoubles::default(),
        top_level,
        statics: Vec::new(),
        hidden: hidden.0,
        kinds: Vec::new(),
        fake_slots: Vec::new(),
        local_fakes: Vec::new(),
        invoked_by: Vec::new(),
        path_calls: Vec::new(),
        static_made: BTreeMap::new(),
        trait_mocks: BTreeMap::new(),
    };
    pass.bind_fakes();
    pass.visit_signature(signature);
    pass.visit_block(block);
    pass.finish()
}

/// Where a top-level statement's configuration chain starts: its innermost
/// method call on a plain name, or the macro a chain is made on
/// (`faux::when!(…)`).
fn chain_start(expr: &syn::Expr) -> Option<(usize, usize)> {
    let syn::Expr::MethodCall(outer) = strip_parens(expr) else {
        return None;
    };
    let mut call = outer;
    while let syn::Expr::MethodCall(inner) = strip_parens(&call.receiver) {
        call = inner;
    }
    match strip_parens(&call.receiver) {
        syn::Expr::Macro(mac) => Some(start(&mac.mac.path)),
        receiver => receiver_handle(receiver).map(|_| start(&call.method)),
    }
}

/// The visitor over one function.
struct Pass<'p> {
    /// Where the function is.
    site: &'p Site<'p>,
    /// Resolves the mock types it names.
    resolver: &'p MockResolver<'p>,
    /// What it found so far.
    found: FunctionDoubles,
    /// Where each top-level statement's configuration chain starts.
    top_level: BTreeSet<(usize, usize)>,
    /// Each static call on a mockall mock: where it starts, the mock and
    /// the member.
    statics: Vec<((usize, usize), String, String)>,
    /// Every name an item or `use` in the body declares: it hides a
    /// generated mock or a fake of that name (a local `struct MockClock`).
    hidden: BTreeSet<String>,
    /// Each double's framework, by position.
    kinds: Vec<Framework>,
    /// The fake each of the first doubles stands for, by its canonical
    /// path: the file's fake doubles, which the function only uses.
    fake_slots: Vec<String>,
    /// The fakes a local of the function may hold, each with the position
    /// of its first double.
    local_fakes: Vec<(&'p Fake, usize)>,
    /// Each double of a mocked function, with the path a call of it is
    /// written as.
    invoked_by: Vec<(usize, String)>,
    /// The path of every path call the function makes, written out.
    path_calls: Vec<String>,
    /// Each static mock made, by mock and member.
    static_made: BTreeMap<(String, String), usize>,
    /// Each double that is a trait mock, by position: the traits it
    /// implements, and the members it gives them (`None` for all of them).
    trait_mocks: BTreeMap<usize, TraitMock>,
}

/// The traits a trait mock implements, and the members it gives them: a
/// call of one may be taken by another trait in scope (see
/// [`TraitRivals`]). A member of the mock's own (an inherent `mock!`
/// section) is no trait's.
#[derive(Debug)]
struct TraitMock {
    /// Each trait, `None` when it could not be placed.
    traits: Vec<Option<String>>,
    /// The trait members, `None` for every member of the mock.
    members: Option<BTreeSet<String>>,
}

impl<'p> Pass<'p> {
    /// Whether the body leaves `path`'s first name to the module: no item
    /// or `use` of the body declares it.
    fn visible(&self, path: &syn::Path) -> bool {
        path.leading_colon.is_some()
            || path
                .segments
                .first()
                .is_some_and(|head| !self.hidden.contains(&ident_name(&head.ident)))
    }

    /// The mockall mock `path` names, unless the body declares its first
    /// name.
    fn resolve(&self, path: &syn::Path) -> Option<(String, &'p GeneratedMock)> {
        if !self.visible(path) {
            return None;
        }
        self.resolver.resolve(self.site.container, path)
    }

    /// What a mockall path call `func` is.
    fn classify(&self, func: &syn::Expr) -> Option<MockCall<'p>> {
        mockall::classify(func, &|path| self.resolve(path))
    }

    /// Add one double, returning its position.
    fn add(&mut self, fact: DoubleFact, framework: Framework) -> usize {
        self.found.handles.doubles.push(fact.canonical.clone());
        self.found.doubles.push(fact);
        self.kinds.push(framework);
        self.found.doubles.len() - 1
    }

    /// Make the double `made` describes, at the construct `span`, returning
    /// its position.
    fn make(&mut self, span: proc_macro2::Span, made: Made) -> usize {
        let at = span.start();
        let canonical = format!(
            "{}#double:{}:{}",
            self.site.relative,
            at.line,
            at.column + 1
        );
        // A mock of a trait; a mockall mock's traits and members are the
        // generator's, set after (see `make_mockall`).
        let trait_target = match &made.target {
            Some(("interface", path)) => Some(path.clone()),
            _ => None,
        };
        let fact = DoubleFact {
            canonical,
            mock: made.mock,
            span,
            framework: made.framework.name(),
            pattern: made.pattern,
            target: made.target,
            target_kind: made.target_kind,
            members: Vec::new(),
            invocations: 0,
            test_case: self.site.test_case.clone(),
            module: self.site.container.to_owned(),
        };
        let index = self.add(fact, made.framework);
        if let Some(path) = trait_target {
            let mock = TraitMock {
                traits: vec![Some(path)],
                members: None,
            };
            self.trait_mocks.insert(index, mock);
        }
        if let Some((member, api)) = made.member {
            self.found.doubles[index].member(&member, api, at.line);
        }
        if let Some(path) = made.invoked_by {
            self.invoked_by.push((index, path));
        }
        index
    }

    /// The double a mockall generator makes at `node`.
    fn make_mockall(&mut self, node: &syn::Expr, mock: &str, generated: &GeneratedMock) -> usize {
        let made = Made {
            framework: Framework::Mockall,
            mock: mock.to_owned(),
            pattern: generated.pattern,
            target: generated
                .target
                .clone()
                .map(|target| (generated.target_kind, target)),
            target_kind: "class",
            member: None,
            invoked_by: None,
        };
        let index = self.make(node.span(), made);
        // A mock of inherent methods alone gives no member to a trait.
        let mock = TraitMock {
            traits: generated
                .traits
                .iter()
                .map(|mocked| mocked.path.clone())
                .collect(),
            members: Some(
                generated
                    .traits
                    .iter()
                    .flat_map(|mocked| mocked.members.iter().cloned())
                    .collect(),
            ),
        };
        self.trait_mocks.insert(index, mock);
        index
    }

    /// The double `expr` makes when it is a constructor or a mockall
    /// context call.
    fn constructed(&mut self, expr: &syn::Expr) -> Option<usize> {
        let context = self.site.frameworks;
        let container = self.site.container;
        let (made, at) = match expr {
            syn::Expr::Call(call) => {
                if let Some(kind) = self.classify(&call.func) {
                    return self.mockall_constructed(expr, call, kind);
                }
                let syn::Expr::Path(func) = call.func.as_ref() else {
                    return None;
                };
                if !self.visible(&func.path) {
                    return None;
                }
                let made = faux::constructed(context, container, call)
                    .or_else(|| mockiato::constructed(context, container, call))
                    .or_else(|| mock_derive::constructed(context, container, call))
                    .or_else(|| mry::constructed(context, container, expr))?;
                (made, start(&call.func))
            }
            syn::Expr::MethodCall(call) => (
                mockers::constructed(context, container, call)?,
                start(&call.method),
            ),
            syn::Expr::Macro(mac) if self.visible(&mac.mac.path) => (
                galvanic::constructed(context, container, &mac.mac)
                    .or_else(|| mry::constructed(context, container, expr))?,
                start(&mac.mac.path),
            ),
            syn::Expr::Struct(literal) if self.visible(&literal.path) => (
                mry::constructed(context, container, expr)?,
                start(&literal.path),
            ),
            _ => return None,
        };
        let index = self.make(expr.span(), made);
        self.found.handles.made.insert(at, index);
        Some(index)
    }

    /// The double a mockall constructor or context call `call` makes.
    fn mockall_constructed(
        &mut self,
        expr: &syn::Expr,
        call: &syn::ExprCall,
        kind: MockCall<'p>,
    ) -> Option<usize> {
        let index = match kind {
            MockCall::Constructor(mock, generated) => self.make_mockall(expr, &mock, generated),
            MockCall::Context(mock, generated, member) => {
                let index = self.make_mockall(expr, &mock, generated);
                let line = call.func.span().start().line;
                self.found.doubles[index].member(&member, "context", line);
                index
            }
            MockCall::Static(..) => return None,
        };
        self.found.handles.made.insert(start(&call.func), index);
        Some(index)
    }

    /// The double a `let NAME: MockS = Default::default();` makes.
    fn defaulted(&mut self, local: &syn::Local, init: &syn::Expr) -> Option<usize> {
        let path = mockall::defaulted(local, init)?;
        let (mock, generated) = self.resolve(path)?;
        Some(self.make_mockall(init, &mock, generated))
    }

    /// Visit what the constructor `expr` is handed: its arguments, its
    /// receiver, its fields or its macro's tokens, never the constructor's
    /// own path.
    fn visit_made_arguments(&mut self, expr: &syn::Expr) {
        match expr {
            syn::Expr::Call(call) => {
                for argument in &call.args {
                    self.visit_expr(argument);
                }
            }
            syn::Expr::MethodCall(call) => {
                self.visit_expr(&call.receiver);
                for argument in &call.args {
                    self.visit_expr(argument);
                }
            }
            syn::Expr::Struct(literal) => {
                for field in &literal.fields {
                    self.visit_expr(&field.expr);
                }
                if let Some(rest) = &literal.rest {
                    self.visit_expr(rest);
                }
            }
            syn::Expr::Macro(mac) => self.visit_macro(&mac.mac),
            _ => {}
        }
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

    /// Note each configured member of a trait mock that another trait in
    /// scope may take the call of (see [`TraitRivals`]), when a value would
    /// otherwise be told.
    fn settle_trait_rivals(&mut self) {
        let handles = &self.found.handles;
        let rivalled: Vec<(String, String)> = handles
            .chains
            .iter()
            .filter(|(_, chains)| {
                matches!(chains.as_slice(), [chain] if matches!(chain.values.as_slice(), [Some(_)]))
            })
            .filter_map(|((handle, member), _)| {
                let index = handles
                    .member_doubles
                    .get(&(handle.clone(), member.clone()))
                    .or_else(|| handles.bound.get(handle))?;
                let mock = self.trait_mocks.get(index)?;
                let mocked = mock
                    .members
                    .as_ref()
                    .is_none_or(|members| members.contains(member));
                (mocked && self.site.trait_rivals.rival(&mock.traits, member))
                    .then(|| (handle.clone(), member.clone()))
            })
            .collect();
        self.found.handles.rivalled.extend(rivalled);
    }

    /// Settle the static calls and the fakes, and hand over.
    fn finish(mut self) -> FunctionDoubles {
        self.settle_trait_rivals();
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
        for (index, path) in std::mem::take(&mut self.invoked_by) {
            let calls = self
                .path_calls
                .iter()
                .filter(|called| **called == path)
                .count();
            if let Some(double) = self.found.doubles.get_mut(index) {
                double.invocations += calls;
            }
        }
        let fakes: Vec<DoubleFact> = self.found.doubles.drain(..self.fake_slots.len()).collect();
        for (fake, double) in self.fake_slots.iter().zip(fakes) {
            *self.found.fake_invocations.entry(fake.clone()).or_default() += double.invocations;
        }
        self.found
    }
}
