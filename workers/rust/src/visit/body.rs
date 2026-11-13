//! The `syn` visitor over a body: each expression, pattern and statement
//! the walk resolves calls, references and receiver types in.

use std::collections::BTreeMap;

use syn::spanned::Spanned;
use syn::visit::Visit;
use syn::Type;

use super::receivers::dispatch;
use super::state::{Calls, Walk};
use super::types::ALTERNATIVE_MARK;
use crate::facts::reference;
use crate::resolve::ident_name;

impl syn::visit::Visit<'_> for Calls<'_, '_> {
    fn visit_path(&mut self, node: &syn::Path) {
        self.inline_import(node, node.span());
        syn::visit::visit_path(self, node);
    }

    fn visit_expr_call(&mut self, node: &syn::ExprCall) {
        if let syn::Expr::Path(path) = node.func.as_ref() {
            self.command_program(node);
            self.inline_import(&path.path, node.span());
            self.visit_call(&path.path, node.span());
            // `Action::Deny(x)` builds a variant: the enum is used. The callee
            // itself is not walked as a value path, since it is the call, but
            // the types its turbofish names are used (`size_of::<Info>()`,
            // `Option::<Reference>::deserialize(..)`).
            self.owner_reference(&path.path, node.span());
            if let Some(qself) = &path.qself {
                self.visit_type(&qself.ty);
            }
            for segment in &path.path.segments {
                self.visit_path_arguments(&segment.arguments);
            }
            for argument in &node.args {
                self.visit_expr(argument);
            }
            return;
        }
        syn::visit::visit_expr_call(self, node);
    }

    fn visit_expr_path(&mut self, node: &syn::ExprPath) {
        if node.qself.is_none() {
            self.owner_reference(&node.path, node.span());
            self.value_reference(&node.path, node.span());
        }
        syn::visit::visit_expr_path(self, node);
    }

    fn visit_type_path(&mut self, node: &syn::TypePath) {
        if node.qself.is_none() {
            self.type_reference(&node.path, node.span());
        }
        syn::visit::visit_type_path(self, node);
    }

    fn visit_receiver(&mut self, node: &syn::Receiver) {
        // The shorthand receivers `self`, `mut self`, `&self` and `&mut self`
        // carry no written type, but each names `Self` the way `self: Self`
        // does: that reference is recorded at the `self` token, as for a
        // typed receiver, whose type the default walk visits.
        if !matches!(node.kind, syn::ReceiverKind::Typed(..)) {
            let implied = syn::TypePath {
                attrs: Vec::new(),
                qself: None,
                path: syn::Path::from(syn::Ident::new("Self", node.self_token.span)),
            };
            self.visit_type_path(&implied);
        }
        syn::visit::visit_receiver(self, node);
    }

    fn visit_trait_bound(&mut self, node: &syn::TraitBound) {
        self.type_reference(&node.path, node.span());
        syn::visit::visit_trait_bound(self, node);
    }

    fn visit_pat_tuple_struct(&mut self, node: &syn::PatTupleStruct) {
        if node.qself.is_none() {
            self.owner_reference(&node.path, node.span());
            self.type_reference(&node.path, node.span());
        }
        syn::visit::visit_pat_tuple_struct(self, node);
    }

    fn visit_pat_struct(&mut self, node: &syn::PatStruct) {
        if node.qself.is_none() {
            self.owner_reference(&node.path, node.span());
            self.type_reference(&node.path, node.span());
        }
        syn::visit::visit_pat_struct(self, node);
    }

    fn visit_macro(&mut self, node: &syn::Macro) {
        self.cargo_binary(node);
        // A macro body is tokens to syn. Most invocations in ordinary code
        // (`format!`, `println!`, `assert_eq!`, `write!`) take comma-separated
        // expressions, so a body that parses as such is walked like any other
        // arguments. `matches!(value, pattern if guard)` is read as what it
        // is. Whatever is left (`vec![f(x); n]`, a guard in another macro, a
        // DSL) is searched for paths written as calls, which are `possible`.
        if node
            .path
            .segments
            .last()
            .is_some_and(|segment| segment.ident == "matches")
            && self.visit_matches(node.tokens.clone())
        {
            return;
        }
        if !self.visit_macro_tokens(node.tokens.clone()) {
            self.visit_token_calls(node.tokens.clone());
        }
    }

    fn visit_expr_struct(&mut self, node: &syn::ExprStruct) {
        for (target, unconfirmed) in self.walk.resolve_paths(&self.container, &node.path) {
            let endpoint = reference("class", &target);
            if unconfirmed {
                self.walk.facts.conditional_edge(
                    &self.enclosing,
                    &endpoint,
                    syn::spanned::Spanned::span(node),
                );
            } else {
                self.walk.facts.edge(
                    "calls",
                    &self.enclosing,
                    &endpoint,
                    "probable",
                    syn::spanned::Spanned::span(node),
                );
            }
        }
        syn::visit::visit_expr_struct(self, node);
    }

    fn visit_expr_method_call(&mut self, node: &syn::ExprMethodCall) {
        self.command_argument(node);
        // A `route(...)` method call is how axum and actix declare routes.
        if node.method == "route" {
            self.call_routes(node);
        }
        // A call on what another call returns: `s.mode().label()` or
        // `State::new().mode()`. The inner call's owner is known here; its
        // return type may be declared in another file, so the member is named
        // through the call and the core resolves it, or drops the edge.
        let method = ident_name(&node.method);
        let returning = self.returning_call(&node.receiver);
        // Any other receiver whose type the walk can state: a binding, a
        // field, or what `open(root)?` evaluates to.
        let owner = if returning.is_none() {
            self.receiver_owner(&node.receiver)
                .and_then(|owner| dispatch(&owner, &method))
        } else {
            None
        };
        if let Some(callee) = &returning {
            let endpoint = reference("method_of_return", &format!("{callee}::{method}"));
            self.walk.facts.edge(
                "calls",
                &self.enclosing,
                &endpoint,
                "probable",
                node.method.span(),
            );
        }
        if let Some(target) = owner {
            // A field declared once per `cfg` holds one of several types.
            for target in target.split(ALTERNATIVE_MARK) {
                let endpoint = reference("method", &format!("{target}::{method}"));
                self.walk.facts.speculative_edge(
                    "calls",
                    &self.enclosing,
                    &endpoint,
                    node.method.span(),
                );
            }
        } else if returning.is_none() {
            self.walk.facts.untyped_call(method);
        }
        self.mount_router(node);
        // A `nest(...)` or scoped `service(...)` serves the routes its
        // arguments declare under its prefix; the receiver stays outside it.
        if let Some(prefix) = self.route_prefix(node) {
            self.visit_expr(&node.receiver);
            self.prefixes.push(prefix);
            for argument in &node.args {
                self.visit_expr(argument);
            }
            self.prefixes.pop();
            return;
        }
        if let Some((position, element)) = self.closure_argument(node) {
            for attr in &node.attrs {
                self.visit_attribute(attr);
            }
            self.visit_expr(&node.receiver);
            if let Some(turbofish) = &node.turbofish {
                self.visit_angle_bracketed_generic_arguments(turbofish);
            }
            for (index, argument) in node.args.iter().enumerate() {
                if index == position && matches!(argument, syn::Expr::Closure(_)) {
                    self.closure_element = Some(element.clone());
                }
                self.visit_expr(argument);
                self.closure_element = None;
            }
            return;
        }
        syn::visit::visit_expr_method_call(self, node);
    }

    fn visit_local(&mut self, node: &syn::Local) {
        // The initializer is walked first: it runs before the binding exists,
        // so `let p = p.clone()` resolves its receiver through the old `p`.
        syn::visit::visit_local(self, node);
        let (ident, annotation) = match &node.pat {
            syn::Pat::Ident(ident) if ident.subpat.is_none() => (ident_name(&ident.ident), None),
            syn::Pat::Type(typed) => match typed.pat.as_ref() {
                syn::Pat::Ident(ident) if ident.subpat.is_none() => {
                    (ident_name(&ident.ident), Some(typed.ty.as_ref()))
                }
                _ => {
                    self.bind_pattern(&node.pat);
                    return;
                }
            },
            // `let Some(store) = open(root) else { .. };`, `let Index { store, .. } = x;`
            pattern => {
                let held = node
                    .init
                    .as_ref()
                    .and_then(|init| self.receiver_owner(&init.expr));
                self.bind_against(pattern, held);
                if let (syn::Pat::Tuple(names), Some(init)) = (pattern, &node.init) {
                    self.bind_tuple(names, &init.expr);
                }
                return;
            }
        };
        let stated = match annotation {
            Some(ty) => self.walk.receiver_type(&self.container, ty),
            None => node
                .init
                .as_ref()
                .and_then(|init| self.value_type(&init.expr)),
        };
        // A rebinding of unknown type shadows the old one, so its type is forgotten.
        match stated {
            Some(target) => {
                self.receivers.insert(ident, target);
            }
            None => {
                self.receivers.remove(&ident);
            }
        }
    }

    fn visit_expr_closure(&mut self, node: &syn::ExprClosure) {
        // A closure parameter shadows any outer binding of the same name, and
        // says nothing about its own type, so it is forgotten while inside.
        // A parameter whose type is written (`|s: &Store|`) is typed.
        // A closure handed to an iterator or `Option` adapter takes what the
        // adapter yields (`values.iter().any(|v| v.valid())`): see
        // [`Calls::closure_element`].
        let saved = self.receivers.clone();
        let mut element = self.closure_element.take();
        for input in &node.inputs {
            self.bind_against(input, element.take());
        }
        syn::visit::visit_expr_closure(self, node);
        self.receivers = saved;
    }

    fn visit_block(&mut self, node: &syn::Block) {
        // A `let` in a block, a loop body or an `unsafe` block ends with it,
        // and so does what a `use` in it imports.
        let saved = self.receivers.clone();
        let outer = self.walk.enter_block_uses(&self.container, &node.stmts);
        self.visit_statements(&node.stmts);
        self.walk.leave_block_uses(outer);
        self.receivers = saved;
    }

    fn visit_arm(&mut self, node: &syn::Arm) {
        // An arm's bindings live for the arm only, typed against the value
        // matched when its type is known (`Some(store) => store.load()`).
        let saved = self.receivers.clone();
        let held = self.scrutinee.take();
        self.bind_against(&node.pat, held);
        syn::visit::visit_arm(self, node);
        self.receivers = saved;
    }

    fn visit_expr_match(&mut self, node: &syn::ExprMatch) {
        for attr in &node.attrs {
            self.visit_attribute(attr);
        }
        self.visit_expr(&node.expr);
        let held = self.receiver_owner(&node.expr);
        for arm in &node.arms {
            self.scrutinee.clone_from(&held);
            self.visit_arm(arm);
        }
        self.scrutinee = None;
    }

    fn visit_expr_if(&mut self, node: &syn::ExprIf) {
        // `if let` binds for the `then` block only, not the `else` branch.
        let saved = self.receivers.clone();
        self.visit_condition(&node.cond);
        self.visit_block(&node.then_branch);
        self.receivers = saved;
        if let Some((_, otherwise)) = &node.else_branch {
            self.visit_expr(otherwise);
        }
    }

    fn visit_expr_while(&mut self, node: &syn::ExprWhile) {
        let saved = self.receivers.clone();
        self.visit_condition(&node.cond);
        self.visit_block(&node.body);
        self.receivers = saved;
    }

    fn visit_expr_for_loop(&mut self, node: &syn::ExprForLoop) {
        self.visit_expr(&node.expr);
        let saved = self.receivers.clone();
        self.visit_pat(&node.pat);
        // `for tile in &tiles` binds each element of a collection.
        let element = self.iterated_element(&node.expr);
        self.bind_against(&node.pat, element);
        self.visit_block(&node.body);
        self.receivers = saved;
    }
}

