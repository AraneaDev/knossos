//! Walking one parsed file into nodes and containment edges.
//!
//! Items are walked with an explicit container stack rather than
//! `syn::visit::Visit`, because every node needs the canonical path of its
//! parent and a visitor's callbacks do not carry one.

use std::collections::{BTreeMap, BTreeSet};

use syn::spanned::Spanned;
use syn::visit::Visit;
use syn::{ImplItem, Item, TraitItem, Type};

use crate::facts::{reference, Facts};
use crate::layout::Layout;
use crate::resolve::{ident_name, is_standard_type, Aliases};

mod calls;
mod cfg;
mod declarations;
mod launches;
mod paths;
mod placement;
mod routes;
mod state;
mod types;

use calls::{is_foreign_export, is_wasm_bindgen};
pub use cfg::include_sites;
use cfg::{cfg_predicate, is_cfg_test, is_cfg_test_support, is_test_attribute, item_attrs};
pub use cfg::{
    collect_test_modules, is_test_module_path, is_test_support_module_path, ModuleGates,
};
pub use declarations::{
    declaration_paths, declared_renames, gated_declaration_paths, indexed_item_paths, Declarations,
    ExportAlternatives, ExportedNames, FieldType, GlobImports, StructFields, TypeAliases,
};
use placement::mod_child;
pub use placement::mod_declarations;
use state::{Calls, Walk};

/// Walk every item in a parsed file, attributing each to `module`, and
/// return the files its `mod` declarations load (see [`mod_child`]).
pub fn walk(
    facts: &mut Facts,
    module: &str,
    file: &syn::File,
    frameworks: &[String],
    declarations: &Declarations,
    test_modules: &ModuleGates,
    layout: &Layout,
) -> BTreeSet<String> {
    // A file that IS an out-of-line test module is test code in its entirety,
    // and so is anything nested below it, so the scope opens around the whole
    // walk rather than around a single item.
    let file_is_test = is_test_module_path(module, test_modules);
    if file_is_test {
        facts.enter_test_scope();
    }
    let file_gate = facts.enter_cfg_scope(test_modules.gate_of_file(facts.relative(), module));
    let file_support = facts.enter_support_scope(is_test_support_module_path(module, test_modules));
    let mut walker = Walk::new(facts, module, frameworks, declarations, layout);
    walker.own_declarations = declaration_paths(module, &file.items);
    walker.collect_uses(module, &file.items);
    walker.resolve_globs();
    walker.collect_type_aliases(module, &file.items);
    walker.collect_struct_fields(module, &file.items);
    walker.collect_function_returns(module, &file.items);
    walker.walk_items(module, "module", &file.items);
    walker.flush_routes();
    let placed = std::mem::take(&mut walker.placed);
    facts.exit_support_scope(file_support);
    facts.exit_cfg_scope(file_gate);
    if file_is_test {
        facts.exit_test_scope();
    }

    placed
}

/// What one file tells the declaration index beyond the paths it declares:
/// the names its `use` items re-export and the field types of its
/// structs, each resolved through the file's own imports as its walk would
/// resolve them, before any other file is known. See [`ExportedNames`] and
/// [`StructFields`].
#[must_use]
pub fn index_facts(relative: &str, module: &str, items: &[Item], layout: &Layout) -> IndexFacts {
    // Nothing here is emitted: the facts and the empty index only satisfy
    // the walk's resolution, which falls back on neither for a path the
    // file roots or imports.
    let mut facts = Facts::new(relative);
    let declarations = Declarations::new();
    let mut walker = Walk::new(&mut facts, module, &[], &declarations, layout);
    walker.own_declarations = declaration_paths(module, items);
    walker.collect_uses(module, items);
    // The glob sources a field type may come through, as this file alone
    // places them; which one provides a name is asked at lookup.
    walker.resolve_globs();
    walker.collect_type_aliases(module, items);
    walker.collect_struct_fields(module, items);

    // Each module-level alias, as this file's own imports resolve the type
    // it names; an impl's associated type is only ever named from its impl.
    let aliases = std::mem::take(&mut walker.type_aliases);

    let alternatives = std::mem::take(&mut walker.export_alternatives)
        .into_iter()
        .filter_map(|(name, paths)| Some((name, paths.filter(|paths| paths.len() > 1)?)))
        .collect();

    let mut impl_methods = BTreeSet::new();
    let mut gated_impl_methods = BTreeSet::new();
    walker.collect_impl_methods(
        module,
        items,
        false,
        &mut impl_methods,
        &mut gated_impl_methods,
    );
    let mut globs = GlobImports::new();
    for (container, source) in std::mem::take(&mut walker.globs) {
        let private = walker
            .private_globs
            .contains(&(container.clone(), source.clone()));
        globs.entry(container).or_default().push((source, private));
    }

    let private_exports = walker
        .private_exports
        .difference(&walker.public_exports)
        .cloned()
        .collect();

    IndexFacts {
        impl_methods,
        gated_impl_methods,
        private_exports,
        exports: std::mem::take(&mut walker.exports),
        fields: std::mem::take(&mut walker.struct_fields),
        aliases,
        export_alternatives: alternatives,
        globs,
    }
}

