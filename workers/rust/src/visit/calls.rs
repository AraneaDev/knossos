//! What a body names: calls, references to types and functions, and the
//! receiver types a method call resolves through.
//!
//! Function bodies, field types, module-level initializers and macro
//! arguments are all walked by the one `syn` visitor, `Calls`. A target the
//! source does not vouch for is deferred until this file's declarations are
//! known, or emitted as speculative, never invented.

use std::collections::BTreeMap;

use syn::spanned::Spanned;
use syn::visit::Visit;

use super::cfg::cfg_predicate;
use super::state::Calls;
use super::types::{
    element_of, pointee_of, unwrapped, ALTERNATIVE_MARK, ELEMENT_MARK, POINTER_MARK, WRAP_MARK,
};
use crate::facts::reference;
use crate::resolve::{ident_name, is_prelude_name};

impl Calls<'_, '_> {
    /// Resolve and emit a call, deferring the ones that need confirming.
    ///
    /// A callee path resolves through [`Walk::resolve_path`] like every other
    /// path this worker sees. What the flag it returns decides is whether the
    /// resulting edge can be trusted on the spot.
    ///
    /// A multi-segment path the source states outright — rooted at `crate`, at
    /// `::`, at `self`/`super`, or headed by a name the import map expands — is
    /// emitted immediately. Its target is where the author said it is, and an
    /// external target is a genuine external symbol.
    ///
    /// Everything else must be confirmed against this file's declarations
    /// first. That is a path whose head is neither rooted nor imported, and a
    /// single-segment callee, which `syn` can render as a rooted path without
    /// it naming one (see [`Walk::resolve_path`]). Rust makes an unrooted head
    /// legal in exactly two ways: the name was imported, or it is declared in
    /// this same file. Nothing else is callable that way. So such a target is
    /// real only when it lands on a declaration this contribution makes, which
    /// cannot be known yet — the declaration may come later in the file, since
    /// `walk_items` proceeds in source order — and is checked in
    /// `Facts::finish` through [`Facts::conditional_edge`].
    ///
    /// That covers a bare `helper()`, which is indistinguishable at the syntax
    /// level from a call through a local closure or function-pointer binding;
    /// a qualified head such as `String::from()` or `serde_json::to_value()`;
    /// and the two one-segment shapes, `<Widget>::default()` and `self()`.
    /// Trusting a qualified head used to attribute it to the enclosing module,
    /// so `String::from` in `crate::net::http` became
    /// `crate::net::http::String::from`: a `crate::`-rooted name no crate
    /// member ever declares, which the reconciler then materialised as a
    /// fabricated external node.
    ///
    /// The check confirms; it does not prove the negative. It drops every
    /// target this contribution cannot vouch for, and one legitimate shape
    /// falls with them: a call qualified by a *child* module, such as
    /// `sign::any_supported_type(&key_der)` under a `pub mod sign;` declared in
    /// the same file. A bare `mod foo;` emits only a containment edge and no
    /// node (see [`Walk::walk_mod`]), so the child module's node belongs to the
    /// contribution of the file that defines it, and the per-contribution
    /// `declared` set here cannot match it. That is a known false negative: a
    /// missing edge, never a wrong one.
    ///
    /// A callee named in UpperCamelCase builds a value rather than calling
    /// anything: `Wrapper(1)` and `Self(x)` construct a tuple struct, which
    /// they reference, and `Error::Io(e)` an enum variant, whose enum the
    /// owner reference in [`Calls::visit_expr_call`] already names. Neither
    /// is a function or a method, so neither becomes a `calls` edge.
    ///
    /// A trusted target inside this project (rooted at `crate`, this file's
    /// crate root or a workspace member) carries a kind guessed from its
    /// spelling ([`call_kind`]) that no declaration here confirmed, so its
    /// edge is speculative: kept when the graph declares it, dropped rather
    /// than invented as an external symbol when it does not. A target in
    /// another crate stays an ordinary edge, its external node the
    /// dependency's symbol.
    ///
    /// [`Walk::resolve_path`]: super::state::Walk::resolve_path
    /// [`Walk::walk_mod`]: super::state::Walk::walk_mod
    /// [`Facts::conditional_edge`]: crate::facts::Facts::conditional_edge
    fn visit_call(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        if builds_value(path) {
            if !names_variant(path) {
                self.type_reference(path, span);
            }
            return;
        }
        for (target, unconfirmed) in self.walk.resolve_paths(&self.container, path) {
            let target = if unconfirmed {
                target
            } else {
                self.walk.through_member_alias(target)
            };
            self.call_target(&target, unconfirmed, span);
        }
    }

    /// Emit the `calls` edge to one target [`Calls::visit_call`] resolved.
    fn call_target(&mut self, target: &str, unconfirmed: bool, span: proc_macro2::Span) {
        let endpoint = reference(call_kind(target), target);
        if unconfirmed {
            self.walk
                .facts
                .conditional_edge(&self.enclosing, &endpoint, span);
            return;
        }
        let head = target.split("::").next().unwrap_or(target);
        if head == self.walk.crate_root() || self.walk.layout.is_project_root(head) {
            self.walk
                .facts
                .speculative_edge("calls", &self.enclosing, &endpoint, span);
        } else {
            self.walk
                .facts
                .edge("calls", &self.enclosing, &endpoint, "probable", span);
        }
    }
}

impl Calls<'_, '_> {
    /// Speculative `references` edges to the type a path names, as a struct
    /// or enum and as a trait: which one it is, and whether it is declared
    /// here at all rather than being `Vec` or `String`, only the graph knows.
    fn type_reference(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        for (target, unconfirmed) in self.walk.resolve_paths(&self.container, path) {
            self.type_target(path, &target, unconfirmed, span);
        }
    }

    /// Emit the references [`Calls::type_reference`] makes to one target.
    fn type_target(
        &mut self,
        path: &syn::Path,
        target: &str,
        unconfirmed: bool,
        span: proc_macro2::Span,
    ) {
        // `str`, `Some`, `Option` written bare and neither declared nor
        // imported here are the standard ones, not items of this module. The
        // guess named nothing, and in a large file those edges alone could
        // outgrow the size limit on its answer.
        if unconfirmed
            && path.segments.len() == 1
            && is_prelude_name(&ident_name(&path.segments[0].ident))
            && !self.walk.own_declarations.contains(target)
        {
            return;
        }
        for kind in ["class", "interface"] {
            let endpoint = reference(kind, target);
            if endpoint != self.enclosing {
                self.walk
                    .facts
                    .speculative_edge("references", &self.enclosing, &endpoint, span);
            }
        }
    }

