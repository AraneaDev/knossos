//! `impl` blocks: the type each is for, the trait it implements, and the
//! methods, associated types and `implements` edges it declares.

use std::collections::BTreeSet;

use syn::spanned::Spanned;
use syn::{ImplItem, Item, Type};

use super::*;
use crate::facts::reference;
use crate::resolve::{ident_name, is_standard_type};

impl Walk<'_> {
    /// Record the path of every method the `impl` blocks of `items`
    /// (inline modules included) declare, as [`Walk::walk_impl`] would
    /// declare it, in `out`, and the `cfg`-gated ones in `gated`. A block
    /// whose type the file cannot place inside the project, or that only a
    /// trait impl for a standard type is, declares nothing to the index.
    pub(super) fn collect_impl_methods(
        &self,
        container: &str,
        items: &[Item],
        under_cfg: bool,
        out: &mut BTreeSet<String>,
        gated: &mut BTreeSet<String>,
    ) {
        for item in items {
            let item_gated = under_cfg || cfg_predicate(item_attrs(item)).is_some();
            match item {
                Item::Impl(node) => {
                    let Some(target) = self.index_impl_target(container, node) else {
                        continue;
                    };
                    for member in &node.items {
                        if let ImplItem::Fn(method) = member {
                            let path = format!("{target}::{}", ident_name(&method.sig.ident));
                            if item_gated || cfg_predicate(&method.attrs).is_some() {
                                gated.insert(path.clone());
                            }
                            out.insert(path);
                        }
                    }
                }
                Item::Mod(node) => {
                    if let Some((_, inner)) = &node.content {
                        let nested = format!("{container}::{}", ident_name(&node.ident));
                        self.collect_impl_methods(&nested, inner, item_gated, out, gated);
                    }
                }
                _ => {}
            }
        }
    }

    /// The type an `impl` block's methods are indexed under: its self type
    /// as the file's imports place it, within the project. A trait impl for
    /// a primitive or prelude type named bare is a block of its own (see
    /// [`Walk::impl_target`]) and indexed nowhere; so is a blanket impl.
    pub(super) fn index_impl_target(
        &self,
        container: &str,
        node: &syn::ItemImpl,
    ) -> Option<String> {
        let target = self.type_path(container, &node.self_ty)?;
        if !self.in_project(&target) {
            return None;
        }
        if node.trait_.is_some() {
            if let Type::Path(path) = node.self_ty.as_ref() {
                if path.path.segments.len() == 1
                    && is_standard_type(&ident_name(&path.path.segments[0].ident))
                    && self.stated_type(container, &path.path).is_none()
                {
                    return None;
                }
            }
        }
        Some(target)
    }

    /// Walk an `impl` block's methods onto the type it implements, with the
    /// `implements` edge of a trait impl.
    pub(super) fn walk_impl(
        &mut self,
        container: &str,
        container_kind: &str,
        node: &syn::ItemImpl,
        span: proc_macro2::Span,
    ) {
        // `impl OwnedChild` where `OwnedChild` is `#[cfg(unix)] pub use
        // unix::OwnedChild;` beside a Windows one: the block adds to
        // whichever compiles, so it is walked once per alternative.
        let head = match node.self_ty.as_ref() {
            Type::Path(path) if path.qself.is_none() && path.path.leading_colon.is_none() => path
                .path
                .segments
                .first()
                .map(|segment| ident_name(&segment.ident)),
            _ => None,
        };
        let alternatives = head
            .as_deref()
            .map(|head| self.aliases.alternatives(head))
            .unwrap_or_default();
        if let (Some(head), false) = (head, alternatives.is_empty()) {
            for alternative in alternatives {
                self.aliases.choose(&head, &alternative);
                self.walk_impl_block(container, container_kind, node, span);
                self.aliases.unchoose();
            }
            return;
        }
        self.walk_impl_block(container, container_kind, node, span);
    }

    /// [`Walk::walk_impl`] for one reading of the block's type.
    pub(super) fn walk_impl_block(
        &mut self,
        container: &str,
        container_kind: &str,
        node: &syn::ItemImpl,
        span: proc_macro2::Span,
    ) {
        let Some(target) = self.impl_target(container, container_kind, node, span) else {
            return;
        };
        // The impl target may live in another file. Its `implements`
        // and `contains` edges use it as their SOURCE, and a source
        // the contribution never declared is normally filtered in
        // `Facts::finish`; the crate-wide index vouching for it keeps
        // those edges instead of orphaning the methods. The index is
        // the only acceptable vouching: it holds only files the core
        // discovered and this worker parsed, so a vouch also
        // guarantees the declaring file has a contribution, in this
        // request or cached, whose node will resolve. A target the
        // index cannot place stays unvouched and the edges ride out
        // the old drop, because a `contains` edge whose source names
        // nothing would make reconciliation throw.
        if self.declarations.get(&target).copied() == Some(1) {
            self.facts
                .external_unless_declared(&reference("class", &target));
        }
        let old_target = self.current_impl_target.replace(target.clone());
        let old_self = self.current_impl_self.replace((*node.self_ty).clone());
        self.record_associated_types(container, &target, node);
        self.record_implements(container, &target, node, span);
        self.walk_impl_methods(container, &target, node);
        self.current_impl_target = old_target;
        self.current_impl_self = old_self;
    }

    /// The canonical path an `impl` block's members attach to, or `None` for
    /// an inherent impl that names no type.
    ///
    /// An `impl` block is not a node: it declares members of a type that is
    /// declared elsewhere, possibly in another file. Its methods are attached
    /// to the type's canonical path so both halves of a split definition land
    /// on the same node. When that type was not declared in this file,
    /// `Facts::finish` drops the resulting `contains` edge (its source was
    /// never emitted as a node here) while the method nodes themselves still
    /// stand.
    pub(super) fn impl_target(
        &mut self,
        container: &str,
        container_kind: &str,
        node: &syn::ItemImpl,
        span: proc_macro2::Span,
    ) -> Option<String> {
        let target = match (self.type_path(container, &node.self_ty), &node.trait_) {
            (Some(target), _) => target,
            // `impl<M: Matcher> Matcher for &M`: a blanket impl over a type
            // parameter, or a reference or tuple, names no type to attach to.
            // The block still implements the trait, so it becomes the
            // container, as an impl for a foreign type does below.
            (None, Some((trait_path, _))) => {
                let type_name = type_label(&node.self_ty);
                self.declare_trait_impl(container, container_kind, trait_path, &type_name, span)
            }
            (None, None) => return None,
        };
        // A type from outside this crate can only take a trait impl
        // (the orphan rule), and its path is a name the project does
        // not own: attaching methods there declared `std::sync::Arc::x`
        // with nothing tying them to the trait they implement. The
        // block itself is the project's, so it becomes the container.
        // So is a primitive or prelude type named bare that nothing in
        // scope declares or imports (`impl PartialEq<u8> for String`,
        // `impl Shout for str`), which the enclosing-module guess would
        // otherwise make a type of this module.
        let root = self.crate_root().to_owned();
        let unplaced = match node.self_ty.as_ref() {
            Type::Path(path) if path.qself.is_none() && path.path.leading_colon.is_none() => {
                path.path.segments.len() == 1
                    && is_standard_type(&ident_name(&path.path.segments[0].ident))
                    && self.stated_type(container, &path.path).is_none()
            }
            _ => false,
        };
        Some(match &node.trait_ {
            Some((trait_path, _))
                if unplaced || (target != root && !target.starts_with(&format!("{root}::"))) =>
            {
                let type_name = target.rsplit("::").next().unwrap_or(&target).to_owned();
                self.declare_trait_impl(container, container_kind, trait_path, &type_name, span)
            }
            _ => target,
        })
    }

    /// Declare the `<impl Trait for Type>` class that contains the members of
    /// an impl block with no project type to attach them to.
    pub(super) fn declare_trait_impl(
        &mut self,
        container: &str,
        container_kind: &str,
        trait_path: &syn::Path,
        type_name: &str,
        span: proc_macro2::Span,
    ) -> String {
        let trait_name = trait_path
            .segments
            .last()
            .map(|segment| ident_name(&segment.ident))
            .unwrap_or_default();
        let canonical = self.declare(
            container,
            container_kind,
            &format!("<impl {trait_name} for {type_name}>"),
            "class",
            span,
        );
        // The block is used wherever a bound on its trait is met
        // (`fn read<T: NativeValue>(..)` called with a `u32`), which leaves
        // no edge to it: it fulfils the trait, as its methods do.
        self.facts
            .node_attribute(&canonical, "trait_impl", serde_json::Value::Bool(true));
        canonical
    }

    /// Record each associated type of an impl block as an alias of the type it
    /// names, so a path through `Target::Assoc` resolves to it.
    pub(super) fn record_associated_types(
        &mut self,
        container: &str,
        target: &str,
        node: &syn::ItemImpl,
    ) {
        for member in &node.items {
            if let ImplItem::Type(associated) = member {
                if let Some(named) = self.receiver_type(container, &associated.ty) {
                    self.type_aliases.insert(
                        format!("{target}::{}", ident_name(&associated.ident)),
                        named,
                    );
                }
            }
        }
    }

    /// Emit the `implements` edge of a trait impl.
    ///
    /// `impl Trait for Type` names both endpoints explicitly, so the edge is
    /// `certain`. The trait name is resolved the same way any other path is,
    /// through `use` and the `self`/`super`/`crate` prefixes.
    pub(super) fn record_implements(
        &mut self,
        container: &str,
        target: &str,
        node: &syn::ItemImpl,
        span: proc_macro2::Span,
    ) {
        if let Some((trait_path, _)) = &node.trait_ {
            if let Some(interface) = self.path_target(container, trait_path) {
                self.facts.edge(
                    "implements",
                    &reference("class", target),
                    &reference("interface", &interface),
                    "certain",
                    span,
                );
            }
        }
    }

    /// Declare and walk the methods of an impl block under `target`, with the
    /// facts only impl methods carry: the declared return type, `overrides`
    /// for a trait impl, and `runtime_invoked` for what the runtime calls.
    pub(super) fn walk_impl_methods(
        &mut self,
        container: &str,
        target: &str,
        node: &syn::ItemImpl,
    ) {
        // `impl Drop for T` is the one trait whose method the runtime
        // calls: nothing in the graph names `drop`, so it reads as
        // unreferenced however heavily the type is used. Marked here,
        // on the trait rather than on the method name, so an ordinary
        // inherent method that happens to be called `drop` keeps its
        // reference check.
        let drop_impl = node.trait_.as_ref().is_some_and(|(path, _)| {
            path.segments
                .last()
                .is_some_and(|segment| segment.ident == "Drop")
        });
        // `#[wasm_bindgen] impl T`: JavaScript calls what the bindings
        // export, the public methods and the constructor, and nothing
        // in Rust has to.
        let exported_impl = node.attrs.iter().any(is_wasm_bindgen);
        let outer = self.enter_type_params(&node.generics);
        // A block `impl_target` declared for a trait impl with no project
        // type (`<impl Matcher for &M>`) already names the impl.
        let outer_segment = self.impl_segment.take();
        if let Some((trait_path, _)) = &node.trait_ {
            if !target.ends_with('>') {
                self.impl_segment = Some(format!("<impl {}>", path_label(trait_path)));
            }
        }
        for member in &node.items {
            if let ImplItem::Fn(method) = member {
                let name = ident_name(&method.sig.ident);
                self.walk_member_fn(
                    container,
                    target,
                    "class",
                    &method.attrs,
                    &method.sig,
                    Some(&method.block),
                    member.span(),
                    |this, method_canonical| {
                        // The declared return type, which is what a call on the
                        // result resolves through in the core (`method_of_return`).
                        // Speculative: `String` or `Vec<T>` names nothing here.
                        if let syn::ReturnType::Type(_, returned) = &method.sig.output {
                            if let Some(returned) = this
                                .receiver_type(container, returned)
                                .and_then(|returned| types::class_of(&returned).map(str::to_owned))
                            {
                                this.facts.speculative_edge(
                                    "returns",
                                    &reference("method", method_canonical),
                                    &reference("class", &returned),
                                    method.sig.output.span(),
                                );
                            }
                        }
                        // Every method of a trait impl fulfils a member the
                        // trait declares, and is reached through the trait,
                        // which may be a dependency's (`Default`, a visitor)
                        // whose members the graph cannot see.
                        if node.trait_.is_some() {
                            this.facts.node_attribute(
                                method_canonical,
                                "overrides",
                                serde_json::Value::Bool(true),
                            );
                        }
                        if (drop_impl && name == "drop")
                            || (exported_impl && matches!(method.vis, syn::Visibility::Public(_)))
                        {
                            this.facts.node_attribute(
                                method_canonical,
                                "runtime_invoked",
                                serde_json::Value::Bool(true),
                            );
                        }
                    },
                );
            }
        }
        self.impl_segment = outer_segment;
        self.type_params = outer;
    }

    /// Declare one trait or impl method under `owner` and walk its body, when
    /// it has one. A member compiled only for tests is test code, as a free
    /// function is (see `Walk::walk_items`), so the whole member sits in a
    /// test scope. `annotate` adds the facts only one kind of member carries,
    /// given the method's canonical path, between declaring it and walking
    /// its body.
    #[allow(clippy::too_many_arguments)]
    pub(super) fn walk_member_fn(
        &mut self,
        container: &str,
        owner: &str,
        owner_kind: &str,
        attrs: &[syn::Attribute],
        signature: &syn::Signature,
        block: Option<&syn::Block>,
        span: proc_macro2::Span,
        annotate: impl FnOnce(&mut Self, &str),
    ) {
        let is_test = is_cfg_test(attrs);
        self.facts.enter_test_scope_if(is_test);
        let gated = self.facts.enter_cfg_scope(cfg_predicate(attrs));
        let support = self.facts.enter_support_scope(is_cfg_test_support(attrs));
        let name = ident_name(&signature.ident);
        let method_canonical = match self.impl_segment.clone() {
            // A trait impl's method, called as `Type::method` or through a
            // value of the type: that path still reaches it when no
            // inherent method of the name exists.
            Some(segment) => {
                self.facts
                    .alias(&reference("method", &format!("{owner}::{name}")));
                self.declare_as(
                    owner,
                    owner_kind,
                    &format!("{segment}::{name}"),
                    &name,
                    "method",
                    span,
                )
            }
            None => self.declare(owner, owner_kind, &name, "method", span),
        };
        let outer = self.enter_type_params(&signature.generics);
        annotate(self, &method_canonical);
        if let Some(block) = block {
            self.walk_body(
                &reference("method", &method_canonical),
                container,
                signature,
                block,
            );
        }
        self.type_params = outer;
        self.facts.exit_support_scope(support);
        self.facts.exit_cfg_scope(gated);
        self.facts.exit_test_scope_if(is_test);
    }
}
