//! Walking one parsed file into nodes and containment edges.
//!
//! Items are walked with an explicit container stack rather than
//! `syn::visit::Visit`, because every node needs the canonical path of its
//! parent and a visitor's callbacks do not carry one.

use std::collections::{BTreeMap, BTreeSet};

use syn::spanned::Spanned;
use syn::{Item, TraitItem};

use crate::facts::{reference, Facts};
use crate::layout::Layout;
use crate::resolve::{ident_name, Aliases};

mod bindings;
mod block_uses;
mod body;
mod calls;
mod cfg;
mod declaration_paths;
mod declarations;
mod fields;
mod globs;
mod impls;
mod item_types;
mod labels;
mod launches;
mod macros;
mod paths;
mod placement;
mod receivers;
mod routes;
mod state;
mod types;
mod uses;

use calls::{is_foreign_export, is_wasm_bindgen};
pub use cfg::include_sites;
use cfg::{cfg_predicate, is_cfg_test, is_cfg_test_support, is_test_attribute, item_attrs};
pub use cfg::{
    collect_test_modules, is_test_module_path, is_test_support_module_path, ModuleGates,
};
pub use declaration_paths::{declaration_paths, declared_renames, indexed_item_paths};
pub use declarations::{
    Declarations, ExportAlternatives, ExportedNames, FieldType, FunctionReturns, GlobImports,
    StructFields, TypeAliases,
};
use labels::{path_label, type_label};
use placement::mod_child;
pub use placement::mod_declarations;
use state::Walk;

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
    walker.collect_implemented_types(module, &file.items);
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
    walker.collect_implemented_types(module, items);
    // The glob sources a field type may come through, as this file alone
    // places them; which one provides a name is asked at lookup.
    walker.resolve_globs();
    walker.collect_type_aliases(module, items);
    walker.collect_struct_fields(module, items);
    walker.collect_function_returns(module, items);

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
        returns: std::mem::take(&mut walker.function_returns),
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
    /// See [`FunctionReturns`].
    pub returns: FunctionReturns,
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
            type_params: BTreeMap::new(),
            current_impl_target: None,
            current_impl_self: None,
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
            implemented_types: BTreeSet::new(),
            type_aliases: BTreeMap::new(),
        }
    }
}

impl Walk<'_> {
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
}

#[cfg(test)]
mod tests;