/// What [`index_facts`] reads from one file for the declaration index.
#[derive(Clone, Debug, Default)]
pub struct IndexFacts {
    /// The path of every method the file's `impl` blocks declare, under the
    /// type the file's own imports resolve the block's to (`impl
    /// BrokerState` beside `use super::BrokerState;` declares its methods on
    /// the parent's type, not on one of the file's module).
    pub impl_methods: BTreeSet<String>,
    /// The ones a `cfg` gates, its own, its block's, or an enclosing inline
    /// module's.
    pub gated_impl_methods: BTreeSet<String>,
    /// See [`ExportedNames`].
    pub exports: ExportedNames,
    /// The names of `exports` only a private `use` binds, which a glob
    /// brings in only below the module (see `Walk::through_globs`).
    pub private_exports: BTreeSet<String>,
    /// See [`StructFields`].
    pub fields: StructFields,
    /// See [`TypeAliases`].
    pub aliases: TypeAliases,
    /// See [`ExportAlternatives`].
    pub export_alternatives: ExportAlternatives,
    /// See [`GlobImports`].
    pub globs: GlobImports,
}

impl<'a> Walk<'a> {
    /// A walk of the file `facts` is for, placed in `module`, with nothing
    /// collected yet.
    fn new(
        facts: &'a mut Facts,
        module: &str,
        frameworks: &'a [String],
        declarations: &'a Declarations,
        layout: &'a Layout,
    ) -> Self {
        let relative = facts.relative().to_owned();
        let root = module.split("::").next().unwrap_or("crate").to_owned();
        let crate_module = if layout.is_target_root(&relative) {
            module.to_owned()
        } else {
            root
        };
        Walk {
            facts,
            module: module.to_owned(),
            aliases: Aliases::default(),
            globs: Vec::new(),
            pending_globs: Vec::new(),
            private_globs: BTreeSet::new(),
            module_aliases: BTreeMap::new(),
            module_children: BTreeMap::new(),
            type_params: BTreeSet::new(),
            current_impl_target: None,
            impl_segment: None,
            frameworks,
            declarations,
            routes: Vec::new(),
            role_marks: Vec::new(),
            struct_fields: BTreeMap::new(),
            function_returns: BTreeMap::new(),
            layout,
            relative,
            renamed_children: BTreeMap::new(),
            crate_module,
            placed: BTreeSet::new(),
            exports: BTreeMap::new(),
            export_alternatives: BTreeMap::new(),
            private_exports: BTreeSet::new(),
            public_exports: BTreeSet::new(),
            own_declarations: BTreeSet::new(),
            type_aliases: BTreeMap::new(),
        }
    }
}

