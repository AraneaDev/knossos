//! The type a receiver or a value holds, when the source states it: a
//! local, a field, what a call returns, what an `Option`, a `Result` or a
//! collection holds, and what an adapter hands its closure.

use super::state::Calls;
use super::types::{
    element_of, pointee_of, unwrapped, ALTERNATIVE_MARK, ELEMENT_MARK, POINTER_MARK, WRAP_MARK,
};
use crate::resolve::ident_name;

impl Calls<'_, '_> {
    /// The type a method call's receiver holds, when the source states it: a
    /// local of known type, a field or an element of one, or an enum variant
    /// or associated constant (`Theme::Regular`), whose type is the path it
    /// sits under.
    pub(super) fn receiver_owner(&self, expression: &syn::Expr) -> Option<String> {
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
    pub(super) fn value_type(&self, expression: &syn::Expr) -> Option<String> {
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
    pub(super) fn mapped_type(&self, call: &syn::ExprMethodCall) -> Option<String> {
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
    pub(super) fn picked_element(&self, call: &syn::ExprMethodCall) -> Option<String> {
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
    pub(super) fn unwrapping_arm(&self, arm: &syn::Arm, scrutinee: &syn::Expr) -> Option<String> {
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
    pub(super) fn block_type(&self, block: &syn::Block) -> Option<String> {
        match block.stmts.last()? {
            syn::Stmt::Expr(tail, None) => self.value_type(tail),
            _ => None,
        }
    }

    /// The element a `for` loop binds when it walks a collection of known
    /// type: the collection itself, a reference to it, an `Option`, or an
    /// iterator over one (see [`Calls::iterator_element`]).
    pub(super) fn iterated_element(&self, expression: &syn::Expr) -> Option<String> {
        self.iterator_element(expression)
            .or_else(|| self.held_element(expression))
    }

    /// The element an iterator chain yields, when it starts from a
    /// collection or an `Option` of known type: its `iter()`, `iter_mut()`
    /// or `into_iter()`, followed by any adapters that keep the element
    /// (`filter`, `rev`, `take`, ..). None for anything else, which is no
    /// iterator the walk can name.
    pub(super) fn iterator_element(&self, expression: &syn::Expr) -> Option<String> {
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
    pub(super) fn held_element(&self, expression: &syn::Expr) -> Option<String> {
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
    pub(super) fn closure_argument(&self, call: &syn::ExprMethodCall) -> Option<(usize, String)> {
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

    /// The method whose result an expression is, when its owner is known: a
    /// method call on a receiver of stated type, or an associated call
    /// `Type::f(..)`. `Type::method` as a canonical path, or None.
    pub(super) fn returning_call(&self, expression: &syn::Expr) -> Option<String> {
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
}

/// The type a method call on a receiver of type `owner` resolves through:
/// the type itself, or for a smart pointer what it holds, unless the
/// pointer provides the method itself (`a.clone()` clones the `Arc`). Such
/// a call is left untyped rather than guessed, and so is a call on a
/// collection, whose methods are its own and not its element's.
pub(super) fn dispatch(owner: &str, method: &str) -> Option<String> {
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
pub(super) fn opens_wrapper(call: &syn::ExprMethodCall) -> bool {
    matches!(
        ident_name(&call.method).as_str(),
        "unwrap" | "expect" | "unwrap_or" | "unwrap_or_else" | "unwrap_or_default"
    )
}

/// Whether a method call on an `Option` or a `Result` returns one holding
/// the same value, borrowed, moved out or copied: `as_ref`, `as_mut`,
/// `as_deref`, `take`, `cloned` and the like, and the conversions between
/// the two (`ok_or`, `ok`, `map_err`, anyhow's `context`).
pub(super) fn keeps_wrapper(call: &syn::ExprMethodCall) -> bool {
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
pub(super) fn is_place(expression: &syn::Expr) -> bool {
    match expression {
        syn::Expr::Paren(inner) => is_place(&inner.expr),
        syn::Expr::Reference(reference) => is_place(&reference.expr),
        syn::Expr::Field(_) | syn::Expr::Index(_) => true,
        syn::Expr::Path(path) => path.qself.is_none() && path.path.get_ident().is_some(),
        _ => false,
    }
}

/// An expression seen through `&` and parentheses.
pub(super) fn strip_place(expression: &syn::Expr) -> &syn::Expr {
    match expression {
        syn::Expr::Paren(inner) => strip_place(&inner.expr),
        syn::Expr::Reference(reference) => strip_place(&reference.expr),
        other => other,
    }
}

/// The local a method call's receiver names, seen through `&` and parentheses.
pub(super) fn receiver_name(expression: &syn::Expr) -> Option<String> {
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
pub(super) fn constructed_type(expression: &syn::Expr) -> Option<syn::Path> {
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
