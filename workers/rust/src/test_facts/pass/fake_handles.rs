//! The handles of a function: a local bound to a value of one of the
//! file's fakes (Decision 4), and mockers' pair of a mock and the handle
//! that configures it.

use syn::visit::Visit;

use super::Pass;
use crate::resolve::ident_name;
use crate::test_facts::doubles::DoubleFact;
use crate::test_facts::fakes::Fake;
use crate::test_facts::frameworks::Framework;
use crate::test_facts::handles::Chain;

impl<'p> Pass<'p> {
    /// Bind the file's fakes the function's module declares: each fake's
    /// doubles take the first positions, and a unit fake's own name is a
    /// handle.
    pub(super) fn bind_fakes(&mut self) {
        let site = self.site;
        for fake in site.fakes {
            if fake.container != site.container || self.hidden.contains(&fake.name) {
                continue;
            }
            let first = self.found.doubles.len();
            for implemented in &fake.impls {
                let fact = DoubleFact {
                    canonical: implemented.fact.canonical.clone(),
                    mock: fake.canonical.clone(),
                    span: implemented.fact.span,
                    framework: Framework::HandWritten.name(),
                    pattern: implemented.fact.pattern,
                    target: None,
                    target_kind: "class",
                    members: Vec::new(),
                    invocations: 0,
                    test_case: None,
                    module: fake.container.clone(),
                };
                self.add(fact, Framework::HandWritten);
                self.fake_slots.push(fake.canonical.clone());
            }
            self.local_fakes.push((fake, first));
            if fake.unit {
                self.found.handles.unit_handles.insert(fake.name.clone());
                self.bind_fake(&fake.name, fake, first);
            }
        }
    }

    /// Bind `name` as a handle of `fake`, whose first double is at `first`:
    /// each member answers with the literal it pins, if any.
    pub(super) fn bind_fake(&mut self, name: &str, fake: &Fake, first: usize) {
        let handles = &mut self.found.handles;
        handles.bound.insert(name.to_owned(), first);
        for (offset, implemented) in fake.impls.iter().enumerate() {
            for (member, literal) in &implemented.members {
                let key = (name.to_owned(), member.clone());
                handles.chains.entry(key.clone()).or_default().push(Chain {
                    top_level: true,
                    at: (0, 0),
                    values: vec![literal.clone()],
                });
                handles.member_doubles.insert(key, first + offset);
            }
        }
    }

    /// The fake `expr` makes a value of, with the position of its first
    /// double: `Fake` (a unit struct), `Fake { … }`, or `Fake::new()` or
    /// `Fake::default()` when the file declares that it makes one.
    pub(super) fn fake_value(&self, expr: &syn::Expr) -> Option<(&'p Fake, usize)> {
        let found = |name: &syn::Ident, wants: &dyn Fn(&Fake) -> bool| {
            self.local_fakes
                .iter()
                .find(|(fake, _)| *name == fake.name && wants(fake))
                .copied()
        };
        match expr {
            // A qualified `<T>::Fake` keeps its `::` as a leading colon: no ident.
            syn::Expr::Path(path) => found(path.path.get_ident()?, &|fake| fake.unit),
            syn::Expr::Struct(literal) => found(literal.path.get_ident()?, &|_| true),
            syn::Expr::Call(call) => {
                let syn::Expr::Path(func) = call.func.as_ref() else {
                    return None;
                };
                let [owner, function] = func.path.segments.iter().collect::<Vec<_>>()[..] else {
                    return None;
                };
                if func.qself.is_some()
                    || func.path.leading_colon.is_some()
                    || !owner.arguments.is_none()
                    || !function.arguments.is_none()
                {
                    return None;
                }
                found(&owner.ident, &|fake| {
                    fake.constructors.iter().any(|name| function.ident == name)
                })
            }
            _ => None,
        }
    }

    /// Read a `let` that binds a value of a fake, or else any `let`.
    pub(super) fn visit_fake_local(&mut self, node: &syn::Local) {
        let value = node
            .init
            .as_ref()
            .filter(|init| init.diverge.is_none())
            .and_then(|init| self.fake_value(&init.expr));
        let Some((fake, first)) = value else {
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
                self.bind_fake(&ident_name(&ident.ident), fake, first);
            }
            // Bound to no one name: handed on.
            _ => self.invoke(first),
        }
        if let Some(init) = &node.init {
            self.visit_made_arguments(&init.expr);
        }
    }

    /// Bind mockers' `let (mock, handle) = scenario.create_mock…();`: the
    /// mock is the double's handle, the handle configures it.
    pub(super) fn bind_pair(&mut self, tuple: &syn::PatTuple, index: usize) {
        let names: Vec<Option<String>> = tuple
            .elems
            .iter()
            .map(|element| match element {
                syn::Pat::Ident(ident) if ident.by_ref.is_none() && ident.subpat.is_none() => {
                    Some(ident_name(&ident.ident))
                }
                _ => None,
            })
            .collect();
        let [mock, Some(handle)] = names.as_slice() else {
            self.invoke(index);
            return;
        };
        if let Some(mock) = mock {
            self.found.handles.bound.insert(mock.clone(), index);
        }
        self.found
            .handles
            .config_of
            .insert(handle.clone(), (index, mock.clone()));
    }
}
