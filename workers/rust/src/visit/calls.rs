//! What a body names: calls, references to types and functions, and the
//! receiver types a method call resolves through.
//!
//! Function bodies, field types, module-level initializers and macro
//! arguments are all walked by the one `syn` visitor, `Calls`. A target the
//! source does not vouch for is deferred until this file's declarations are
//! known, or emitted as speculative, never invented.

use syn::spanned::Spanned;

use super::state::Calls;
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
    pub(super) fn visit_call(&mut self, path: &syn::Path, span: proc_macro2::Span) {
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
    pub(super) fn call_target(&mut self, target: &str, unconfirmed: bool, span: proc_macro2::Span) {
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
    pub(super) fn type_reference(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        for (target, unconfirmed) in self.walk.resolve_paths(&self.container, path) {
            self.type_target(path, &target, unconfirmed, span);
        }
    }

    /// Emit the references [`Calls::type_reference`] makes to one target.
    pub(super) fn type_target(
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
    pub(super) fn owner_reference(&mut self, path: &syn::Path, span: proc_macro2::Span) {
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
    pub(super) fn value_reference(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        for (target, unconfirmed) in self.walk.resolve_paths(&self.container, path) {
            self.value_target(path, &target, unconfirmed, span);
        }
    }

    /// Emit the references [`Calls::value_reference`] makes to one target.
    pub(super) fn value_target(
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

    /// The module an inline path reaches, imported as a `use` of it would be:
    /// `grep::cli::CommandReaderBuilder::new()` written without a `use` line
    /// depends on `grep_cli` just as `use grep::cli::CommandReaderBuilder;`
    /// does. The item, and any type its member hangs off, are left off the
    /// written path, and what remains resolves through the file's aliases and
    /// re-exports. A path that only guesses at a module, or names the file's
    /// own module, imports nothing.
    pub(super) fn inline_import(&mut self, path: &syn::Path, span: proc_macro2::Span) {
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
    pub(super) fn function_reference(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        for (target, _) in self.walk.resolve_paths(&self.container, path) {
            let endpoint = reference("function", &target);
            self.walk
                .facts
                .speculative_edge("references", &self.enclosing, &endpoint, span);
        }
    }
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
pub(super) fn applies_wasm_bindgen(meta: &syn::Meta) -> bool {
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

/// Whether a callee path builds a value: its last segment is UpperCamelCase,
/// as tuple structs and enum variants are and functions are not.
pub(super) fn builds_value(path: &syn::Path) -> bool {
    path.segments
        .last()
        .is_some_and(|segment| ident_name(&segment.ident).starts_with(char::is_uppercase))
}

/// Whether a constructor path names an enum variant (`Error::Io`,
/// `Self::Empty`): the segment before the last is a type, too.
pub(super) fn names_variant(path: &syn::Path) -> bool {
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
pub(super) fn call_kind(canonical: &str) -> &'static str {
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
pub(super) fn serde_function_paths(attr: &syn::Attribute) -> Vec<(syn::Path, bool)> {
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
