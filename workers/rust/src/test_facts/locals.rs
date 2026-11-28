//! Which names in a test hold a literal for certain (Decision 8, the R12 and
//! R14 analogs).
//!
//! A local `let NAME = LIT;` is a literal when it is a top-level statement
//! of the body before the assertion, not `mut`, the only binding of `NAME`
//! anywhere in the function (`let`, `if let`, `while let`, a `match` arm,
//! `for`, a closure or function parameter, in any nested block), no `unsafe`
//! appears in the function, and no macro but an assertion is handed `NAME`
//! (it could expand to a `let NAME` that shadows it). A `const` or
//! non-`mut` `static` of the same file is a literal when the function's
//! module reaches it as the walk resolves names (its own module, its
//! imports, its glob imports such as `use super::*`), its initializer is a
//! literal, and the function binds nothing of that name.
//!
//! Everything else is unknown: a `static mut`, a constant of another file
//! (its initializer is not in the index, G5-07), a field, and every call.
//! Shadowing, `&mut` borrows, captures and interior mutability need no rule
//! of their own: a second binding fails the only-binding rule, a write
//! through a borrow needs `mut`, a literal-typed value has no interior
//! mutability, and a write through `unsafe` fails the `unsafe` rule.

use std::collections::{BTreeMap, BTreeSet};

use syn::visit::Visit;

use super::assertions::arguments;
use super::literals::literal_text;
use super::macros::Frozen;
use crate::resolve::ident_name;
use crate::visit::FileScope;

/// How many glob and import hops a constant's name is followed through.
const MAX_HOPS: usize = 8;

/// The value-namespace items of one file, by canonical path, and the
/// literal text of each `const` and non-`mut` `static` whose initializer is
/// a literal.
pub(super) struct FileConsts<'d> {
    /// The text of each literal constant.
    literals: BTreeMap<String, String>,
    /// Every `const`, `static` and `fn` path the file declares: a name its
    /// module declares is that item, literal or not.
    values: BTreeSet<String>,
    /// The paths declared more than once (`cfg` alternatives), which hold
    /// no one literal.
    repeated: BTreeSet<String>,
    /// Every name a module-level `use` binds there, by path: beside a
    /// `const` of that name, a `cfg` alternative of it.
    imported: BTreeSet<String>,
    /// The modules that invoke a macro in item position
    /// (`define_limit!();`), which may declare any name there.
    macro_modules: BTreeSet<String>,
    /// Which macros declare no item.
    pub(super) frozen: Frozen<'d>,
}

impl<'d> FileConsts<'d> {
    /// The constants of the items of the file placed in `module`, inline
    /// modules included; `gated` when a `cfg` on the declaration that
    /// loads the file gates all of it.
    pub(super) fn of(items: &[syn::Item], module: &str, gated: bool, frozen: Frozen<'d>) -> Self {
        let mut consts = Self {
            literals: BTreeMap::new(),
            values: BTreeSet::new(),
            repeated: BTreeSet::new(),
            imported: BTreeSet::new(),
            macro_modules: BTreeSet::new(),
            frozen,
        };
        consts.collect(items, module, gated);
        consts
    }

    /// [`FileConsts::of`] for the items of `container`, all of them gated
    /// when `gated` is: a `cfg` on an enclosing module, or a module declared
    /// twice (`cfg` alternatives), has another value in some other build.
    fn collect(&mut self, items: &[syn::Item], container: &str, gated: bool) {
        let mut modules = BTreeMap::new();
        for item in items {
            if let syn::Item::Mod(node) = item {
                *modules.entry(ident_name(&node.ident)).or_insert(0_usize) += 1;
            }
        }
        for item in items {
            let (name, literal) = match item {
                syn::Item::Const(node) => (
                    &node.ident,
                    (!gated && !is_gated(&node.attrs))
                        .then(|| literal_text(&node.expr))
                        .flatten(),
                ),
                syn::Item::Static(node) => (
                    &node.ident,
                    (matches!(node.mutability, syn::StaticMutability::None)
                        && !gated
                        && !is_gated(&node.attrs))
                    .then(|| literal_text(&node.expr))
                    .flatten(),
                ),
                syn::Item::Fn(node) => (&node.sig.ident, None),
                syn::Item::Use(node) => {
                    let mut names = BTreeSet::new();
                    use_names(&node.tree, &mut names, &mut false);
                    self.imported
                        .extend(names.iter().map(|name| format!("{container}::{name}")));
                    continue;
                }
                syn::Item::Macro(node) if node.ident.is_none() => {
                    if self.frozen.declares_items(&node.mac) {
                        self.macro_modules.insert(container.to_owned());
                    }
                    continue;
                }
                syn::Item::Mod(node) => {
                    if let Some((_, inner)) = &node.content {
                        let name = ident_name(&node.ident);
                        let twice = modules.get(&name).copied().unwrap_or(0) > 1;
                        self.collect(
                            inner,
                            &format!("{container}::{name}"),
                            gated || twice || is_gated(&node.attrs),
                        );
                    }
                    continue;
                }
                _ => continue,
            };
            let path = format!("{container}::{}", ident_name(name));
            if let Some(text) = literal {
                self.literals.insert(path.clone(), text);
            }
            if !self.values.insert(path.clone()) {
                self.repeated.insert(path);
            }
        }
    }