impl Walk<'_> {
    /// Record the path of every method the `impl` blocks of `items`
    /// (inline modules included) declare, as [`Walk::walk_impl`] would
    /// declare it, in `out`, and the `cfg`-gated ones in `gated`. A block
    /// whose type the file cannot place inside the project, or that only a
    /// trait impl for a standard type is, declares nothing to the index.
    fn collect_impl_methods(
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
    fn index_impl_target(&self, container: &str, node: &syn::ItemImpl) -> Option<String> {
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

    /// Walk one item list whose declarations belong to `container`.
    ///
    /// `container_kind` is the node kind of `container` itself (`module`, `class`,
    /// or `interface`), needed to build the `contains` edge's source reference —
    /// an edge endpoint carries a node kind that isn't always the edge's own kind.
    ///
    /// An item compiled only under `test` (`#[cfg(test)] fn helper()`) is
    /// test code with everything it declares; a module opens that scope in
    /// [`Walk::walk_mod`], so it is not opened twice here.
    fn walk_items(&mut self, container: &str, container_kind: &str, items: &[Item]) {
        for item in items {
            let is_test = !matches!(item, Item::Mod(_)) && is_cfg_test(item_attrs(item));
            if is_test {
                self.facts.enter_test_scope();
            }
            let gated = self.facts.enter_cfg_scope(cfg_predicate(item_attrs(item)));
            let support = self
                .facts
                .enter_support_scope(is_cfg_test_support(item_attrs(item)));
            self.walk_item(container, container_kind, item);
            self.facts.exit_support_scope(support);
            self.facts.exit_cfg_scope(gated);
            if is_test {
                self.facts.exit_test_scope();
            }
        }
    }

    /// Walk one item, emitting its node and recursing into anything it holds.
    fn walk_item(&mut self, container: &str, container_kind: &str, item: &Item) {
        match item {
            Item::Struct(node) => {
                let canonical = self.declare(
                    container,
                    container_kind,
                    &ident_name(&node.ident),
                    "class",
                    item.span(),
                );
                self.walk_type_declaration(
                    &reference("class", &canonical),
                    container,
                    &node.attrs,
                    &[&node.fields],
                    &[],
                );
            }
            Item::Enum(node) => {
                let canonical = self.declare(
                    container,
                    container_kind,
                    &ident_name(&node.ident),
                    "class",
                    item.span(),
                );
                let fields: Vec<&syn::Fields> = node.variants.iter().map(|v| &v.fields).collect();
                let variant_attrs: Vec<&[syn::Attribute]> =
                    node.variants.iter().map(|v| v.attrs.as_slice()).collect();
                self.walk_type_declaration(
                    &reference("class", &canonical),
                    container,
                    &node.attrs,
                    &fields,
                    &variant_attrs,
                );
            }
            Item::Const(node) if container_kind == "module" => {
                self.walk_module_value(container, &node.ty, &node.expr);
            }
            Item::Static(node) if container_kind == "module" => {
                self.walk_module_value(container, &node.ty, &node.expr);
            }
            Item::Union(node) => {
                self.declare(
                    container,
                    container_kind,
                    &ident_name(&node.ident),
                    "class",
                    item.span(),
                );
            }
            Item::Fn(node) => self.walk_fn(container, container_kind, node, item.span()),
            Item::Trait(node) => self.walk_trait(container, container_kind, node, item.span()),
            Item::Impl(node) => self.walk_impl(container, container_kind, node, item.span()),
            Item::Mod(node) => self.walk_mod(container, container_kind, node, item.span()),
            Item::Macro(node) if node.mac.path.is_ident("macro_rules") => {
                self.walk_macro_rules(container, container_kind, &node.mac.tokens);
            }
            _ => {}
        }
    }

    /// Walk a free function: its node, its markers as a test, an entry point
    /// or a foreign export, its attribute routes, and its body.
    fn walk_fn(
        &mut self,
        container: &str,
        container_kind: &str,
        node: &syn::ItemFn,
        span: proc_macro2::Span,
    ) {
        let name = ident_name(&node.sig.ident);
        // A harness-invoked test has no caller in the graph, the same
        // way a cfg(test) module has none.
        let is_test = is_test_attribute(&node.attrs);
        if is_test {
            self.facts.enter_test_scope();
        }
        let canonical = self.declare(container, container_kind, &name, "function", span);
        // A crate-root `fn main` is what the runtime invokes — nothing
        // calls or imports it, so without the executable mark the
        // whole entry point reads as dead code.
        if container == self.module && name == "main" {
            self.facts.mark_executable();
        }
        // Exported to a foreign caller: the host embedding the library
        // calls it by its symbol, and nothing in Rust ever does.
        if is_foreign_export(node) {
            self.facts
                .node_attribute(&canonical, "runtime_invoked", serde_json::Value::Bool(true));
        }
        self.attribute_routes(&canonical, &node.attrs);
        let outer = self.enter_type_params(&node.sig.generics);
        // The declared return type, which a call on the result resolves
        // through in the core (`method_of_return`), as a method's does.
        if let syn::ReturnType::Type(_, returned) = &node.sig.output {
            if let Some(returned) = self
                .receiver_type(container, returned)
                .and_then(|returned| types::class_of(&returned).map(str::to_owned))
            {
                self.facts.speculative_edge(
                    "returns",
                    &reference("function", &canonical),
                    &reference("class", &returned),
                    node.sig.output.span(),
                );
            }
        }
        self.walk_body(
            &reference("function", &canonical),
            container,
            &node.sig,
            &node.block,
        );
        self.type_params = outer;
        if is_test {
            self.facts.exit_test_scope();
        }
    }

    /// Walk a trait: its node, the supertraits it extends, and each method it
    /// declares, with the default body when it has one.
    fn walk_trait(
        &mut self,
        container: &str,
        container_kind: &str,
        node: &syn::ItemTrait,
        span: proc_macro2::Span,
    ) {
        let canonical = self.declare(
            container,
            container_kind,
            &ident_name(&node.ident),
            "interface",
            span,
        );
        // A supertrait bound (`trait Named: Display`) names a trait the
        // declared trait extends. Only a plain trait bound is handled;
        // a lifetime bound (`trait Named: 'static`) names no node.
        for supertrait in &node.supertraits {
            if let syn::TypeParamBound::Trait(bound) = supertrait {
                if let Some(target) = self.path_target(container, &bound.path) {
                    self.facts.edge(
                        "extends",
                        &reference("interface", &canonical),
                        &reference("interface", &target),
                        "probable",
                        span,
                    );
                }
            }
        }
        let outer = self.enter_type_params(&node.generics);
        for member in &node.items {
            if let TraitItem::Fn(method) = member {
                // A trait method with no default body has no block to
                // walk: `default` is `None` for a signature-only member.
                self.walk_member_fn(
                    container,
                    &canonical,
                    "interface",
                    &method.attrs,
                    &method.sig,
                    method.default.as_ref(),
                    member.span(),
                    |_, _| {},
                );
            }
        }
        self.type_params = outer;
    }

    /// Walk an `impl` block's methods onto the type it implements, with the
    /// `implements` edge of a trait impl.
    fn walk_impl(
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
    fn walk_impl_block(
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
        self.record_associated_types(container, &target, node);
        self.record_implements(container, &target, node, span);
        self.walk_impl_methods(container, &target, node);
        self.current_impl_target = old_target;
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
    fn impl_target(
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
    fn declare_trait_impl(
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
    fn record_associated_types(&mut self, container: &str, target: &str, node: &syn::ItemImpl) {
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
    fn record_implements(
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
    fn walk_impl_methods(&mut self, container: &str, target: &str, node: &syn::ItemImpl) {
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
    fn walk_member_fn(
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

    /// Walk one `mod` item.
    ///
    /// `mod foo { .. }` (inline content) declares the module here: it gets a node
    /// plus the `contains` edge from `container`, and its items are walked under
    /// it. `mod foo;` (no content) is only a *reference* to a module declared in
    /// another file — the file itself emits that module's node from its own
    /// `crate::visit::walk` call, so declaring a second node here would duplicate
    /// it under a different evidence path and trip
    /// `reconciler.duplicate_symbol_evidence` on virtually every multi-file crate.
    /// Only the `contains` edge is emitted for that case, and nothing is walked;
    /// its target is the module the loaded file is placed in (see [`mod_child`]).
    fn walk_mod(
        &mut self,
        container: &str,
        container_kind: &str,
        node: &syn::ItemMod,
        span: proc_macro2::Span,
    ) {
        let (canonical, _) = mod_child(&self.relative, &self.module, container, node, self.layout);
        // The whole subtree compiles only under cfg(test), so the mark covers
        // the module node and every item it holds, helpers included.
        let is_test = is_cfg_test(&node.attrs);
        if is_test {
            self.facts.enter_test_scope();
        }
        if let Some((_, items)) = &node.content {
            self.facts
                .node("module", &canonical, &ident_name(&node.ident), span, span);
            self.walk_items(&canonical, "module", items);
        }
        if is_test {
            self.facts.exit_test_scope();
        }
        self.facts.edge(
            "contains",
            &reference(container_kind, container),
            &reference("module", &canonical),
            "certain",
            span,
        );
    }

    /// Emit one node plus the `contains` edge from its container, returning its
    /// bare (unprefixed) canonical path.
    ///
    /// A method separates from its container with `::` like every other Rust path,
    /// so the canonical name a reader sees is the one they would type. The
    /// returned path is what a caller passes back in as the next `container`, so
    /// it stays unprefixed — only `Facts::node` and the edge endpoints built here
    /// carry the `rust:<kind>:` reference prefix the core requires.
    fn declare(
        &mut self,
        container: &str,
        container_kind: &str,
        name: &str,
        kind: &str,
        span: proc_macro2::Span,
    ) -> String {
        self.declare_as(container, container_kind, name, name, kind, span)
    }

    /// [`Walk::declare`] for a node whose path below its container is not
    /// its display name: `<impl Driver>::receive` is shown as `receive`.
    fn declare_as(
        &mut self,
        container: &str,
        container_kind: &str,
        below: &str,
        display: &str,
        kind: &str,
        span: proc_macro2::Span,
    ) -> String {
        let canonical = format!("{container}::{below}");
        self.facts.node(kind, &canonical, display, span, span);
        self.facts.edge(
            "contains",
            &reference(container_kind, container),
            &reference(kind, &canonical),
            "certain",
            span,
        );

        canonical
    }

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
    fn walk_body(
        &mut self,
        enclosing: &str,
        container: &str,
        signature: &syn::Signature,
        block: &syn::Block,
    ) {
        let receivers = self.signature_receivers(container, signature);
        let mut visitor = Calls {
            walk: self,
            enclosing: enclosing.to_owned(),
            container: container.to_owned(),
            receivers,
            prefixes: Vec::new(),
        };
        visitor.visit_signature(signature);
        let outer = visitor.walk.enter_block_uses(container, &block.stmts);
        for statement in &block.stmts {
            visitor.visit_stmt(statement);
        }
        visitor.walk.leave_block_uses(outer);
    }

    /// Walk what a type declaration names outside any body: its fields'
    /// types, and the functions serde attributes name by string. `enclosing`
    /// is the struct, enum or union itself.
    fn walk_type_declaration(
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
            prefixes: Vec::new(),
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
    fn walk_macro_rules(
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
            prefixes: Vec::new(),
        };
        visitor.visit_token_calls(tokens.clone());
    }

    /// Walk a module-level `const` or `static`: its type and its initializer,
    /// whose calls run on behalf of the module that declares it.
    fn walk_module_value(&mut self, container: &str, ty: &Type, expr: &syn::Expr) {
        let mut visitor = Calls {
            walk: self,
            enclosing: reference("module", container),
            container: container.to_owned(),
            receivers: BTreeMap::new(),
            prefixes: Vec::new(),
        };
        visitor.visit_type(ty);
        visitor.visit_expr(expr);
    }

    /// Record the named field types of every struct in `items`, inline
    /// modules included, resolved through this file's imports, or left to
    /// its glob imports (see [`FieldType::Globbed`]). Collected before the
    /// walk, so an `impl` above its struct still sees them.
    fn collect_struct_fields(&mut self, container: &str, items: &[Item]) {
        for item in items {
            match item {
                Item::Struct(node) => {
                    let outer = self.enter_type_params(&node.generics);
                    let mut fields = BTreeMap::new();
                    for field in &node.fields {
                        if let Some(ident) = &field.ident {
                            if let Some(target) = self.declared_field_type(container, &field.ty) {
                                fields.insert(ident_name(ident), target);
                            }
                        }
                    }
                    self.type_params = outer;
                    // A struct with no typed field is recorded too, and `#[cfg]`
                    // alternatives of one struct keep only the fields they
                    // agree on: which one compiles is unknown.
                    let owner = format!("{container}::{}", ident_name(&node.ident));
                    match self.struct_fields.get_mut(&owner) {
                        Some(known) => {
                            known.retain(|name, target| fields.get(name) == Some(target));
                        }
                        None => {
                            self.struct_fields.insert(owner, fields);
                        }
                    }
                }
                Item::Mod(node) => {
                    if let Some((_, inner)) = &node.content {
                        self.collect_struct_fields(
                            &format!("{container}::{}", ident_name(&node.ident)),
                            inner,
                        );
                    }
                }
                _ => {}
            }
        }
    }

    /// Bring the type parameters `generics` declares into scope, returning
    /// the ones in scope before, for the caller to restore.
    pub(super) fn enter_type_params(&mut self, generics: &syn::Generics) -> BTreeSet<String> {
        let outer = self.type_params.clone();
        self.type_params
            .extend(generics.type_params().map(|param| ident_name(&param.ident)));
        outer
    }

    /// The receiver types a signature states: `self` as the `impl` block's
    /// type, and every plainly named parameter as its type behind any `&`.
    fn signature_receivers(
        &self,
        container: &str,
        signature: &syn::Signature,
    ) -> BTreeMap<String, String> {
        let mut receivers = BTreeMap::new();
        for input in &signature.inputs {
            match input {
                syn::FnArg::Receiver(_) => {
                    if let Some(target) = &self.current_impl_target {
                        receivers.insert("self".to_owned(), target.clone());
                    }
                }
                syn::FnArg::Typed(typed) => {
                    if let syn::Pat::Ident(ident) = typed.pat.as_ref() {
                        if let Some(target) = self.receiver_type(container, &typed.ty) {
                            receivers.insert(ident_name(&ident.ident), target);
                        }
                    }
                }
            }
        }
        receivers
    }
}

/// A path as its source spells it, generic arguments included, without
/// the spaces a token stream puts between tokens: `From<u8>`,
/// `Into<crate::Id>`, `Fn(&str) -> bool`. Only the last segment is kept
/// when the path has several (`std::fmt::Display` is `Display`), so a
/// trait named through different imports reads the same.
fn path_label(path: &syn::Path) -> String {
    let Some(segment) = path.segments.last() else {
        return "_".to_owned();
    };
    let mut label = ident_name(&segment.ident);
    match &segment.arguments {
        syn::PathArguments::None => {}
        syn::PathArguments::AngleBracketed(arguments) => {
            let parts: Vec<String> = arguments
                .args
                .iter()
                .map(|argument| match argument {
                    syn::GenericArgument::Type(ty) => type_text(ty),
                    syn::GenericArgument::Lifetime(lifetime) => format!("'{}", lifetime.ident),
                    syn::GenericArgument::AssocType(assoc) => {
                        format!("{}={}", ident_name(&assoc.ident), type_text(&assoc.ty))
                    }
                    _ => "_".to_owned(),
                })
                .collect();
            label.push_str(&format!("<{}>", parts.join(",")));
        }
        syn::PathArguments::Parenthesized(arguments) => {
            let inputs: Vec<String> = arguments
                .inputs
                .iter()
                .map(|input| type_text(&input.ty))
                .collect();
            label.push_str(&format!("({})", inputs.join(",")));
            if let syn::ReturnType::Type(_, output) = &arguments.output {
                label.push_str(&format!("->{}", type_text(output)));
            }
        }
    }
    label
}

/// A type as its source spells it, for [`path_label`]: every segment of a
/// path is kept, so `From<a::Id>` and `From<b::Id>` stay apart.
fn type_text(ty: &Type) -> String {
    match ty {
        Type::Path(path) if path.qself.is_none() => {
            let segments: Vec<String> = path
                .path
                .segments
                .iter()
                .map(|segment| {
                    let mut single = syn::Path::from(segment.ident.clone());
                    if let Some(last) = single.segments.last_mut() {
                        last.arguments = segment.arguments.clone();
                    }
                    path_label(&single)
                })
                .collect();
            segments.join("::")
        }
        Type::Reference(reference) => format!(
            "&{}{}{}",
            reference
                .lifetime
                .as_ref()
                .map(|lifetime| format!("'{} ", lifetime.ident))
                .unwrap_or_default(),
            if reference.mutability.is_some() {
                "mut "
            } else {
                ""
            },
            type_text(&reference.elem)
        ),
        Type::Slice(slice) => format!("[{}]", type_text(&slice.elem)),
        Type::Array(array) => format!("[{};_]", type_text(&array.elem)),
        Type::Tuple(tuple) => format!(
            "({})",
            tuple
                .elems
                .iter()
                .map(type_text)
                .collect::<Vec<_>>()
                .join(",")
        ),
        Type::Paren(paren) => type_text(&paren.elem),
        Type::Group(group) => type_text(&group.elem),
        _ => "_".to_owned(),
    }
}

/// A short label for a type an `impl` block names but no path places:
/// `&M`, `&mut M`, `[T]`, `(A, B)`; `_` for anything else.
fn type_label(ty: &Type) -> String {
    match ty {
        Type::Reference(reference) => format!(
            "&{}{}",
            if reference.mutability.is_some() {
                "mut "
            } else {
                ""
            },
            type_label(&reference.elem)
        ),
        Type::Path(path) => path
            .path
            .segments
            .last()
            .map(|segment| ident_name(&segment.ident))
            .unwrap_or_else(|| "_".to_owned()),
        Type::Slice(slice) => format!("[{}]", type_label(&slice.elem)),
        Type::Tuple(tuple) => format!(
            "({})",
            tuple
                .elems
                .iter()
                .map(type_label)
                .collect::<Vec<_>>()
                .join(", ")
        ),
        Type::Paren(paren) => type_label(&paren.elem),
        Type::Group(group) => type_label(&group.elem),
        _ => "_".to_owned(),
    }
}

#[cfg(test)]
mod tests;
