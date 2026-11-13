//! The types a struct's or variant's fields declare, the type parameters
//! in scope, and the receiver types a signature gives a body.

use std::collections::{BTreeMap, BTreeSet};

use syn::Item;

use super::*;
use crate::resolve::ident_name;

impl Walk<'_> {
    /// Record the named field types of every struct in `items`, inline
    /// modules included, resolved through this file's imports, or left to
    /// its glob imports (see [`FieldType::Globbed`]). Collected before the
    /// walk, so an `impl` above its struct still sees them.
    pub(super) fn collect_struct_fields(&mut self, container: &str, items: &[Item]) {
        for item in items {
            match item {
                Item::Struct(node) => {
                    let outer = self.enter_type_params(&node.generics);
                    let mut fields = self.declared_fields(container, &node.fields);
                    param_fields(&node.generics, &node.fields, &mut fields);
                    self.type_params = outer;
                    let owner = format!("{container}::{}", ident_name(&node.ident));
                    self.record_fields(owner, fields);
                }
                // A variant's fields, under the variant's path, are what a
                // pattern of it binds (`Shape::Circle { radius }`).
                Item::Enum(node) => {
                    let outer = self.enter_type_params(&node.generics);
                    let enumeration = format!("{container}::{}", ident_name(&node.ident));
                    for variant in &node.variants {
                        let fields = self.declared_fields(container, &variant.fields);
                        let owner = format!("{enumeration}::{}", ident_name(&variant.ident));
                        self.record_fields(owner, fields);
                    }
                    self.type_params = outer;
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

    /// The declared type of each field, by name, or by position (`0`, `1`)
    /// for a tuple struct's or tuple variant's.
    pub(super) fn declared_fields(
        &self,
        container: &str,
        fields: &syn::Fields,
    ) -> BTreeMap<String, FieldType> {
        let mut types = BTreeMap::new();
        // A name declared again is a `cfg` alternative of the field: it holds
        // one of the types, each under the same marks (see
        // `ALTERNATIVE_MARK`). Alternatives that differ otherwise, or one
        // whose type is unknown, leave the field untyped.
        let mut untyped = BTreeSet::new();
        for (position, field) in fields.iter().enumerate() {
            let name = field
                .ident
                .as_ref()
                .map_or_else(|| position.to_string(), ident_name);
            if untyped.contains(&name) {
                continue;
            }
            let declared = self.declared_field_type(container, &field.ty);
            let merged = match (types.remove(&name), declared) {
                (None, declared) => declared,
                (Some(earlier), Some(next)) => alternative_field(earlier, next),
                (Some(_), None) => None,
            };
            match merged {
                Some(target) => {
                    types.insert(name, target);
                }
                None if field.ident.is_some() => {
                    untyped.insert(name);
                }
                None => {}
            }
        }
        types
    }

    /// Record the field types of one struct or variant. One with no typed
    /// field is recorded too, and `#[cfg]` alternatives of one keep only the
    /// fields they agree on: which one compiles is unknown.
    pub(super) fn record_fields(&mut self, owner: String, fields: BTreeMap<String, FieldType>) {
        match self.struct_fields.get_mut(&owner) {
            Some(known) => {
                known.retain(|name, target| fields.get(name) == Some(target));
            }
            None => {
                self.struct_fields.insert(owner, fields);
            }
        }
    }

    /// Bring the type parameters `generics` declares into scope, returning
    /// the ones in scope before, for the caller to restore.
    pub(super) fn enter_type_params(
        &mut self,
        generics: &syn::Generics,
    ) -> BTreeMap<String, Vec<syn::Path>> {
        let outer = self.type_params.clone();
        for param in generics.type_params() {
            self.type_params
                .insert(ident_name(&param.ident), trait_paths(&param.bounds));
        }
        // `where D: Driver` bounds a parameter declared above.
        for predicate in generics.where_clause.iter().flat_map(|w| &w.predicates) {
            let syn::WherePredicate::Type(typed) = predicate else {
                continue;
            };
            let syn::Type::Path(bounded) = &typed.bounded_ty else {
                continue;
            };
            let Some(name) = bounded.path.get_ident().map(ident_name) else {
                continue;
            };
            if let Some(bounds) = self.type_params.get_mut(&name) {
                bounds.extend(trait_paths(&typed.bounds));
            }
        }
        outer
    }

    /// The type arguments of each receiver [`Walk::signature_receivers`]
    /// typed, with the type they were read for: `self` takes the `impl`
    /// block's (`impl<E: Environment> Context<'_, E>`), and a parameter
    /// the ones written in its type.
    pub(super) fn signature_arguments(
        &self,
        container: &str,
        signature: &syn::Signature,
        receivers: &BTreeMap<String, String>,
    ) -> BTreeMap<String, (String, Vec<Option<String>>)> {
        let mut arguments = BTreeMap::new();
        for input in &signature.inputs {
            let (name, ty) = match input {
                syn::FnArg::Receiver(_) => match &self.current_impl_self {
                    Some(ty) => ("self".to_owned(), ty),
                    None => continue,
                },
                syn::FnArg::Typed(typed) => match typed.pat.as_ref() {
                    syn::Pat::Ident(ident) => (ident_name(&ident.ident), typed.ty.as_ref()),
                    _ => continue,
                },
            };
            let Some(owner) = receivers.get(&name) else {
                continue;
            };
            let written = self.type_arguments(container, ty);
            if written.iter().any(Option::is_some) {
                arguments.insert(name, (owner.clone(), written));
            }
        }
        arguments
    }

    /// The receiver types a signature states: `self` as the `impl` block's
    /// type, and every plainly named parameter as its type behind any `&`.
    pub(super) fn signature_receivers(
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

/// The type a field declared twice under different `cfg`s holds: the two
/// types as alternatives under the marks both write, or None when the
/// marks differ or either is not a resolved path.
pub(super) fn alternative_field(earlier: FieldType, next: FieldType) -> Option<FieldType> {
    use types::{split_marks, ALTERNATIVE_MARK};
    let (FieldType::Resolved(earlier), FieldType::Resolved(next)) = (earlier, next) else {
        return None;
    };
    let (marks, held) = split_marks(&earlier);
    let (next_marks, next_held) = split_marks(&next);
    if marks != next_marks {
        return None;
    }
    let mut alternatives: Vec<&str> = held.split(ALTERNATIVE_MARK).collect();
    for alternative in next_held.split(ALTERNATIVE_MARK) {
        if !alternatives.contains(&alternative) {
            alternatives.push(alternative);
        }
    }
    alternatives.sort_unstable();
    Some(FieldType::Resolved(format!(
        "{marks}{}",
        alternatives.join(&ALTERNATIVE_MARK.to_string())
    )))
}

/// Record each field of a generic struct declared as one of the struct's
/// own type parameters, behind any `&`, by that parameter's position (see
/// [`FieldType::Param`]).
pub(super) fn param_fields(
    generics: &syn::Generics,
    fields: &syn::Fields,
    types: &mut BTreeMap<String, FieldType>,
) {
    let params: Vec<String> = generics
        .type_params()
        .map(|param| ident_name(&param.ident))
        .collect();
    for field in fields {
        let Some(ident) = &field.ident else {
            continue;
        };
        let mut ty = &field.ty;
        while let syn::Type::Reference(reference) = ty {
            ty = &reference.elem;
        }
        let syn::Type::Path(path) = ty else {
            continue;
        };
        let Some(name) = path.path.get_ident().map(ident_name) else {
            continue;
        };
        if let Some(position) = params.iter().position(|param| *param == name) {
            types.insert(ident_name(ident), FieldType::Param(position));
        }
    }
}

/// The traits a list of bounds names (`Driver + Send`), lifetimes and
/// `?Sized` left out.
pub(super) fn trait_paths(
    bounds: &syn::punctuated::Punctuated<syn::TypeParamBound, syn::Token![+]>,
) -> Vec<syn::Path> {
    bounds
        .iter()
        .filter_map(|bound| match bound {
            syn::TypeParamBound::Trait(bound) if bound.maybe.is_none() => Some(bound.path.clone()),
            _ => None,
        })
        .collect()
}