    /// The type a multi-segment value or pattern path sits under:
    /// `HookState::Absent` names `HookState`, `Self::helper` the impl's type.
    /// A path under `crate`, `self` or `super` (`crate::helper()`) sits under
    /// a module, which is no type to reference.
    fn owner_reference(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        if path.segments.len() < 2 {
            return;
        }
        let mut owner = path.clone();
        owner.segments.pop();
        owner.segments.pop_punct();
        if owner.segments.last().is_some_and(|segment| {
            matches!(
                ident_name(&segment.ident).as_str(),
                "crate" | "self" | "super"
            )
        }) {
            return;
        }
        self.type_reference(&owner, span);
    }

    /// A function named as a value (`map(Self::helper)`, `.or_else(fallback)`),
    /// which no call expression marks. A bare name is usually a local binding,
    /// so an unconfirmed one counts only when this file declares a function
    /// by that name, the same deferral an unqualified call gets.
    fn value_reference(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        for (target, unconfirmed) in self.walk.resolve_paths(&self.container, path) {
            self.value_target(path, &target, unconfirmed, span);
        }
    }

    /// Emit the references [`Calls::value_reference`] makes to one target.
    fn value_target(
        &mut self,
        path: &syn::Path,
        target: &str,
        unconfirmed: bool,
        span: proc_macro2::Span,
    ) {
        // `Unit`, `v2::Unit` or `.map(Wrapper)`: a unit struct's value, or a
        // tuple struct's constructor, names the type. An all-capitals name
        // (`MAX`) is a constant, which the graph holds no node for.
        let last = path
            .segments
            .last()
            .map(|segment| ident_name(&segment.ident))
            .unwrap_or_default();
        if last.starts_with(char::is_uppercase) && last.chars().any(char::is_lowercase) {
            self.type_target(path, target, unconfirmed, span);
        }
        if path.segments.len() < 2 {
            let endpoint = reference("function", target);
            if unconfirmed {
                self.walk.facts.conditional_any_edge(
                    &self.enclosing,
                    &endpoint,
                    span,
                    "references",
                    "probable",
                );
            } else if endpoint != self.enclosing {
                self.walk
                    .facts
                    .speculative_edge("references", &self.enclosing, &endpoint, span);
            }
            return;
        }
        for kind in ["function", "method"] {
            let endpoint = reference(kind, target);
            if endpoint != self.enclosing {
                self.walk
                    .facts
                    .speculative_edge("references", &self.enclosing, &endpoint, span);
            }
        }
    }

    /// The functions a `#[serde(...)]` attribute names by string:
    /// `default = "f"`, `serialize_with`, `deserialize_with`,
    /// `skip_serializing_if`, `getter`, and a `with = "module"`'s
    /// `serialize`/`deserialize`. Serde calls each; nothing else names them.
    pub(super) fn serde_references(&mut self, attrs: &[syn::Attribute]) {
        for attr in attrs {
            if !attr.path().is_ident("serde") {
                continue;
            }
            for (path, with) in serde_function_paths(attr) {
                let span = attr.span();
                if with {
                    for function in ["serialize", "deserialize"] {
                        let mut member = path.clone();
                        member.segments.push(syn::PathSegment::from(syn::Ident::new(
                            function,
                            proc_macro2::Span::call_site(),
                        )));
                        self.function_reference(&member, span);
                    }
                } else {
                    self.function_reference(&path, span);
                }
            }
        }
    }

    /// The type a method call's receiver holds, when the source states it: a
    /// local of known type, a field or an element of one, or an enum variant
    /// or associated constant (`Theme::Regular`), whose type is the path it
    /// sits under.
    fn receiver_owner(&self, expression: &syn::Expr) -> Option<String> {
        if let Some(name) = receiver_name(expression) {
            return self.receivers.get(&name).cloned();
        }
        let mut current = expression;
        loop {
            current = match current {
                syn::Expr::Paren(inner) => &inner.expr,
                syn::Expr::Reference(inner) => &inner.expr,
                _ => break,
            };
        }
        // `grid.tiles[x][y]`: an element of the collection indexed.
        if let syn::Expr::Index(index) = current {
            let owner = self.receiver_owner(&index.expr)?;
            return element_of(&owner).map(str::to_owned);
        }
        // `self.walk.facts`: the base's type, then the field's declared type.
        if let syn::Expr::Field(field) = current {
            let syn::Member::Named(name) = &field.member else {
                return None;
            };
            // A field access derefs a smart pointer to what it holds.
            let owner = self.receiver_owner(&field.base)?;
            let member = ident_name(name);
            if let Some(stated) = self.walk.field_type(pointee_of(&owner), &member) {
                return Some(stated);
            }
            // `self.environment` declared `&'a mut E`: what the receiver's
            // type argument for `E` is (`impl<E: Environment> Context<'_, E>`).
            let position = self.walk.field_param(pointee_of(&owner), &member)?;
            let base = receiver_name(&field.base)?;
            let (typed, arguments) = self.receiver_arguments.get(&base)?;
            return if *typed == owner {
                arguments.get(position).cloned().flatten()
            } else {
                None
            };
        }
        // `open(root)?.load()`, `make().unwrap().run()`: what the
        // expression evaluates to, when the source states it.
        let syn::Expr::Path(path) = current else {
            return self.value_type(current);
        };
        if path.qself.is_some() || path.path.segments.len() < 2 {
            return None;
        }
        let mut owner = path.path.clone();
        owner.segments.pop();
        owner.segments.pop_punct();
        self.walk.stated_type(&self.container, &owner)
    }