    /// The literal `name`, written in `module`, names, followed as the
    /// walk resolves it: the module's own item, then its import of that
    /// name, then its glob imports. `None` when it names anything else, or
    /// a name of another file.
    pub(super) fn resolve(&self, module: &str, name: &str, scope: &FileScope) -> Option<&str> {
        self.resolve_in(module, name, scope, 0)
    }

    /// [`FileConsts::resolve`], `hops` imports deep.
    fn resolve_in(&self, module: &str, name: &str, scope: &FileScope, hops: usize) -> Option<&str> {
        if hops > MAX_HOPS {
            return None;
        }
        let own = format!("{module}::{name}");
        if self.macro_modules.contains(module)
            || self.repeated.contains(&own)
            || (self.values.contains(&own) && self.imported.contains(&own))
        {
            return None;
        }
        if self.values.contains(&own) {
            return self.literals.get(&own).map(String::as_str);
        }
        if let Some(imported) = scope
            .module_aliases
            .get(&(module.to_owned(), name.to_owned()))
        {
            let (parent, last) = imported.as_deref()?.rsplit_once("::")?;
            return self.resolve_in(parent, last, scope, hops + 1);
        }
        // Two globs that bring in the name apart make it ambiguous.
        let provided: BTreeSet<&str> = scope
            .globs
            .iter()
            .filter(|(importer, _)| importer == module)
            .filter_map(|(_, source)| self.resolve_in(source, name, scope, hops + 1))
            .collect();
        match provided.into_iter().collect::<Vec<_>>().as_slice() {
            [one] => Some(one),
            _ => None,
        }
    }
}

/// What one function's body says about the names in it: which are bound
/// once to a literal at the top, which are bound at all, and whether
/// anything there could write a name out of sight.
pub(super) struct LocalLiterals<'c> {
    /// Each top-level `let NAME = LIT;`, not `mut`, with where it starts
    /// (line, column) and the literal's text.
    top: BTreeMap<String, ((usize, usize), String)>,
    /// How many times each name is bound anywhere in the function.
    bindings: BTreeMap<String, usize>,
    /// Every identifier handed to a macro other than an assertion.
    in_macros: BTreeSet<String>,
    /// Every name an item in the body declares or imports (`use`,
    /// `const`, `static`, `fn`…), which shadows a local or a constant of
    /// the same name for the rest of its block.
    items: BTreeSet<String>,
    /// Whether the body glob-imports (`use m::*;`) or invokes a macro where
    /// an item may stand (`define_limit!();`), either of which may bring in
    /// any name.
    glob: bool,
    /// Whether `unsafe` appears anywhere in the function.
    unsafe_code: bool,
    /// The file's constants.
    consts: &'c FileConsts<'c>,
    /// The module the function is declared in.
    module: String,
    /// The file's resolution state.
    scope: &'c FileScope,
}

impl<'c> LocalLiterals<'c> {
    /// Read one function (its signature and body), declared in `module`.
    pub(super) fn of(
        signature: &syn::Signature,
        block: &syn::Block,
        consts: &'c FileConsts<'c>,
        module: &str,
        scope: &'c FileScope,
    ) -> Self {
        let mut names = Names {
            bindings: BTreeMap::new(),
            in_macros: BTreeSet::new(),
            items: BTreeSet::new(),
            glob: false,
            unsafe_code: false,
            frozen: &consts.frozen,
        };
        names.visit_signature(signature);
        names.visit_block(block);
        let mut top = BTreeMap::new();
        for statement in &block.stmts {
            if let Some((name, at, text)) = literal_let(statement) {
                top.insert(name, (at, text));
            }
        }
        Self {
            top,
            bindings: names.bindings,
            in_macros: names.in_macros,
            items: names.items,
            glob: names.glob,
            unsafe_code: names.unsafe_code,
            consts,
            module: module.to_owned(),
            scope,
        }
    }

    /// The literal `name` holds where it is read, at `(line, column)`, or
    /// `None` when it may hold anything else.
    pub(super) fn text(&self, name: &str, at: (usize, usize)) -> Option<&str> {
        // An item, a glob or a macro in the body may declare the name, out
        // of the reach of the pattern count: whatever it names is unknown.
        if self.glob || self.items.contains(name) || self.in_macros.contains(name) {
            return None;
        }
        match self.bindings.get(name).copied().unwrap_or(0) {
            0 => self.consts.resolve(&self.module, name, self.scope),
            1 => {
                let (bound, text) = self.top.get(name)?;
                (*bound < at && !self.unsafe_code).then_some(text.as_str())
            }
            _ => None,
        }
    }
}

