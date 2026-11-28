//! The configuration chains of one function: on a handle, on a macro
//! (`faux::when!(h.m)`), on a static mock (`Cat::mock_meow(…)`), a mocked
//! function's (`f.mock_safe(…)`), and galvanic's `given!` clauses.

use std::collections::BTreeSet;

use syn::spanned::Spanned;
use syn::visit::Visit;

use super::Pass;
use crate::resolve::ident_name;
use crate::test_facts::doubles::Pin;
use crate::test_facts::frameworks::{faux, galvanic, mock_derive, mocktopus, mry, Framework, Role};
use crate::test_facts::handles::{handle_name, start, Chain};
use crate::test_facts::mockall;
use crate::test_facts::syntax::returned_literal;

impl Pass<'_> {
    /// Read a method-call chain rooted at the handle `handle`: `calls` runs
    /// from the outermost call to the innermost, the one on the handle.
    pub(super) fn chain(&mut self, handle: &str, calls: &[&syn::ExprMethodCall]) {
        let Some(innermost) = calls.last() else {
            return;
        };
        let method = ident_name(&innermost.method);
        let line = innermost.method.span().start().line;
        let at = start(&innermost.method);
        let end = innermost.span().end().line;
        let verbs = &calls[..calls.len() - 1];
        if let Some((index, mock)) = self.found.handles.config_of.get(handle).cloned() {
            let key = mock.as_deref();
            self.configure(index, key, (method, "expect"), (line, at, end), verbs, true);
            return;
        }
        let Some(index) = self.found.handles.bound.get(handle).copied() else {
            return;
        };
        let framework = self.kinds[index];
        if framework == Framework::Mockall {
            self.mockall_chain(handle, index, &method, (line, at, end), verbs);
            return;
        }
        match framework.role(&method) {
            Role::Member(member, api) => {
                let answers = framework != Framework::Mry
                    || mry::matches_any(self.site.frameworks, self.site.container, innermost);
                self.configure(
                    index,
                    Some(handle),
                    (member, api),
                    (line, at, end),
                    verbs,
                    answers,
                );
            }
            Role::Install => {}
            Role::Call => self.call(handle, index, method),
        }
    }

    /// Count the call `method` on the handle `handle` of the double at
    /// `index`.
    pub(super) fn call(&mut self, handle: &str, index: usize, method: String) {
        self.invoke(index);
        let called = self.found.handles.called.entry(handle.to_owned());
        called.or_default().insert(method);
    }

    /// Read a chain on a mockall handle (Decision 3).
    pub(super) fn mockall_chain(
        &mut self,
        handle: &str,
        index: usize,
        method: &str,
        (line, at, end): (usize, (usize, usize), usize),
        verbs: &[&syn::ExprMethodCall],
    ) {
        let context = self.found.doubles[index]
            .members
            .first()
            .filter(|member| member.api == "context")
            .map(|member| member.name.clone());
        let Some(member) = mockall::member(context.as_deref(), method) else {
            if method == "checkpoint" {
                self.found.handles.defeated.insert(handle.to_owned());
            } else {
                self.call(handle, index, method.to_owned());
            }
            return;
        };
        // A static context's expectations are shared by every test: it
        // gives identity only, never a pin.
        let pins = context.is_none();
        let pinned = self.configure(
            index,
            Some(handle),
            (member, "expect"),
            (line, at, end),
            verbs,
            true,
        );
        if !pins {
            if let Some(configured) = self.found.doubles[index].members.get_mut(pinned) {
                configured.returns.clear();
            }
        }
    }

    /// Record the configuration of `member` (name, api) of the double at
    /// `index`, made at `line` and `at` by the chain `verbs` (outermost
    /// first); a chain of the handle `key` when the double has one, whose
    /// pinned literal may be its value when `answers`. Returns the member's
    /// position.
    pub(super) fn configure(
        &mut self,
        index: usize,
        key: Option<&str>,
        (member, api): (String, &'static str),
        (line, at, end): (usize, (usize, usize), usize),
        verbs: &[&syn::ExprMethodCall],
        answers: bool,
    ) -> usize {
        let framework = self.kinds[index];
        let table = match framework {
            Framework::Mockall => &mockall::VERBS,
            other => other.verbs(),
        };
        let mut values = Vec::new();
        let mut pins = Vec::new();
        let mut asserts = 0;
        for call in verbs.iter().rev() {
            let name = ident_name(&call.method);
            let verb_line = call.method.span().start().line;
            if let Some(verb) = table.values.iter().find(|verb| verb.name == name) {
                let told = table.answers && answers && verb.pins;
                values.push(
                    call.args
                        .first()
                        .and_then(returned_literal)
                        .filter(|_| told),
                );
                if verb.pins {
                    pins.push(Pin {
                        api: name,
                        line: verb_line,
                        once: verb.once,
                    });
                }
            } else if table.times.contains(&name.as_str()) {
                let double = self.found.doubles[index].canonical.clone();
                self.found.times.push((verb_line, double));
                asserts += 1;
            }
        }
        let configured = self.found.doubles[index].member(&member, api, line);
        configured.returns.extend(pins);
        let position = self.found.doubles[index]
            .members
            .iter()
            .position(|known| known.name == member)
            .unwrap_or_default();
        let outer = verbs.first().map_or(end, |call| call.span().end().line);
        self.found.handles.config_lines.push((line, outer));
        // mry's `assert_called` only reads what was called.
        let reads_only = framework == Framework::Mry && values.is_empty() && asserts > 0;
        if let (Some(key), false) = (key, reads_only) {
            self.found
                .handles
                .chains
                .entry((key.to_owned(), member))
                .or_default()
                .push(Chain {
                    top_level: self.top_level.contains(&at),
                    at,
                    values,
                });
        }
        position
    }

    /// The static mock a call `call` configures, made once per function.
    pub(super) fn static_mock(&mut self, call: &syn::ExprCall) -> Option<usize> {
        let syn::Expr::Path(func) = call.func.as_ref() else {
            return None;
        };
        if !self.visible(&func.path) {
            return None;
        }
        let context = self.site.frameworks;
        let container = self.site.container;
        let made = mry::static_mock(context, container, call)
            .or_else(|| mock_derive::static_mock(context, container, call))?;
        let (member, _) = made.member.clone()?;
        self.found.handles.static_members.insert(member.clone());
        let key = (made.mock.clone(), member);
        if let Some(index) = self.static_made.get(&key) {
            return Some(*index);
        }
        let index = self.make(call.span(), made);
        self.static_made.insert(key, index);
        self.found.handles.made.insert(start(&call.func), index);
        Some(index)
    }

    /// Read a chain `calls` made on a static mock's configuration `call`
    /// (`Cat::mock_meow(…).returns(…)`): identity and pins only, no handle.
    pub(super) fn static_chain(
        &mut self,
        call: &syn::ExprCall,
        calls: &[&syn::ExprMethodCall],
    ) -> bool {
        let Some(index) = self.static_mock(call) else {
            return false;
        };
        let Some((member, api)) = self.found.doubles[index]
            .members
            .first()
            .map(|member| (member.name.clone(), member.api))
        else {
            return false;
        };
        let line = call.func.span().start().line;
        self.configure(
            index,
            None,
            (member, api),
            (line, start(&call.func), line),
            calls,
            false,
        );
        for argument in &call.args {
            self.visit_expr(argument);
        }
        true
    }

    /// Read a chain `calls` made on the macro `mac`, when it is
    /// `faux::when!(h.m)` of a faux handle `h`.
    pub(super) fn faux_chain(&mut self, mac: &syn::Macro, calls: &[&syn::ExprMethodCall]) -> bool {
        if !self.visible(&mac.path) {
            return false;
        }
        let Some((handle, member)) =
            faux::configured(self.site.frameworks, self.site.container, mac)
        else {
            return false;
        };
        let Some(index) = self.found.handles.bound.get(&handle).copied() else {
            return false;
        };
        if self.kinds[index] != Framework::Faux {
            return false;
        }
        let at = start(&mac.path);
        let line = mac.path.span().start().line;
        self.configure(
            index,
            Some(&handle),
            (member, "when"),
            (line, at, line),
            calls,
            true,
        );
        self.found.handles.made.insert(at, index);
        true
    }

    /// Make the double of `f.mock_safe(…)` (or `mock_raw`), a mocked
    /// function's, when `node` is one.
    pub(super) fn mocked_function(&mut self, node: &syn::ExprMethodCall) -> bool {
        let syn::Expr::Path(receiver) = node.receiver.as_ref() else {
            return false;
        };
        let local =
            handle_name(receiver).is_some_and(|name| self.found.handles.bound.contains_key(&name));
        if local || !self.visible(&receiver.path) {
            return false;
        }
        let Some((made, pins)) = mocktopus::made(node) else {
            return false;
        };
        let index = self.make(node.span(), made);
        if let Some(member) = self.found.doubles[index].members.first_mut() {
            member.returns.extend(pins);
        }
        self.found.handles.made.insert(start(receiver), index);
        let span = node.span();
        self.found
            .handles
            .config_lines
            .push((span.start().line, span.end().line));
        for argument in &node.args {
            self.visit_expr(argument);
        }
        true
    }

    /// Read a galvanic `given!` or `expect_interactions!` block: its members,
    /// pins and `times` assertions. Returns the handles it configures.
    pub(super) fn galvanic(&mut self, mac: &syn::Macro) -> BTreeSet<String> {
        let mut configured = BTreeSet::new();
        let Some(clauses) = galvanic::clauses(mac) else {
            return configured;
        };
        for clause in clauses {
            let Some(index) = self.found.handles.bound.get(&clause.handle).copied() else {
                continue;
            };
            if self.kinds[index] != Framework::Galvanic {
                continue;
            }
            if clause.times {
                let double = self.found.doubles[index].canonical.clone();
                self.found.times.push((clause.line, double));
            } else {
                let member = self.found.doubles[index].member(&clause.member, "given", clause.line);
                member.returns.extend(clause.pin.map(|line| Pin {
                    api: "then_return".to_owned(),
                    line,
                    once: false,
                }));
                let key = (clause.handle.clone(), clause.member);
                self.found
                    .handles
                    .chains
                    .entry(key)
                    .or_default()
                    .push(Chain {
                        top_level: false,
                        at: (clause.line, 0),
                        values: vec![None],
                    });
            }
            configured.insert(clause.handle);
        }
        let span = mac.span();
        self.found
            .handles
            .config_lines
            .push((span.start().line, span.end().line));
        configured
    }
}