    /// The type a `let` initializer gives its binding, when the source
    /// states it: a value it constructs (see [`constructed_type`]), a local,
    /// field or element of known type it copies, or what a call returns
    /// (`let trie = sample_trie();`), whichever file declares the function.
    /// `?`, `.unwrap()` and `.expect(..)` open an `Option` or a `Result` to
    /// what it holds. A `match`, an `if` or a block has the type of the arm
    /// or tail whose type is stated: Rust makes every arm agree.
    fn value_type(&self, expression: &syn::Expr) -> Option<String> {
        match expression {
            syn::Expr::Paren(inner) => return self.value_type(&inner.expr),
            syn::Expr::Reference(inner) => return self.value_type(&inner.expr),
            syn::Expr::Try(inner) => {
                if let Some(held) = self.value_type(&inner.expr) {
                    return unwrapped(&held).map(str::to_owned);
                }
            }
            syn::Expr::MethodCall(call) if call.turbofish.is_none() && opens_wrapper(call) => {
                if let Some(held) = self.value_type(&call.receiver) {
                    return unwrapped(&held).map(str::to_owned);
                }
            }
            // `provider.as_ref()`, `slot.take()`: still an `Option` (or a
            // `Result`) of what it held, seen through the borrow.
            syn::Expr::MethodCall(call) if call.turbofish.is_none() && keeps_wrapper(call) => {
                if let Some(held) = self.value_type(&call.receiver) {
                    if let Some(inner) = unwrapped(&held) {
                        return Some(format!("{WRAP_MARK}{inner}"));
                    }
                }
            }
            // `from_value(v).map(ToolCall::RunStart)`: an `Option` or a
            // `Result` of what the function or variant named returns.
            syn::Expr::MethodCall(call)
                if call.turbofish.is_none() && call.method == "map" && call.args.len() == 1 =>
            {
                if let Some(mapped) = self.mapped_type(call) {
                    return Some(format!("{WRAP_MARK}{mapped}"));
                }
            }
            // `entries.iter().find(..)`, `entries.last()`: an `Option` of
            // one element of the collection.
            syn::Expr::MethodCall(call) if call.turbofish.is_none() => {
                if let Some(element) = self.picked_element(call) {
                    return Some(format!("{WRAP_MARK}{element}"));
                }
            }
            syn::Expr::Match(node) => {
                return node.arms.iter().find_map(|arm| {
                    self.unwrapping_arm(arm, &node.expr)
                        .or_else(|| self.value_type(&arm.body))
                });
            }
            syn::Expr::If(node) => {
                return self.block_type(&node.then_branch).or_else(|| {
                    let (_, otherwise) = node.else_branch.as_ref()?;
                    self.value_type(otherwise)
                });
            }
            syn::Expr::Block(node) => return self.block_type(&node.block),
            syn::Expr::Unsafe(node) => return self.block_type(&node.block),
            _ => {}
        }
        if let Some(callee) = self.returning_call(expression) {
            if let Some(returned) = self.walk.returned_type(&callee) {
                return Some(returned);
            }
        }
        match constructed_type(expression) {
            Some(path) => self.walk.stated_type(&self.container, &path),
            None if is_place(expression) => self.receiver_owner(expression),
            None => None,
        }
    }

    /// What `.map(f)` makes of the value an `Option` or a `Result` holds,
    /// when `f` is named rather than a closure: a tuple variant's enum
    /// (`ToolCall::RunStart`) or what a function returns. Not for a `map`
    /// over an iterator of known element, whose result is no `Option`.
    fn mapped_type(&self, call: &syn::ExprMethodCall) -> Option<String> {
        let syn::Expr::Path(function) = call.args.first()? else {
            return None;
        };
        if function.qself.is_some() || self.iterator_element(&call.receiver).is_some() {
            return None;
        }
        let receiver = self.value_type(&call.receiver);
        if receiver
            .as_deref()
            .is_some_and(|held| unwrapped(held).is_none())
        {
            return None;
        }
        let path = &function.path;
        let last = ident_name(&path.segments.last()?.ident);
        if path.segments.len() >= 2 && last.starts_with(char::is_uppercase) {
            let mut owner = path.clone();
            owner.segments.pop();
            owner.segments.pop_punct();
            return self.walk.stated_type(&self.container, &owner);
        }
        match self.walk.resolve_path(&self.container, path)? {
            (target, false) => self.walk.returned_type(&target),
            (target, true) if self.walk.own_declarations.contains(&target) => {
                self.walk.returned_type(&target)
            }
            _ => None,
        }
    }

    /// The element a method picking one out of a sequence returns in an
    /// `Option`: `find`, `next`, `last`, `nth`, `min`, `max` and the like on
    /// an iterator over a known element, and `first`, `last`, `get` and
    /// `pop` (with their `_mut` forms) on a collection of one.
    fn picked_element(&self, call: &syn::ExprMethodCall) -> Option<String> {
        let method = ident_name(&call.method);
        let from_iterator = matches!(
            method.as_str(),
            "find"
                | "next"
                | "next_back"
                | "last"
                | "nth"
                | "nth_back"
                | "min"
                | "max"
                | "min_by"
                | "max_by"
                | "min_by_key"
                | "max_by_key"
                | "reduce"
        );
        if from_iterator {
            if let Some(element) = self.iterator_element(&call.receiver) {
                return Some(element);
            }
        }
        let from_collection = matches!(
            method.as_str(),
            "first" | "last" | "get" | "first_mut" | "last_mut" | "get_mut" | "pop"
        );
        if !from_collection {
            return None;
        }
        let owner = self.receiver_owner(&call.receiver)?;

        element_of(&owner).map(str::to_owned)
    }

    /// The type of an arm that hands back what `Ok(c)` or `Some(c)` binds
    /// (`Ok(c) => c`): what the `Result` or `Option` matched holds. Asked
    /// before the arm's body is typed, since `c` is the arm's own binding
    /// and not one outside the `match`.
    fn unwrapping_arm(&self, arm: &syn::Arm, scrutinee: &syn::Expr) -> Option<String> {
        let syn::Pat::TupleStruct(pattern) = &arm.pat else {
            return None;
        };
        let last = ident_name(&pattern.path.segments.last()?.ident);
        if pattern.qself.is_some() || !matches!(last.as_str(), "Some" | "Ok") {
            return None;
        }
        let [syn::Pat::Ident(binding)] = pattern.elems.iter().collect::<Vec<_>>()[..] else {
            return None;
        };
        let body = match arm.body.as_ref() {
            syn::Expr::Block(block) => match block.block.stmts.last()? {
                syn::Stmt::Expr(tail, None) if block.block.stmts.len() == 1 => tail,
                _ => return None,
            },
            other => other,
        };
        if binding.subpat.is_some() || receiver_name(body)? != ident_name(&binding.ident) {
            return None;
        }

        unwrapped(&self.receiver_owner(scrutinee)?).map(str::to_owned)
    }

    /// The type a block evaluates to: its tail expression's.
    fn block_type(&self, block: &syn::Block) -> Option<String> {
        match block.stmts.last()? {
            syn::Stmt::Expr(tail, None) => self.value_type(tail),
            _ => None,
        }
    }

    /// Bind each name of a tuple pattern to the type of the matching
    /// element of a tuple initializer: `let (grid, trie) = (self.grid, t);`.
    fn bind_tuple(&mut self, names: &syn::PatTuple, init: &syn::Expr) {
        let syn::Expr::Tuple(values) = init else {
            return;
        };
        if names.elems.len() != values.elems.len() {
            return;
        }
        for (name, value) in names.elems.iter().zip(&values.elems) {
            let syn::Pat::Ident(ident) = name else {
                continue;
            };
            if ident.subpat.is_some() {
                continue;
            }
            if let Some(target) = self.value_type(value) {
                self.receivers.insert(ident_name(&ident.ident), target);
            }
        }
    }

