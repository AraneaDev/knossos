//! Short textual labels for types and paths, as nodes and evidence show them.

use syn::Type;

use crate::resolve::ident_name;

/// A path as its source spells it, generic arguments included, without
/// the spaces a token stream puts between tokens: `From<u8>`,
/// `Into<crate::Id>`, `Fn(&str) -> bool`. Only the last segment is kept
/// when the path has several (`std::fmt::Display` is `Display`), so a
/// trait named through different imports reads the same.
pub(super) fn path_label(path: &syn::Path) -> String {
    type_text(
        &Type::Path(syn::TypePath {
            attrs: Vec::new(),
            qself: None,
            path: path.clone(),
        }),
        true,
    )
}

/// A type as its source spells it, for [`path_label`]: every segment of a
/// path is kept, so `From<a::Id>` and `From<b::Id>` stay apart, unless
/// `last_only` keeps only the last segment (a trait's own name). Each
/// segment's generic arguments are rendered the same way.
pub(super) fn type_text(ty: &Type, last_only: bool) -> String {
    match ty {
        Type::Path(path) if path.qself.is_none() => {
            let segments = &path.path.segments;
            let skip = if last_only {
                segments.len().saturating_sub(1)
            } else {
                0
            };
            let rendered: Vec<String> = segments
                .iter()
                .skip(skip)
                .map(|segment| {
                    let mut label = ident_name(&segment.ident);
                    match &segment.arguments {
                        syn::PathArguments::None => {}
                        syn::PathArguments::AngleBracketed(arguments) => {
                            let parts: Vec<String> = arguments
                                .args
                                .iter()
                                .map(|argument| match argument {
                                    syn::GenericArgument::Type(ty) => type_text(ty, false),
                                    syn::GenericArgument::Lifetime(lifetime) => {
                                        format!("'{}", lifetime.ident)
                                    }
                                    syn::GenericArgument::AssocType(assoc) => format!(
                                        "{}={}",
                                        ident_name(&assoc.ident),
                                        type_text(&assoc.ty, false)
                                    ),
                                    _ => "_".to_owned(),
                                })
                                .collect();
                            label.push_str(&format!("<{}>", parts.join(",")));
                        }
                        syn::PathArguments::Parenthesized(arguments) => {
                            let inputs: Vec<String> = arguments
                                .inputs
                                .iter()
                                .map(|input| type_text(&input.ty, false))
                                .collect();
                            label.push_str(&format!("({})", inputs.join(",")));
                            if let syn::ReturnType::Type(_, output) = &arguments.output {
                                label.push_str(&format!("->{}", type_text(output, false)));
                            }
                        }
                    }
                    label
                })
                .collect();
            if rendered.is_empty() {
                "_".to_owned()
            } else {
                rendered.join("::")
            }
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
            type_text(&reference.elem, false)
        ),
        Type::Slice(slice) => format!("[{}]", type_text(&slice.elem, false)),
        Type::Array(array) => format!("[{};_]", type_text(&array.elem, false)),
        Type::Tuple(tuple) => format!(
            "({})",
            tuple
                .elems
                .iter()
                .map(|elem| type_text(elem, false))
                .collect::<Vec<_>>()
                .join(",")
        ),
        Type::Paren(paren) => type_text(&paren.elem, last_only),
        Type::Group(group) => type_text(&group.elem, last_only),
        _ => "_".to_owned(),
    }
}

/// A short label for a type an `impl` block names but no path places:
/// `&M`, `&mut M`, `[T]`, `(A, B)`; `_` for anything else.
pub(super) fn type_label(ty: &Type) -> String {
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
