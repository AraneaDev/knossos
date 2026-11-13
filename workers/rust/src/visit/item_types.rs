//! The types a file's items state before any body is walked: what each
//! function returns, what each `type` alias names, and which types its
//! inherent `impl` blocks are for.

use syn::Item;

use super::state::Walk;
use crate::resolve::ident_name;

impl Walk<'_> {
    /// Record the type of every inherent `impl` block a file writes, inline
    /// modules included (see `implemented_types`): a type of this crate,
    /// in this module when written unqualified, whatever declares it.
    pub(super) fn collect_implemented_types(&mut self, module: &str, items: &[Item]) {
        for item in items {
            match item {
                Item::Impl(node) if node.trait_.is_none() => {
                    if let Some(owner) = self.type_path(module, &node.self_ty) {
                        self.implemented_types.insert(owner);
                    }
                }
                Item::Mod(inner) => {
                    if let Some((_, nested)) = &inner.content {
                        self.collect_implemented_types(
                            &format!("{module}::{}", ident_name(&inner.ident)),
                            nested,
                        );
                    }
                }
                _ => {}
            }
        }
    }

    /// Record the return type of every function and method a file declares,
    /// inline modules included (see `function_returns`). A method returning
    /// `Self` returns its impl's type. Collected before the walk, so a call
    /// above the function's declaration still sees it.
    pub(super) fn collect_function_returns(&mut self, module: &str, items: &[Item]) {
        for item in items {
            match item {
                Item::Fn(node) => {
                    let canonical = format!("{module}::{}", ident_name(&node.sig.ident));
                    self.record_return(module, canonical, &node.sig, None);
                }
                Item::Impl(node) => {
                    let Some(owner) = self.type_path(module, &node.self_ty) else {
                        continue;
                    };
                    let outer = self.enter_type_params(&node.generics);
                    for member in &node.items {
                        if let syn::ImplItem::Fn(method) = member {
                            let canonical = format!("{owner}::{}", ident_name(&method.sig.ident));
                            self.record_return(module, canonical, &method.sig, Some(&owner));
                        }
                    }
                    self.type_params = outer;
                }
                Item::Mod(inner) => {
                    if let Some((_, nested)) = &inner.content {
                        self.collect_function_returns(
                            &format!("{module}::{}", ident_name(&inner.ident)),
                            nested,
                        );
                    }
                }
                _ => {}
            }
        }
    }

    /// Record what one signature returns under `canonical`, `Self` read as
    /// `owner` when the signature is a method's.
    pub(super) fn record_return(
        &mut self,
        container: &str,
        canonical: String,
        signature: &syn::Signature,
        owner: Option<&str>,
    ) {
        let syn::ReturnType::Type(_, returned) = &signature.output else {
            self.record_returned(canonical, None);
            return;
        };
        let outer = self.enter_type_params(&signature.generics);
        // `Self`, bare or wrapped (`Result<Self>`), is the impl's type.
        let outer_target = self.current_impl_target.clone();
        if let Some(owner) = owner {
            self.current_impl_target = Some(owner.to_owned());
        }
        let stated = self.receiver_type(container, returned);
        self.current_impl_target = outer_target;
        self.type_params = outer;
        self.record_returned(canonical, stated);
    }

    /// Record that the declaration at `canonical` returns `stated`. A second
    /// declaration at the same path with another return type, or one this
    /// file cannot resolve, leaves the path ambiguous.
    pub(super) fn record_returned(&mut self, canonical: String, stated: Option<String>) {
        let returned = match self.function_returns.get(&canonical) {
            Some(earlier) if *earlier != stated => None,
            _ => stated,
        };
        self.function_returns.insert(canonical, returned);
    }

    /// Record the `type` aliases a file declares at module level, and in its
    /// inline modules, by canonical path (see `type_aliases`).
    pub(super) fn collect_type_aliases(&mut self, module: &str, items: &[Item]) {
        for item in items {
            match item {
                Item::Type(alias) => {
                    if let Some(named) = self.receiver_type(module, &alias.ty) {
                        self.type_aliases
                            .insert(format!("{module}::{}", ident_name(&alias.ident)), named);
                    }
                }
                Item::Mod(inner) => {
                    if let Some((_, nested)) = &inner.content {
                        self.collect_type_aliases(
                            &format!("{module}::{}", ident_name(&inner.ident)),
                            nested,
                        );
                    }
                }
                _ => {}
            }
        }
    }
}