    /// The element a `for` loop binds when it walks a collection of known
    /// type: the collection itself, a reference to it, an `Option`, or an
    /// iterator over one (see [`Calls::iterator_element`]).
    fn iterated_element(&self, expression: &syn::Expr) -> Option<String> {
        self.iterator_element(expression)
            .or_else(|| self.held_element(expression))
    }

    /// The element an iterator chain yields, when it starts from a
    /// collection or an `Option` of known type: its `iter()`, `iter_mut()`
    /// or `into_iter()`, followed by any adapters that keep the element
    /// (`filter`, `rev`, `take`, ..). None for anything else, which is no
    /// iterator the walk can name.
    fn iterator_element(&self, expression: &syn::Expr) -> Option<String> {
        let syn::Expr::MethodCall(call) = strip_place(expression) else {
            return None;
        };
        match ident_name(&call.method).as_str() {
            "iter" | "iter_mut" | "into_iter" if call.args.is_empty() => {
                self.held_element(&call.receiver)
            }
            "filter" | "skip" | "take" | "rev" | "peekable" | "skip_while" | "take_while"
            | "inspect" | "cloned" | "copied" | "by_ref" | "step_by" | "fuse" => {
                self.iterator_element(&call.receiver)
            }
            _ => None,
        }
    }

    /// What iterating a value of known type yields: a collection's element,
    /// or the value an `Option` or a `Result` holds.
    fn held_element(&self, expression: &syn::Expr) -> Option<String> {
        let owner = self.receiver_owner(expression)?;

        element_of(&owner)
            .or_else(|| unwrapped(&owner))
            .map(str::to_owned)
    }

    /// The argument of an adapter call that is a closure taking what the
    /// receiver yields, with that type: `any`, `map`, `find` and the rest
    /// on an iterator over a known element, and `map`, `and_then`,
    /// `is_some_and` and the rest on an `Option` or a `Result` of known
    /// type, whose closure takes the value.
    fn closure_argument(&self, call: &syn::ExprMethodCall) -> Option<(usize, String)> {
        let method = ident_name(&call.method);
        if let Some(element) = self.iterator_element(&call.receiver) {
            let takes_element = matches!(
                method.as_str(),
                "any"
                    | "all"
                    | "map"
                    | "filter"
                    | "for_each"
                    | "find"
                    | "filter_map"
                    | "flat_map"
                    | "find_map"
                    | "position"
                    | "inspect"
                    | "take_while"
                    | "skip_while"
                    | "map_while"
                    | "max_by_key"
                    | "min_by_key"
                    | "partition"
                    | "try_for_each"
            );
            return takes_element.then_some((0, element));
        }
        let position = match method.as_str() {
            "map" | "and_then" | "is_some_and" | "is_ok_and" | "is_none_or" | "filter"
            | "inspect" | "take_if" => 0,
            "map_or" | "map_or_else" => 1,
            _ => return None,
        };
        let owner = self.receiver_owner(&call.receiver)?;

        unwrapped(&owner).map(|held| (position, held.to_owned()))
    }

    /// The bindings a struct pattern makes (`let Index { store, .. } = x;`,
    /// `Shape::Circle { radius }`), each field's pattern bound against the
    /// type the struct's or variant's declaration states for it.
    fn bind_struct(&mut self, pattern: &syn::PatStruct) {
        let owner = self.pattern_owner(pattern.qself.as_ref(), &pattern.path);
        for field in &pattern.fields {
            let stated = match (&owner, &field.member) {
                (Some(owner), syn::Member::Named(member)) => {
                    self.walk.field_type(owner, &ident_name(member))
                }
                (Some(owner), syn::Member::Unnamed(index)) => {
                    self.walk.field_type(owner, &index.index.to_string())
                }
                _ => None,
            };
            self.bind_against(&field.pat, stated);
        }
    }

    /// The bindings a tuple struct or tuple variant pattern makes: `Some(x)`
    /// or `Ok(x)` against an `Option` or a `Result` of known type binds what
    /// it holds, and any other path its declared fields, by position.
    fn bind_tuple_struct(&mut self, pattern: &syn::PatTupleStruct, held: Option<String>) {
        let last = pattern
            .path
            .segments
            .last()
            .map(|segment| ident_name(&segment.ident))
            .unwrap_or_default();
        if pattern.qself.is_none() && matches!(last.as_str(), "Some" | "Ok" | "Err" | "None") {
            let value = match last.as_str() {
                "Some" | "Ok" => held.as_deref().and_then(unwrapped).map(str::to_owned),
                _ => None,
            };
            for (position, element) in pattern.elems.iter().enumerate() {
                self.bind_against(element, if position == 0 { value.clone() } else { None });
            }
            return;
        }
        let owner = self.pattern_owner(pattern.qself.as_ref(), &pattern.path);
        for (position, element) in pattern.elems.iter().enumerate() {
            let stated = owner
                .as_ref()
                .and_then(|owner| self.walk.field_type(owner, &position.to_string()));
            self.bind_against(element, stated);
        }
    }

    /// The struct or variant a pattern's path names, when it resolves.
    fn pattern_owner(&self, qself: Option<&syn::QSelf>, path: &syn::Path) -> Option<String> {
        if qself.is_some() {
            return None;
        }
        self.walk
            .resolve_path(&self.container, path)
            .map(|(target, _)| target)
    }

    /// Walk an `if` or `while` condition, binding each `let` pattern of a
    /// `&&` chain right after its scrutinee, so the conditions after it
    /// (`if let Some(s) = o && s.go()`) see the new binding.
    fn visit_condition(&mut self, condition: &syn::Expr) {
        match condition {
            syn::Expr::Let(binding) => {
                self.visit_expr(&binding.expr);
                self.visit_pat(&binding.pat);
                let held = self.receiver_owner(&binding.expr);
                self.bind_against(&binding.pat, held);
            }
            syn::Expr::Binary(binary) if matches!(binary.op, syn::BinOp::And(_)) => {
                self.visit_condition(&binary.left);
                self.visit_condition(&binary.right);
            }
            other => self.visit_expr(other),
        }
    }

    /// Every name a pattern binds, as a new binding: typed where the pattern
    /// states its type (a struct pattern's fields, `name: Type`), forgotten
    /// otherwise, so an outer binding of the same name lends it no type.
    /// The alternatives of an or-pattern keep only the types they agree on.
    fn bind_pattern(&mut self, pattern: &syn::Pat) {
        self.bind_against(pattern, None);
    }

