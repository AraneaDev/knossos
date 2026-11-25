//! The walk of one function's body (see [`super`]).

use std::collections::BTreeSet;

use syn::visit::Visit;

use super::Pass;
use crate::resolve::ident_name;
use crate::test_facts::assertions::arguments;
use crate::test_facts::frameworks::{self, Framework};
use crate::test_facts::handles::{handle_name, receiver_handle, start};
use crate::test_facts::mockall::MockCall;
use crate::test_facts::syntax::{identifiers, strip_parens, Named};

impl<'ast> Visit<'ast> for Pass<'_> {
    fn visit_item(&mut self, node: &'ast syn::Item) {
        // A nested item is a function of its own; but a macro defined or
        // invoked where an item stands (`macro_rules! setup`) may name
        // any handle of the function, hygiene notwithstanding.
        if let syn::Item::Macro(item) = node {
            self.found.handles.opaque = true;
            self.galvanic(&item.mac);
        }
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
            self.visit_fake_local(node);
            return;
        };
        self.visit_pat(&node.pat);
        let pattern = match &node.pat {
            syn::Pat::Type(typed) => typed.pat.as_ref(),
            other => other,
        };
        match (pattern, self.kinds[index]) {
            (syn::Pat::Ident(ident), _) if ident.by_ref.is_none() && ident.subpat.is_none() => {
                self.found
                    .handles
                    .bound
                    .insert(ident_name(&ident.ident), index);
            }
            (syn::Pat::Tuple(tuple), Framework::Mockers) => self.bind_pair(tuple, index),
            // Bound to no one name: handed on.
            _ => self.invoke(index),
        }
        if let Some(init) = &node.init {
            self.visit_made_arguments(&init.expr);
        }
    }

    fn visit_expr_call(&mut self, node: &'ast syn::ExprCall) {
        let whole = syn::Expr::Call(node.clone());
        if let syn::Expr::Path(func) = node.func.as_ref() {
            if func.qself.is_none() {
                self.path_calls.push(frameworks::written(&func.path));
            }
        }
        if let Some(index) = self.constructed(&whole) {
            // Made and not bound: handed on where it stands.
            self.invoke(index);
        } else if let Some(MockCall::Static(mock, member)) = self.classify(&node.func) {
            self.statics.push((start(&node.func), mock, member));
        } else if let Some((_, first)) = self.fake_value(&whole) {
            self.invoke(first);
        } else {
            self.static_mock(node);
        }
        for argument in &node.args {
            self.visit_expr(argument);
        }
    }

    fn visit_expr_struct(&mut self, node: &'ast syn::ExprStruct) {
        let whole = syn::Expr::Struct(node.clone());
        if let Some(index) = self.constructed(&whole) {
            self.invoke(index);
        } else if let Some((_, first)) = self.fake_value(&whole) {
            self.invoke(first);
        }
        syn::visit::visit_expr_struct(self, node);
    }

    fn visit_expr_method_call(&mut self, node: &'ast syn::ExprMethodCall) {
        if self.mocked_function(node) {
            return;
        }
        let mut calls = vec![node];
        let mut receiver = node.receiver.as_ref();
        while let syn::Expr::MethodCall(inner) = strip_parens(receiver) {
            calls.push(inner);
            receiver = inner.receiver.as_ref();
        }
        let handles = &self.found.handles;
        let handle = receiver_handle(receiver).filter(|name| {
            handles.bound.contains_key(name) || handles.config_of.contains_key(name)
        });
        let configured = match (handle, strip_parens(receiver)) {
            (Some(handle), _) => {
                self.chain(&handle, &calls);
                true
            }
            (None, syn::Expr::Macro(mac)) => self.faux_chain(&mac.mac, &calls),
            (None, syn::Expr::Call(call)) => self.static_chain(call, &calls),
            _ => false,
        };
        if !configured {
            // Only mockers' `scenario.create_mock::<…>()` makes a double here.
            if node.turbofish.is_some() {
                if let Some(index) = self.constructed(&syn::Expr::MethodCall(node.clone())) {
                    self.invoke(index);
                }
            }
            syn::visit::visit_expr_method_call(self, node);
            return;
        }
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
        let configured = self.galvanic(node);
        let mut named = BTreeSet::new();
        identifiers(node.tokens.clone(), &mut named);
        self.found.handles.unsafe_code |= named.contains("unsafe");
        let handed: Vec<String> = named
            .into_iter()
            .filter(|name| self.found.handles.bound.contains_key(name))
            .collect();
        for name in handed {
            if configured.contains(&name) {
                self.found.handles.defeated.insert(name);
            } else {
                self.hand_on(&name, true);
            }
        }
    }
}
