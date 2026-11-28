//! The syntax the function pass reads off a body: chains, the literal a
//! value verb returns, and the names a body declares or reads.

use syn::visit::Visit;

use super::handles::handle_name;
use super::literals::literal_text;
use crate::resolve::ident_name;

/// `expr` without the parentheses around it.
pub(super) fn strip_parens(expr: &syn::Expr) -> &syn::Expr {
    match expr {
        syn::Expr::Paren(paren) => strip_parens(&paren.expr),
        other => other,
    }
}

/// The literal a value verb's argument returns: `LIT`, `|…| LIT`,
/// `|…| { LIT }` or `move || LIT`.
pub(super) fn returned_literal(argument: &syn::Expr) -> Option<String> {
    let body = match argument {
        syn::Expr::Closure(closure) => closure.body.as_ref(),
        other => return literal_text(other),
    };
    match body {
        syn::Expr::Block(block) => match block.block.stmts.as_slice() {
            [syn::Stmt::Expr(expr, None)] => literal_text(expr),
            _ => None,
        },
        other => literal_text(other),
    }
}

/// Every name an item or a `use` declares anywhere in a body.
#[derive(Default)]
pub(super) struct BodyNames(pub(super) std::collections::BTreeSet<String>);

impl Visit<'_> for BodyNames {
    fn visit_item(&mut self, node: &syn::Item) {
        let ident = match node {
            syn::Item::Struct(item) => Some(&item.ident),
            syn::Item::Enum(item) => Some(&item.ident),
            syn::Item::Union(item) => Some(&item.ident),
            syn::Item::Type(item) => Some(&item.ident),
            syn::Item::Trait(item) => Some(&item.ident),
            syn::Item::Fn(item) => Some(&item.sig.ident),
            syn::Item::Mod(item) => Some(&item.ident),
            syn::Item::Const(item) => Some(&item.ident),
            syn::Item::Static(item) => Some(&item.ident),
            syn::Item::Macro(item) => item.ident.as_ref(),
            _ => None,
        };
        self.0.extend(ident.map(ident_name));
        syn::visit::visit_item(self, node);
    }

    fn visit_use_name(&mut self, node: &syn::UseName) {
        self.0.insert(ident_name(&node.ident));
    }

    fn visit_use_rename(&mut self, node: &syn::UseRename) {
        self.0.insert(ident_name(&node.rename));
    }
}

/// Every plain name an expression reads, macro arguments included.
#[derive(Default)]
pub(super) struct Named(pub(super) std::collections::BTreeSet<String>);

impl Visit<'_> for Named {
    fn visit_expr_path(&mut self, node: &syn::ExprPath) {
        self.0.extend(handle_name(node));
    }

    fn visit_macro(&mut self, node: &syn::Macro) {
        identifiers(node.tokens.clone(), &mut self.0);
    }
}

/// Every identifier in `tokens`, at any depth.
pub(super) fn identifiers(
    tokens: proc_macro2::TokenStream,
    out: &mut std::collections::BTreeSet<String>,
) {
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