    /// Bind a pattern matched against a value of type `held`, when that is
    /// known: a plain name takes it, `Some(x)` or `Ok(x)` what an `Option`
    /// or a `Result` holds, a slice pattern the element. What the pattern
    /// itself states (a struct's fields, `name: Type`) is taken over it.
    fn bind_against(&mut self, pattern: &syn::Pat, held: Option<String>) {
        match pattern {
            syn::Pat::Ident(ident) => {
                let name = ident_name(&ident.ident);
                match &held {
                    Some(target) => {
                        self.receivers.insert(name, target.clone());
                    }
                    None => {
                        self.receivers.remove(&name);
                    }
                }
                if let Some((_, inner)) = &ident.subpat {
                    self.bind_against(inner, held);
                }
            }
            syn::Pat::Type(typed) => {
                let stated = self.walk.receiver_type(&self.container, &typed.ty);
                self.bind_against(&typed.pat, stated);
            }
            syn::Pat::Struct(inner) => self.bind_struct(inner),
            syn::Pat::Tuple(inner) => inner.elems.iter().for_each(|e| self.bind_pattern(e)),
            syn::Pat::TupleStruct(inner) => self.bind_tuple_struct(inner, held),
            syn::Pat::Slice(inner) => {
                let element = held.as_deref().and_then(element_of).map(str::to_owned);
                for item in &inner.elems {
                    // `rest @ ..` is the rest of the slice, not one element.
                    let rest = matches!(
                        item,
                        syn::Pat::Ident(ident)
                            if matches!(ident.subpat.as_ref().map(|(_, p)| p.as_ref()), Some(syn::Pat::Rest(_)))
                    );
                    let bound = if rest { held.clone() } else { element.clone() };
                    self.bind_against(item, bound);
                }
            }
            syn::Pat::Reference(inner) => self.bind_against(&inner.pat, held),
            syn::Pat::Paren(inner) => self.bind_against(&inner.pat, held),
            // A match arm's guard wraps the arm's pattern; the guard binds nothing.
            syn::Pat::Guard(inner) => self.bind_against(&inner.pat, held),
            syn::Pat::Or(inner) => {
                let before = self.receivers.clone();
                let mut agreed: Option<BTreeMap<String, String>> = None;
                for case in &inner.cases {
                    self.receivers = before.clone();
                    self.bind_against(case, held.clone());
                    agreed = Some(match agreed {
                        None => self.receivers.clone(),
                        Some(known) => known
                            .into_iter()
                            .filter(|(name, target)| self.receivers.get(name) == Some(target))
                            .collect(),
                    });
                }
                if let Some(agreed) = agreed {
                    self.receivers = agreed;
                }
            }
            _ => {}
        }
    }

    /// The method whose result an expression is, when its owner is known: a
    /// method call on a receiver of stated type, or an associated call
    /// `Type::f(..)`. `Type::method` as a canonical path, or None.
    fn returning_call(&self, expression: &syn::Expr) -> Option<String> {
        match expression {
            syn::Expr::Paren(inner) => self.returning_call(&inner.expr),
            syn::Expr::Reference(reference) => self.returning_call(&reference.expr),
            syn::Expr::MethodCall(call) => {
                let method = ident_name(&call.method);
                let owner = dispatch(&self.receiver_owner(&call.receiver)?, &method)?;
                // Of `cfg` alternatives, no one method's return is the value's.
                (!owner.contains(ALTERNATIVE_MARK)).then(|| format!("{owner}::{method}"))
            }
            syn::Expr::Call(call) => match call.func.as_ref() {
                // `Vec::new()` names no type of this crate: a guess placed
                // in the enclosing module is not an owner. `make()` is a
                // function of this crate when its declaration or an import
                // vouches for the name, and the core resolves the call on its
                // result through the function's declared return type.
                syn::Expr::Path(path) if path.qself.is_none() => {
                    match self.walk.resolve_path(&self.container, &path.path) {
                        Some((target, false)) => Some(target),
                        Some((target, true))
                            if path.path.segments.len() == 1
                                && self.walk.own_declarations.contains(&target) =>
                        {
                            Some(target)
                        }
                        _ => None,
                    }
                }
                _ => None,
            },
            _ => None,
        }
    }

    /// The module an inline path reaches, imported as a `use` of it would be:
    /// `grep::cli::CommandReaderBuilder::new()` written without a `use` line
    /// depends on `grep_cli` just as `use grep::cli::CommandReaderBuilder;`
    /// does. The item, and any type its member hangs off, are left off the
    /// written path, and what remains resolves through the file's aliases and
    /// re-exports. A path that only guesses at a module, or names the file's
    /// own module, imports nothing.
    fn inline_import(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        if path.segments.len() < 2 {
            return;
        }
        let mut module = path.clone();
        module.segments.pop();
        module.segments.pop_punct();
        while module
            .segments
            .last()
            .is_some_and(|segment| ident_name(&segment.ident).starts_with(char::is_uppercase))
        {
            module.segments.pop();
            module.segments.pop_punct();
        }
        if module.segments.is_empty() {
            return;
        }
        for (target, unconfirmed) in self.walk.resolve_paths(&self.container, &module) {
            if !unconfirmed && target != self.walk.module && target != self.container {
                let source = reference("module", &self.walk.module);
                self.walk.import(&source, &target, span);
            }
        }
    }

    /// A speculative `references` edge to the function a path names.
    fn function_reference(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        for (target, _) in self.walk.resolve_paths(&self.container, path) {
            let endpoint = reference("function", &target);
            self.walk
                .facts
                .speculative_edge("references", &self.enclosing, &endpoint, span);
        }
    }
}

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

impl Calls<'_, '_> {
    /// Walk a block's statements in order. Where consecutive `let`s bind
    /// one name each under its own `cfg` (`#[cfg(target_os = "linux")] let
    /// loaded = load_stock(..); #[cfg(not(..))] let loaded = load(..);`),
    /// one of them is compiled, so the rest of the block is walked once
    /// after each: what is called on the binding, or on what is taken out
    /// of it, is called on every alternative's type. The core collapses the
    /// edges a replay repeats; routes are recorded on the first walk only.
    /// Past a few nested alternatives, the last one alone stands, as when
    /// there is no `cfg`.
    pub(super) fn visit_statements(&mut self, statements: &[syn::Stmt]) {
        /// Alternatives replayed inside one another at most.
        const MAX_REPLAYS: u8 = 2;
        for (index, statement) in statements.iter().enumerate() {
            let alternatives = cfg_alternatives(&statements[index..]);
            if alternatives < 2 || self.replaying >= MAX_REPLAYS {
                self.visit_stmt(statement);
                continue;
            }
            let rest = &statements[index + alternatives..];
            let saved = (self.receivers.clone(), self.receiver_arguments.clone());
            for (position, alternative) in
                statements[index..index + alternatives].iter().enumerate()
            {
                (self.receivers, self.receiver_arguments) = saved.clone();
                self.visit_stmt(alternative);
                let replay = u8::from(position > 0);
                self.replaying += replay;
                self.visit_statements(rest);
                self.replaying -= replay;
            }
            return;
        }
    }