impl Walk<'_> {
    /// Emit a `calls` edge for every resolvable call in one function body.
    ///
    /// `enclosing` is the full `rust:<kind>:<canonical>` reference of the
    /// function or method whose block this is, exactly as `declare` returned
    /// it wrapped in [`reference`](fn@reference) — its kind is already known for certain at
    /// the call site, unlike a call target's, so it is never guessed.
    /// `container` is the module `enclosing` itself is declared in (the same
    /// value `walk_item` was called with, never an `impl` block's type path),
    /// and is what an unqualified call inside the body resolves against —
    /// see [`Walk::path_target`].
    ///
    /// Only `Expr::Call` with a path callee resolves. A method call
    /// (`value.run()`) names no type the worker can see, and is dropped. A
    /// multi-segment callee the source roots explicitly (`crate::helper()`,
    /// `self::go()`) or heads with an imported name (`http::get()`) is trusted
    /// as [`Walk::resolve_path`] resolves it, same as any other path in this
    /// worker. A callee that is neither — `helper()`, `String::from()`,
    /// `<Widget>::default()`, `self()` — cannot be vouched for on sight, and is
    /// emitted solely when it lands on a declaration this same file makes; see
    /// [`Calls::visit_call`] and `Facts::conditional_edge`. Both branches match
    /// the Python worker's principle: an unresolved target produces no edge
    /// instead of a wrong one.
    ///
    /// `signature` seeds the receiver types a method call can resolve through:
    /// `self` names the `impl` block's type, and each parameter its declared
    /// type. See [`Calls::visit_expr_method_call`].
    pub(super) fn walk_body(
        &mut self,
        enclosing: &str,
        container: &str,
        signature: &syn::Signature,
        block: &syn::Block,
    ) {
        let receivers = self.signature_receivers(container, signature);
        let receiver_arguments = self.signature_arguments(container, signature, &receivers);
        let mut visitor = Calls {
            walk: self,
            enclosing: enclosing.to_owned(),
            container: container.to_owned(),
            receivers,
            receiver_arguments,
            prefixes: Vec::new(),
            scrutinee: None,
            closure_element: None,
            replaying: 0,
        };
        visitor.visit_signature(signature);
        let outer = visitor.walk.enter_block_uses(container, &block.stmts);
        visitor.visit_statements(&block.stmts);
        visitor.walk.leave_block_uses(outer);
    }

    /// Walk what a type declaration names outside any body: its fields'
    /// types, and the functions serde attributes name by string. `enclosing`
    /// is the struct, enum or union itself.
    pub(super) fn walk_type_declaration(
        &mut self,
        enclosing: &str,
        container: &str,
        attrs: &[syn::Attribute],
        fields: &[&syn::Fields],
        field_attrs: &[&[syn::Attribute]],
    ) {
        let mut visitor = Calls {
            walk: self,
            enclosing: enclosing.to_owned(),
            container: container.to_owned(),
            receivers: BTreeMap::new(),
            receiver_arguments: BTreeMap::new(),
            prefixes: Vec::new(),
            scrutinee: None,
            closure_element: None,
            replaying: 0,
        };
        visitor.serde_references(attrs);
        for fields in fields {
            visitor.visit_fields(fields);
            for field in fields.iter() {
                visitor.serde_references(&field.attrs);
            }
        }
        for attrs in field_attrs {
            visitor.serde_references(attrs);
        }
    }

    /// Walk a `macro_rules!` definition's body for the calls its expansion
    /// makes, on behalf of the module that defines it: each path written as
    /// a call (`valid_text(stringify!($name), value)`) is a `possible` call,
    /// resolved where the macro is defined, since the body is tokens that
    /// only an expansion turns into code.
    pub(super) fn walk_macro_rules(
        &mut self,
        container: &str,
        container_kind: &str,
        tokens: &proc_macro2::TokenStream,
    ) {
        if container_kind != "module" {
            return;
        }
        let mut visitor = Calls {
            walk: self,
            enclosing: reference("module", container),
            container: container.to_owned(),
            receivers: BTreeMap::new(),
            receiver_arguments: BTreeMap::new(),
            prefixes: Vec::new(),
            scrutinee: None,
            closure_element: None,
            replaying: 0,
        };
        visitor.visit_token_calls(tokens.clone());
    }

    /// Walk a module-level `const` or `static`: its type and its initializer,
    /// whose calls run on behalf of the module that declares it.
    pub(super) fn walk_module_value(&mut self, container: &str, ty: &Type, expr: &syn::Expr) {
        let mut visitor = Calls {
            walk: self,
            enclosing: reference("module", container),
            container: container.to_owned(),
            receivers: BTreeMap::new(),
            receiver_arguments: BTreeMap::new(),
            prefixes: Vec::new(),
            scrutinee: None,
            closure_element: None,
            replaying: 0,
        };
        visitor.visit_type(ty);
        visitor.visit_expr(expr);
    }
}
