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

use super::assertions::{arguments, is_assertion_macro};
use super::literals::literal_text;
use crate::resolve::ident_name;
use crate::visit::FileScope;

/// How many glob and import hops a constant's name is followed through.
const MAX_HOPS: usize = 8;

/// The value-namespace items of one file, by canonical path, and the
/// literal text of each `const` and non-`mut` `static` whose initializer is
/// a literal.
#[derive(Default)]
pub(super) struct FileConsts {
    /// The text of each literal constant.
    literals: BTreeMap<String, String>,
    /// Every `const`, `static` and `fn` path the file declares: a name its
    /// module declares is that item, literal or not.
    values: BTreeSet<String>,
}

impl FileConsts {
    /// The constants of the items of the file placed in `module`, inline
    /// modules included.
    pub(super) fn of(items: &[syn::Item], module: &str) -> Self {
        let mut consts = Self::default();
        consts.collect(items, module);
        consts
    }

    /// [`FileConsts::of`] for the items of `container`.
    fn collect(&mut self, items: &[syn::Item], container: &str) {
        for item in items {
            let (name, literal) = match item {
                syn::Item::Const(node) => (&node.ident, literal_text(&node.expr)),
                syn::Item::Static(node) => (
                    &node.ident,
                    matches!(node.mutability, syn::StaticMutability::None)
                        .then(|| literal_text(&node.expr))
                        .flatten(),
                ),
                syn::Item::Fn(node) => (&node.sig.ident, None),
                syn::Item::Mod(node) => {
                    if let Some((_, inner)) = &node.content {
                        self.collect(inner, &format!("{container}::{}", ident_name(&node.ident)));
                    }
                    continue;
                }
                _ => continue,
            };
            let path = format!("{container}::{}", ident_name(name));
            if let Some(text) = literal {
                self.literals.insert(path.clone(), text);
            }
            self.values.insert(path);
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
        scope
            .globs
            .iter()
            .filter(|(importer, _)| importer == module)
            .find_map(|(_, source)| self.resolve_in(source, name, scope, hops + 1))
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
    /// Whether `unsafe` appears anywhere in the function.
    unsafe_code: bool,
    /// The file's constants.
    consts: &'c FileConsts,
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
        consts: &'c FileConsts,
        module: &str,
        scope: &'c FileScope,
    ) -> Self {
        let mut names = Names {
            bindings: BTreeMap::new(),
            in_macros: BTreeSet::new(),
            unsafe_code: false,
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
            unsafe_code: names.unsafe_code,
            consts,
            module: module.to_owned(),
            scope,
        }
    }

    /// The literal `name` holds where it is read, at `(line, column)`, or
    /// `None` when it may hold anything else.
    pub(super) fn text(&self, name: &str, at: (usize, usize)) -> Option<&str> {
        match self.bindings.get(name).copied().unwrap_or(0) {
            0 => self.consts.resolve(&self.module, name, self.scope),
            1 => {
                let (bound, text) = self.top.get(name)?;
                (*bound < at && !self.unsafe_code && !self.in_macros.contains(name))
                    .then_some(text.as_str())
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
struct Names {
    /// See [`LocalLiterals::bindings`].
    bindings: BTreeMap<String, usize>,
    /// See [`LocalLiterals::in_macros`].
    in_macros: BTreeSet<String>,
    /// See [`LocalLiterals::unsafe_code`].
    unsafe_code: bool,
}

impl Visit<'_> for Names {
    fn visit_pat_ident(&mut self, node: &syn::PatIdent) {
        *self.bindings.entry(ident_name(&node.ident)).or_insert(0) += 1;
        syn::visit::visit_pat_ident(self, node);
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
        if is_assertion_macro(node) {
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