    /// Walk a macro body as expressions, as far as it is made of them.
    ///
    /// Comma-separated expressions first (`format!`, `assert_eq!`). A body
    /// that is not (`json!({ "k": f(x) })`) is split at its top-level commas,
    /// each piece loses a leading `"key":` or `key:`, and what remains is
    /// parsed on its own; a piece that still does not parse is searched
    /// through its bracketed groups the same way. Nothing is guessed: only
    /// token runs that parse as Rust expressions are walked. The groups still
    /// to search are kept in a worklist, so the walk is one loop rather than
    /// two functions calling each other.
    ///
    /// Returns whether every token run was walked as expressions; when one
    /// was not, the caller may search the body's tokens instead.
    fn visit_macro_tokens(&mut self, tokens: proc_macro2::TokenStream) -> bool {
        use syn::parse::Parser;
        let parser = syn::punctuated::Punctuated::<syn::Expr, syn::Token![,]>::parse_terminated;
        let mut pending = vec![tokens];
        let mut whole = true;
        while let Some(tokens) = pending.pop() {
            if let Ok(arguments) = parser.parse2(tokens.clone()) {
                for argument in &arguments {
                    self.visit_expr(argument);
                }
                continue;
            }
            for piece in top_level_pieces(tokens) {
                match macro_piece(piece) {
                    Ok(expression) => self.visit_expr(&expression),
                    Err(groups) => {
                        whole = false;
                        pending.extend(groups);
                    }
                }
            }
        }
        whole
    }

    /// Walk `matches!(value, pattern if guard)`'s arguments as the
    /// expression, pattern and guard they are, returning whether they
    /// parsed so.
    fn visit_matches(&mut self, tokens: proc_macro2::TokenStream) -> bool {
        use syn::parse::Parser;
        let parser = |input: syn::parse::ParseStream<'_>| {
            let value: syn::Expr = input.parse()?;
            input.parse::<syn::Token![,]>()?;
            let pattern = syn::Pat::parse_multi_with_leading_vert(input)?;
            let guard = if input.peek(syn::Token![if]) {
                input.parse::<syn::Token![if]>()?;
                Some(input.parse::<syn::Expr>()?)
            } else {
                None
            };
            if input.peek(syn::Token![,]) {
                input.parse::<syn::Token![,]>()?;
            }
            Ok((value, pattern, guard))
        };
        let Ok((value, pattern, guard)) = parser.parse2(tokens) else {
            return false;
        };
        self.visit_expr(&value);
        let saved = self.receivers.clone();
        self.bind_pattern(&pattern);
        self.visit_pat(&pattern);
        if let Some(guard) = &guard {
            self.visit_expr(guard);
        }
        self.receivers = saved;
        true
    }

    /// Record a `possible` call to every path `tokens` write as one: a
    /// path of identifiers followed by a parenthesised group, at any depth,
    /// that is not a declaration (`fn f(`), a method call (`.f(`), a macro
    /// invocation (`f!(`), a macro metavariable (`$f(`) or a keyword
    /// (`if (`). `$crate::` is this crate. Read from tokens no parse turned
    /// into code, so none of it is certain.
    pub(super) fn visit_token_calls(&mut self, tokens: proc_macro2::TokenStream) {
        let mut calls = Vec::new();
        token_calls(tokens, &mut calls);
        self.walk.facts.enter_possible_scope();
        for (path, span) in calls {
            self.visit_call(&path, span);
        }
        self.walk.facts.exit_possible_scope();
    }
}

/// The paths `tokens` write as calls, with where each is, for
/// [`Calls::visit_token_calls`].
fn token_calls(tokens: proc_macro2::TokenStream, out: &mut Vec<(syn::Path, proc_macro2::Span)>) {
    use proc_macro2::TokenTree;
    /// Words a path written as a call never starts with.
    const NOT_CALLS: [&str; 22] = [
        "if", "while", "match", "return", "for", "in", "fn", "let", "loop", "move", "as", "else",
        "unsafe", "async", "await", "where", "impl", "dyn", "ref", "mut", "break", "yield",
    ];
    let tokens: Vec<TokenTree> = tokens.into_iter().collect();
    let mut index = 0;
    while index < tokens.len() {
        if let TokenTree::Group(group) = &tokens[index] {
            token_calls(group.stream(), out);
            index += 1;
            continue;
        }
        let TokenTree::Ident(first) = &tokens[index] else {
            index += 1;
            continue;
        };
        let before = index.checked_sub(1).map(|at| &tokens[at]);
        let dollar = matches!(before, Some(TokenTree::Punct(p)) if p.as_char() == '$');
        let excluded = match before {
            Some(TokenTree::Punct(p)) => p.as_char() == '.' || (dollar && *first != "crate"),
            Some(TokenTree::Ident(word)) => *word == "fn",
            _ => false,
        };
        let mut segments = vec![first.clone()];
        let mut end = index + 1;
        while end + 2 < tokens.len() {
            match (&tokens[end], &tokens[end + 1], &tokens[end + 2]) {
                (TokenTree::Punct(a), TokenTree::Punct(b), TokenTree::Ident(next))
                    if a.as_char() == ':' && b.as_char() == ':' =>
                {
                    segments.push(next.clone());
                    end += 3;
                }
                _ => break,
            }
        }
        let called = matches!(
            tokens.get(end),
            Some(TokenTree::Group(group)) if group.delimiter() == proc_macro2::Delimiter::Parenthesis
        );
        let keyword = segments.len() == 1 && NOT_CALLS.contains(&first.to_string().as_str());
        if called && !excluded && !keyword {
            let mut path = syn::Path {
                leading_colon: None,
                segments: syn::punctuated::Punctuated::new(),
            };
            for segment in &segments {
                path.segments.push(syn::PathSegment::from(segment.clone()));
            }
            out.push((path, first.span()));
        }
        index = end;
    }
}

/// A macro body split at its top-level commas.
fn top_level_pieces(tokens: proc_macro2::TokenStream) -> Vec<Vec<proc_macro2::TokenTree>> {
    let mut pieces = vec![Vec::new()];
    for token in tokens {
        if matches!(&token, proc_macro2::TokenTree::Punct(punct) if punct.as_char() == ',') {
            pieces.push(Vec::new());
        } else if let Some(piece) = pieces.last_mut() {
            piece.push(token);
        }
    }
    pieces
}

