//! The names a pattern binds, typed against the value it matches, and the
//! walk of a block's statements that replays `cfg` alternatives of a
//! binding.

use std::collections::BTreeMap;

use syn::visit::Visit;

use super::cfg::cfg_predicate;
use super::state::Calls;
use super::types::{element_of, unwrapped};
use crate::resolve::ident_name;

impl Calls<'_, '_> {
    /// Bind each name of a tuple pattern to the type of the matching
    /// element of a tuple initializer: `let (grid, trie) = (self.grid, t);`.
    pub(super) fn bind_tuple(&mut self, names: &syn::PatTuple, init: &syn::Expr) {
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

    /// The bindings a struct pattern makes (`let Index { store, .. } = x;`,
    /// `Shape::Circle { radius }`), each field's pattern bound against the
    /// type the struct's or variant's declaration states for it.
    pub(super) fn bind_struct(&mut self, pattern: &syn::PatStruct) {
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
    pub(super) fn bind_tuple_struct(
        &mut self,
        pattern: &syn::PatTupleStruct,
        held: Option<String>,
    ) {
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
    pub(super) fn pattern_owner(
        &self,
        qself: Option<&syn::QSelf>,
        path: &syn::Path,
    ) -> Option<String> {
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
    pub(super) fn visit_condition(&mut self, condition: &syn::Expr) {
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
    pub(super) fn bind_pattern(&mut self, pattern: &syn::Pat) {
        self.bind_against(pattern, None);
    }

    /// Bind a pattern matched against a value of type `held`, when that is
    /// known: a plain name takes it, `Some(x)` or `Ok(x)` what an `Option`
    /// or a `Result` holds, a slice pattern the element. What the pattern
    /// itself states (a struct's fields, `name: Type`) is taken over it.
    pub(super) fn bind_against(&mut self, pattern: &syn::Pat, held: Option<String>) {
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
}

/// How many statements from the first are `let`s binding the same name,
/// each under a `cfg` of its own and no two under the same one: the `cfg`
/// alternatives of one binding, of which one is compiled. Fewer than two is
/// no alternative.
pub(super) fn cfg_alternatives(statements: &[syn::Stmt]) -> usize {
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