/// A top-level `let NAME = LIT;` or `let NAME: T = LIT;`, not `mut`: its
/// name, where it starts (line, column) and its literal's text.
fn literal_let(statement: &syn::Stmt) -> Option<(String, (usize, usize), String)> {
    let syn::Stmt::Local(local) = statement else {
        return None;
    };
    let pattern = match &local.pat {
        syn::Pat::Type(typed) => typed.pat.as_ref(),
        other => other,
    };
    let syn::Pat::Ident(ident) = pattern else {
        return None;
    };
    let init = local.init.as_ref()?;
    if ident.mutability.is_some()
        || ident.by_ref.is_some()
        || ident.subpat.is_some()
        || init.diverge.is_some()
    {
        return None;
    }
    let at = local.let_token.span.start();
    Some((
        ident_name(&ident.ident),
        (at.line, at.column),
        literal_text(&init.expr)?,
    ))
}

/// A visitor over one function, counting what [`LocalLiterals`] needs.
struct Names<'f> {
    /// See [`LocalLiterals::bindings`].
    bindings: BTreeMap<String, usize>,
    /// See [`LocalLiterals::in_macros`].
    in_macros: BTreeSet<String>,
    /// See [`LocalLiterals::items`].
    items: BTreeSet<String>,
    /// See [`LocalLiterals::glob`].
    glob: bool,
    /// See [`LocalLiterals::unsafe_code`].
    unsafe_code: bool,
    /// Which macros declare no item.
    frozen: &'f Frozen<'f>,
}

impl Visit<'_> for Names<'_> {
    fn visit_pat_ident(&mut self, node: &syn::PatIdent) {
        *self.bindings.entry(ident_name(&node.ident)).or_insert(0) += 1;
        syn::visit::visit_pat_ident(self, node);
    }

    fn visit_item(&mut self, node: &syn::Item) {
        declared_names(node, &mut self.items, &mut self.glob);
        syn::visit::visit_item(self, node);
    }

    fn visit_stmt_macro(&mut self, node: &syn::StmtMacro) {
        self.glob |= self.frozen.declares_items(&node.mac);
        syn::visit::visit_stmt_macro(self, node);
    }

    fn visit_expr_unsafe(&mut self, node: &syn::ExprUnsafe) {
        self.unsafe_code = true;
        syn::visit::visit_expr_unsafe(self, node);
    }

    fn visit_signature(&mut self, node: &syn::Signature) {
        self.unsafe_code |= matches!(node.safety, syn::Safety::Unsafe(_));
        syn::visit::visit_signature(self, node);
    }

    fn visit_macro(&mut self, node: &syn::Macro) {
        // An assertion is read as the expressions it takes, which bind
        // nothing out of sight; any other macro may expand to anything.
        if self.frozen.is_assertion(node) {
            if let Some(arguments) = arguments(node) {
                for argument in &arguments {
                    self.visit_expr(argument);
                }
                return;
            }
        }
        identifiers(node.tokens.clone(), &mut self.in_macros);
        self.unsafe_code |= self.in_macros.contains("unsafe");
    }
}

/// Whether an item carries a `cfg` or `cfg_attr` attribute: some build
/// compiles another declaration in its place.
fn is_gated(attrs: &[syn::Attribute]) -> bool {
    attrs
        .iter()
        .any(|attr| attr.path().is_ident("cfg") || attr.path().is_ident("cfg_attr"))
}

/// The names an item declares or imports into its block, with `glob` set
/// for a glob import.
fn declared_names(item: &syn::Item, out: &mut BTreeSet<String>, glob: &mut bool) {
    // Only what can stand where a value is read: a unit or tuple struct is
    // one, an enum, a trait or a module never is.
    let ident = match item {
        syn::Item::Const(node) => &node.ident,
        syn::Item::Static(node) => &node.ident,
        syn::Item::Fn(node) => &node.sig.ident,
        syn::Item::Struct(node) => &node.ident,
        syn::Item::Use(node) => return use_names(&node.tree, out, glob),
        syn::Item::ForeignMod(node) => {
            for foreign in &node.items {
                match foreign {
                    syn::ForeignItem::Fn(function) => out.insert(ident_name(&function.sig.ident)),
                    syn::ForeignItem::Static(value) => out.insert(ident_name(&value.ident)),
                    _ => false,
                };
            }
            return;
        }
        _ => return,
    };
    out.insert(ident_name(ident));
}

/// The names one `use` tree binds, with `glob` set for a glob.
fn use_names(tree: &syn::UseTree, out: &mut BTreeSet<String>, glob: &mut bool) {
    match tree {
        syn::UseTree::Path(path) => use_names(&path.tree, out, glob),
        syn::UseTree::Name(name) => {
            out.insert(ident_name(&name.ident));
        }
        syn::UseTree::Rename(rename) => {
            out.insert(ident_name(&rename.rename));
        }
        syn::UseTree::Glob(_) => *glob = true,
        syn::UseTree::Group(group) => {
            for inner in &group.items {
                use_names(inner, out, glob);
            }
        }
    }
}

/// Every identifier in `tokens`, at any depth.
fn identifiers(tokens: proc_macro2::TokenStream, out: &mut BTreeSet<String>) {
    for token in tokens {
        match token {
            proc_macro2::TokenTree::Ident(ident) => {
                out.insert(ident_name(&ident));
            }
            proc_macro2::TokenTree::Group(group) => identifiers(group.stream(), out),
            _ => {}
        }
    }
}