/// One comma-separated piece of a macro body, without a leading `"key":` or
/// `key:`: the expression it parses as, or else the bracketed groups inside
/// it, which may hold expressions of their own. See [`Calls::visit_macro_tokens`].
fn macro_piece(
    mut piece: Vec<proc_macro2::TokenTree>,
) -> Result<syn::Expr, Vec<proc_macro2::TokenStream>> {
    let keyed = matches!(
        piece.as_slice(),
        [
            proc_macro2::TokenTree::Literal(_) | proc_macro2::TokenTree::Ident(_),
            proc_macro2::TokenTree::Punct(colon),
            next,
            ..
        ] if colon.as_char() == ':'
            && colon.spacing() == proc_macro2::Spacing::Alone
            && !matches!(next, proc_macro2::TokenTree::Punct(p) if p.as_char() == ':')
    );
    if keyed {
        piece.drain(..2);
    }
    if piece.is_empty() {
        return Err(Vec::new());
    }
    let stream: proc_macro2::TokenStream = piece.iter().cloned().collect();
    syn::parse2::<syn::Expr>(stream).map_err(|_| {
        piece
            .into_iter()
            .filter_map(|token| match token {
                proc_macro2::TokenTree::Group(group) => Some(group.stream()),
                _ => None,
            })
            .collect()
    })
}

/// Whether an attribute is `#[wasm_bindgen]`, however its path is spelled,
/// or a `cfg_attr`, at any depth, that applies it.
pub(super) fn is_wasm_bindgen(attr: &syn::Attribute) -> bool {
    applies_wasm_bindgen(&attr.meta)
}

/// Whether one attribute's meta is `wasm_bindgen`, or a `cfg_attr` that
/// applies it: `#[cfg_attr(target_arch = "wasm32", wasm_bindgen)]` is how an
/// item is exported only to the build the bindings exist for, and a
/// `cfg_attr` may apply another.
fn applies_wasm_bindgen(meta: &syn::Meta) -> bool {
    if meta
        .path()
        .segments
        .last()
        .is_some_and(|segment| segment.ident == "wasm_bindgen")
    {
        return true;
    }
    let syn::Meta::List(list) = meta else {
        return false;
    };
    list.path.is_ident("cfg_attr")
        && list
            .parse_args_with(
                syn::punctuated::Punctuated::<syn::Meta, syn::Token![,]>::parse_terminated,
            )
            .is_ok_and(|metas| metas.iter().skip(1).any(applies_wasm_bindgen))
}

/// Whether a function is exported to a caller outside Rust: `#[no_mangle]`
/// (also as `#[unsafe(no_mangle)]`), `#[export_name]`, `#[wasm_bindgen]`, or
/// an `extern` ABI.
pub(super) fn is_foreign_export(node: &syn::ItemFn) -> bool {
    node.sig.abi.is_some()
        || node.attrs.iter().any(|attr| {
            let path = attr.path();
            path.is_ident("no_mangle")
                || path.is_ident("export_name")
                || is_wasm_bindgen(attr)
                || (path.is_ident("unsafe")
                    && attr
                        .meta
                        .require_list()
                        .is_ok_and(|list| list.tokens.to_string().contains("no_mangle")))
        })
}

/// The type a method call on a receiver of type `owner` resolves through:
/// the type itself, or for a smart pointer what it holds, unless the
/// pointer provides the method itself (`a.clone()` clones the `Arc`). Such
/// a call is left untyped rather than guessed, and so is a call on a
/// collection, whose methods are its own and not its element's.
fn dispatch(owner: &str, method: &str) -> Option<String> {
    let held = pointee_of(owner);
    if held.starts_with(ELEMENT_MARK) || held.starts_with(WRAP_MARK) {
        return None;
    }
    let Some(held) = owner.strip_prefix(POINTER_MARK) else {
        return Some(owner.to_owned());
    };
    let pointer_own = matches!(
        method,
        "as_mut"
            | "as_ptr"
            | "as_ref"
            | "borrow"
            | "borrow_mut"
            | "clone"
            | "deref"
            | "deref_mut"
            | "downgrade"
            | "get_mut"
            | "into_inner"
            | "into_pin"
            | "into_raw"
            | "leak"
            | "make_mut"
            | "ptr_eq"
            | "strong_count"
            | "to_owned"
            | "try_unwrap"
            | "type_id"
            | "unwrap_or_clone"
            | "weak_count"
    );

    (!pointer_own).then(|| held.to_owned())
}

/// Whether a method call opens an `Option` or a `Result` to the value it
/// holds: `.unwrap()`, `.expect(..)` and the defaulting `unwrap_or*`.
fn opens_wrapper(call: &syn::ExprMethodCall) -> bool {
    matches!(
        ident_name(&call.method).as_str(),
        "unwrap" | "expect" | "unwrap_or" | "unwrap_or_else" | "unwrap_or_default"
    )
}

/// How many statements from the first are `let`s binding the same name,
/// each under a `cfg` of its own and no two under the same one: the `cfg`
/// alternatives of one binding, of which one is compiled. Fewer than two is
/// no alternative.
fn cfg_alternatives(statements: &[syn::Stmt]) -> usize {
    let binding = |statement: &syn::Stmt| -> Option<(String, String)> {
        let syn::Stmt::Local(local) = statement else {
            return None;
        };
        let name = match &local.pat {
            syn::Pat::Ident(ident) if ident.subpat.is_none() => ident_name(&ident.ident),
            syn::Pat::Type(typed) => match typed.pat.as_ref() {
                syn::Pat::Ident(ident) if ident.subpat.is_none() => ident_name(&ident.ident),
                _ => return None,
            },
            _ => return None,
        };
        Some((name, cfg_predicate(&local.attrs)?))
    };
    let Some((name, first)) = statements.first().and_then(binding) else {
        return 0;
    };
    let mut seen = vec![first];
    for statement in &statements[1..] {
        match binding(statement) {
            Some((next, predicate)) if next == name && !seen.contains(&predicate) => {
                seen.push(predicate);
            }
            _ => break,
        }
    }
    seen.len()
}

/// Whether a method call on an `Option` or a `Result` returns one holding
/// the same value, borrowed, moved out or copied: `as_ref`, `as_mut`,
/// `as_deref`, `take`, `cloned` and the like, and the conversions between
/// the two (`ok_or`, `ok`, `map_err`, anyhow's `context`).
fn keeps_wrapper(call: &syn::ExprMethodCall) -> bool {
    matches!(
        ident_name(&call.method).as_str(),
        "as_ref"
            | "as_mut"
            | "as_deref"
            | "as_deref_mut"
            | "take"
            | "cloned"
            | "copied"
            | "clone"
            | "or"
            | "or_else"
            | "filter"
            | "inspect"
            // An `Option` turned into a `Result` or back, or a `Result`
            // whose error is changed, still holds the same value.
            | "map_err"
            | "ok_or"
            | "ok_or_else"
            | "ok"
            | "context"
            | "with_context"
    )
}

