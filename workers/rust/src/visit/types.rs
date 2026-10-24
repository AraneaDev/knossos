//! Resolving the types a file writes to the canonical paths of the types
//! they name: an impl's self type, a receiver's type seen through
//! references and smart pointers, a struct field's declared type, and the
//! `type` aliases each of them may name.

use syn::{Item, Type};

use super::declarations::FieldType;
use super::paths::Globbed;
use super::state::Walk;
use crate::resolve::{ident_name, is_primitive};

impl Walk<'_> {
    /// The declared type of `owner`'s field `field`: from this file when it
    /// declares the struct, else from the declaration index, which holds
    /// what the declaring file's own imports resolved it to. A type that
    /// file names only through its glob imports is resolved here, through
    /// the whole index (see [`Walk::through_globs`]).
    pub(super) fn field_type(&self, owner: &str, field: &str) -> Option<String> {
        let (declared, local) = match self.struct_fields.get(owner) {
            Some(fields) => (fields.get(field)?.clone(), true),
            None => (self.declarations.field_type(owner, field)?, false),
        };
        match declared {
            FieldType::Resolved(target) if local => Some(target),
            FieldType::Resolved(declared) => {
                let (marks, held) = split_marks(&declared);
                self.renamed(held.to_owned())
                    .map(|target| format!("{marks}{}", self.exported(target)))
            }
            FieldType::Globbed {
                name,
                marks,
                sources,
            } => match self.through_globs(&sources, &name) {
                Globbed::One(target) => Some(format!("{marks}{target}")),
                Globbed::Absent | Globbed::Ambiguous => None,
            },
        }
    }

    /// The type a struct field declares, as [`Walk::receiver_type`] states
    /// it, or else, for a bare name nothing but this module's glob imports
    /// could bring in, that name and the globs' sources, to be resolved
    /// once every file is indexed.
    pub(super) fn declared_field_type(&self, container: &str, ty: &Type) -> Option<FieldType> {
        if let Some(target) = self.receiver_type(container, ty) {
            return Some(FieldType::Resolved(target));
        }
        let (name, marks) = self.glob_named(container, ty)?;
        let sources = self.glob_sources(container);

        (!sources.is_empty()).then_some(FieldType::Globbed {
            name,
            marks,
            sources,
        })
    }

    /// The bare name a receiver type holds, seen through what
    /// [`Walk::receiver_type`] sees through, with the marks it states for
    /// the pointers and collections holding it, when only the
    /// enclosing-module guess places that name: it is not a primitive,
    /// `Self`, imported, or declared in this file.
    fn glob_named(&self, container: &str, ty: &Type) -> Option<(String, String)> {
        match ty {
            Type::Reference(reference) => self.glob_named(container, &reference.elem),
            Type::Paren(inner) => self.glob_named(container, &inner.elem),
            Type::Array(array) => self
                .glob_named(container, &array.elem)
                .map(|(name, marks)| (name, format!("{ELEMENT_MARK}{marks}"))),
            Type::Slice(slice) => self
                .glob_named(container, &slice.elem)
                .map(|(name, marks)| (name, format!("{ELEMENT_MARK}{marks}"))),
            Type::Path(path) if path.qself.is_none() => match pointee(&path.path) {
                Some(inner) => self
                    .glob_named(container, inner)
                    .map(|(name, marks)| (name, pointed(&marks))),
                None if element(&path.path).is_some() => self
                    .glob_named(container, element(&path.path)?)
                    .map(|(name, marks)| (name, format!("{ELEMENT_MARK}{marks}"))),
                None => {
                    let path = &path.path;
                    if path.leading_colon.is_some() || path.segments.len() != 1 {
                        return None;
                    }
                    let name = ident_name(&path.segments[0].ident);
                    if is_primitive(&name) || name == "Self" || self.type_params.contains(&name) {
                        return None;
                    }
                    match self.resolve_written(container, path)? {
                        (target, true) if !self.own_declarations.contains(&target) => {
                            Some((name, String::new()))
                        }
                        _ => None,
                    }
                }
            },
            _ => None,
        }
    }

    /// The canonical path of an `impl` block's self type, when it names one.
    ///
    /// Only a plain path is handled. `impl Trait for &T`, tuples, and slices name
    /// no declared type in this file, so attributing methods to them would invent
    /// a node. A path that does name a type is resolved through [`Walk::path_target`]
    /// like any other path — through `use`, `crate`/`self`/`super`, and the
    /// local-container fallback — rather than by keeping only its last segment.
    /// Keeping only the last segment previously collapsed a qualified self type
    /// such as `other::Engine` down to `crate::Engine`: a node that can genuinely
    /// exist under a different name in the same file, so the resulting edge would
    /// silently point at the wrong type instead of being dropped or resolved
    /// correctly.
    pub(super) fn type_path(&self, container: &str, ty: &Type) -> Option<String> {
        let Type::Path(path) = ty else {
            return None;
        };

        self.path_target(container, &path.path)
    }

    /// The canonical path of a type a receiver holds, seen through `&`,
    /// `&mut`, parentheses and the smart pointers that dereference to what
    /// they hold (`Box<T>`, `Rc<T>`, `Arc<T>`), or None for anything that is
    /// not a plain path. An array, a slice, a `Vec<T>` or a `VecDeque<T>` is
    /// marked as a collection of its element (see [`ELEMENT_MARK`]): its own
    /// methods are not `T`'s, but indexing it gives a `T`. Any other generic
    /// (`Option<T>`, `HashMap<K, V>`) is the type itself.
    pub(super) fn receiver_type(&self, container: &str, ty: &Type) -> Option<String> {
        match ty {
            Type::Reference(reference) => self.receiver_type(container, &reference.elem),
            Type::Paren(inner) => self.receiver_type(container, &inner.elem),
            Type::Array(array) => self
                .receiver_type(container, &array.elem)
                .map(|held| format!("{ELEMENT_MARK}{held}")),
            Type::Slice(slice) => self
                .receiver_type(container, &slice.elem)
                .map(|held| format!("{ELEMENT_MARK}{held}")),
            Type::Path(path) if path.qself.is_none() => match pointee(&path.path) {
                Some(inner) => self
                    .receiver_type(container, inner)
                    .map(|held| pointed(&held)),
                None => match element(&path.path) {
                    Some(inner) => self
                        .receiver_type(container, inner)
                        .map(|held| format!("{ELEMENT_MARK}{held}")),
                    None => self.stated_type(container, &path.path),
                },
            },
            _ => None,
        }
    }

    /// The canonical path of the type a path names, when something vouches
    /// for it: rooted, imported, or declared where it could be named. A lone
    /// name only the enclosing-module guess places (`Vec`, `Option`, `char`,
    /// `String`) names no type of this crate, so it gives none.
    pub(super) fn stated_type(&self, container: &str, path: &syn::Path) -> Option<String> {
        // A primitive is never a type of this crate, whatever a module
        // declares by that name in the value namespace (`fn char()`), and a
        // generic type parameter in scope shadows any type of its name.
        if path.leading_colon.is_none() && path.segments.len() == 1 {
            let name = ident_name(&path.segments[0].ident);
            if is_primitive(&name) || self.type_params.contains(&name) {
                return None;
            }
        }
        // `Self` is the impl's type, however `resolve_path` flags it for calls.
        let is_self = path.is_ident("Self");
        let target = match self.resolve_path(container, path)? {
            (target, true)
                if path.segments.len() == 1
                    && !is_self
                    && !self.own_declarations.contains(&target)
                    && !self.type_aliases.contains_key(&target) =>
            {
                return None;
            }
            (target, _) => target,
        };
        Some(self.through_aliases(target))
    }

    /// The type `target` names when it is an alias: one this file declares
    /// (a module's `type`, an impl's associated type), or one another file
    /// declares, as the index holds it, followed through any alias it names in
    /// turn. Every alias is resolved when it is collected, so this only looks
    /// paths up. A file that does not compile can alias in a circle; past a
    /// few hops the alias reached is the answer.
    fn through_aliases(&self, target: String) -> String {
        let mut current = target;
        for _ in 0..MAX_ALIAS_HOPS {
            if !split_marks(&current).0.is_empty() {
                break;
            }
            let next = match self.type_aliases.get(&current) {
                Some(local) => local.clone(),
                None => match self.declarations.alias_of(&current) {
                    Some(indexed) if !split_marks(&indexed).0.is_empty() => indexed,
                    Some(indexed) => match self.renamed(indexed) {
                        Some(placed) => self.exported(placed),
                        None => break,
                    },
                    None => break,
                },
            };
            current = next;
        }
        current
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

/// The most aliases one type is followed through (see `stated_type`).
const MAX_ALIAS_HOPS: u8 = 8;

/// What a smart pointer receiver (see [`Walk::receiver_type`]) holds, or
/// the type itself for any other receiver.
pub(super) fn pointee_of(owner: &str) -> &str {
    owner.strip_prefix(POINTER_MARK).unwrap_or(owner)
}

/// The element a collection receiver (see [`Walk::receiver_type`]) holds,
/// seen through a smart pointer holding the collection, or None for a
/// receiver that is not a collection.
pub(super) fn element_of(owner: &str) -> Option<&str> {
    pointee_of(owner).strip_prefix(ELEMENT_MARK)
}

/// The class a receiver type names, seen through a smart pointer, or None
/// for a collection, which names no class of this crate.
pub(super) fn class_of(owner: &str) -> Option<&str> {
    let class = pointee_of(owner);

    (!class.starts_with(ELEMENT_MARK)).then_some(class)
}

/// A receiver type split into the marks leading it and the path they hold.
pub(super) fn split_marks(owner: &str) -> (&str, &str) {
    let mut held = owner;
    loop {
        if let Some(rest) = held.strip_prefix(POINTER_MARK) {
            held = rest;
        } else if let Some(rest) = held.strip_prefix(ELEMENT_MARK) {
            held = rest;
        } else {
            break;
        }
    }

    owner.split_at(owner.len() - held.len())
}

/// A receiver type held behind a smart pointer: a pointer to a pointer
/// dereferences to the same type, so the mark is not repeated.
fn pointed(held: &str) -> String {
    format!("{POINTER_MARK}{}", pointee_of(held))
}

/// Marks a receiver type held behind `Box`, `Rc` or `Arc`: its own methods
/// are the pointer's, and every other method derefs to the type.
pub(super) const POINTER_MARK: &str = "*";

/// Marks a receiver type that is an array, slice, `Vec` or `VecDeque` of
/// the type after it: its own methods are the collection's, and indexing
/// or iterating it gives the element.
pub(super) const ELEMENT_MARK: &str = "[]";

/// The type a `Box<T>`, `Rc<T>` or `Arc<T>` holds, however its path is
/// qualified (`std::sync::Arc<T>`), or None for any other type.
fn pointee(path: &syn::Path) -> Option<&Type> {
    only_argument(path, &["Box", "Rc", "Arc"])
}

/// The element a `Vec<T>` or `VecDeque<T>` holds, however its path is
/// qualified, or None for any other type.
fn element(path: &syn::Path) -> Option<&Type> {
    only_argument(path, &["Vec", "VecDeque"])
}

/// The single type argument of a path whose last segment is one of `names`.
fn only_argument<'p>(path: &'p syn::Path, names: &[&str]) -> Option<&'p Type> {
    let last = path.segments.last()?;
    if !names.contains(&ident_name(&last.ident).as_str()) {
        return None;
    }
    let syn::PathArguments::AngleBracketed(arguments) = &last.arguments else {
        return None;
    };
    let mut types = arguments.args.iter().filter_map(|argument| match argument {
        syn::GenericArgument::Type(ty) => Some(ty),
        _ => None,
    });
    let inner = types.next()?;

    types.next().is_none().then_some(inner)
}