/// Whether an expression names a place whose type a receiver can carry: a
/// local, a field or an element, seen through `&` and parentheses. A path
/// of more than one segment is a constant or a variant, not a copy.
fn is_place(expression: &syn::Expr) -> bool {
    match expression {
        syn::Expr::Paren(inner) => is_place(&inner.expr),
        syn::Expr::Reference(reference) => is_place(&reference.expr),
        syn::Expr::Field(_) | syn::Expr::Index(_) => true,
        syn::Expr::Path(path) => path.qself.is_none() && path.path.get_ident().is_some(),
        _ => false,
    }
}

/// An expression seen through `&` and parentheses.
fn strip_place(expression: &syn::Expr) -> &syn::Expr {
    match expression {
        syn::Expr::Paren(inner) => strip_place(&inner.expr),
        syn::Expr::Reference(reference) => strip_place(&reference.expr),
        other => other,
    }
}

/// The local a method call's receiver names, seen through `&` and parentheses.
fn receiver_name(expression: &syn::Expr) -> Option<String> {
    match expression {
        syn::Expr::Paren(inner) => receiver_name(&inner.expr),
        syn::Expr::Reference(reference) => receiver_name(&reference.expr),
        syn::Expr::Path(path) if path.qself.is_none() => path.path.get_ident().map(ident_name),
        _ => None,
    }
}

/// The type a `let` initializer constructs, when the source names it: a
/// struct literal `T { .. }`, or an associated call `T::new(..)` (taken to
/// return `Self`, as constructors do), either one behind `?`, `.unwrap()`,
/// `.expect(..)` or another of the calls that open a fallible constructor's
/// `Result<Self, _>` or `Option<Self>` to the value (`.unwrap_or_else(..)`).
fn constructed_type(expression: &syn::Expr) -> Option<syn::Path> {
    match expression {
        syn::Expr::Try(inner) => constructed_type(&inner.expr),
        syn::Expr::Paren(inner) => constructed_type(&inner.expr),
        syn::Expr::MethodCall(call)
            if matches!(
                ident_name(&call.method).as_str(),
                "unwrap" | "expect" | "unwrap_or" | "unwrap_or_else" | "unwrap_or_default"
            ) =>
        {
            constructed_type(&call.receiver)
        }
        syn::Expr::Struct(literal) if literal.qself.is_none() => Some(literal.path.clone()),
        syn::Expr::Call(call) => match call.func.as_ref() {
            syn::Expr::Path(path) if path.qself.is_none() && path.path.segments.len() >= 2 => {
                let mut owner = path.path.clone();
                owner.segments.pop();
                owner.segments.pop_punct();
                Some(owner)
            }
            _ => None,
        },
        _ => None,
    }
}

/// Whether a callee path builds a value: its last segment is UpperCamelCase,
/// as tuple structs and enum variants are and functions are not.
fn builds_value(path: &syn::Path) -> bool {
    path.segments
        .last()
        .is_some_and(|segment| ident_name(&segment.ident).starts_with(char::is_uppercase))
}

/// Whether a constructor path names an enum variant (`Error::Io`,
/// `Self::Empty`): the segment before the last is a type, too.
fn names_variant(path: &syn::Path) -> bool {
    let count = path.segments.len();
    count >= 2 && ident_name(&path.segments[count - 2].ident).starts_with(char::is_uppercase)
}

/// Guess whether a resolved call target names a free function or a
/// method/associated function on a type.
///
/// `path_target` only resolves *where* a path points, not *what kind of
/// item* it names — that would require type information this worker does
/// not have. This falls back to Rust's own naming convention instead: types
/// are UpperCamelCase and modules are snake_case by near-universal
/// convention, so the segment immediately before the final one carries the
/// same signal a human reader would use. `crate::Engine::stop` guesses
/// `method` because `Engine` starts uppercase; `crate::net::http::get`
/// guesses `function` because `http` does not.
///
/// A convention-breaking crate can fool this guess. That cost is accepted
/// deliberately: a wrong guess builds a reference of the wrong kind, which
/// then matches no declared node. For a target the source rooted or imported,
/// the reconciler synthesises an external node for it rather than crashing —
/// call targets are never filtered the way edge sources are (see
/// `Facts::finish`). For a guessed target it costs the edge instead, since
/// `Facts::finish` requires a pending call to match a declaration exactly.
/// Either way a bad guess costs one edge or one spurious node, not a failed
/// scan.
fn call_kind(canonical: &str) -> &'static str {
    let segments: Vec<&str> = canonical.split("::").collect();
    let owner = segments.len().checked_sub(2).and_then(|i| segments.get(i));
    match owner {
        Some(segment) if segment.starts_with(char::is_uppercase) => "method",
        _ => "function",
    }
}

/// The paths a `#[serde(...)]` attribute names functions by, each with
/// whether it is a `with = "module"` whose `serialize`/`deserialize` are
/// meant; what precedes a part that does not parse still counts.
fn serde_function_paths(attr: &syn::Attribute) -> Vec<(syn::Path, bool)> {
    let mut named: Vec<(syn::Path, bool)> = Vec::new();
    let _ = attr.parse_nested_meta(|meta| {
        let key = meta.path.get_ident().map(ident_name);
        if meta.input.peek(syn::Token![=]) {
            let value: syn::Expr = meta.value()?.parse()?;
            if let (
                Some(key),
                syn::Expr::Lit(syn::ExprLit {
                    lit: syn::Lit::Str(literal),
                    ..
                }),
            ) = (key, value)
            {
                let with = key == "with";
                if with
                    || matches!(
                        key.as_str(),
                        "default"
                            | "serialize_with"
                            | "deserialize_with"
                            | "skip_serializing_if"
                            | "getter"
                    )
                {
                    if let Ok(path) = literal.parse::<syn::Path>() {
                        named.push((path, with));
                    }
                }
            }
        } else if meta.input.peek(syn::token::Paren) {
            meta.parse_nested_meta(|nested| {
                if nested.input.peek(syn::Token![=]) {
                    let _: syn::Expr = nested.value()?.parse()?;
                }
                Ok(())
            })?;
        }
        Ok(())
    });
    named
}

#[cfg(test)]
mod tests {
    use super::call_kind;

    #[test]
    fn a_snake_case_owner_segment_guesses_a_free_function() {
        assert_eq!("function", call_kind("crate::helper"));
        assert_eq!("function", call_kind("crate::net::http::get"));
    }

    #[test]
    fn an_upper_camel_case_owner_segment_guesses_a_method() {
        assert_eq!("method", call_kind("crate::Engine::stop"));
        assert_eq!("method", call_kind("crate::net::Engine::stop"));
    }
}
