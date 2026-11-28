//! The fields a named-field struct lists and the cases a unit-only enum
//! lists, for the test-fidelity fixture checks (protocol "Field lists").
//!
//! Only a struct or enum that derives `Deserialize` (directly or through a
//! `cfg_attr`) sends a list: a hand-written impl may read any shape.
//!
//! A list is a claim about the keys serde reads, and one that looks whole
//! but is not gives false drift later, so every doubt opens it. A struct's
//! list is `fields_open` for any `serde` key this module does not know to
//! leave the keys alone (`rename`, `rename_all`, `alias`, `flatten`, `tag`,
//! `untagged`, `transparent`, `from` and the rest), a derive outside the
//! frozen standard and serde set (Ruling F28), any other attribute that may
//! be a macro, a `cfg` on a field, and a `cfg_attr` that applies any of
//! these. A field is not required when it is an `Option`, an alias the file
//! declares of one (`type Maybe<T> = Option<T>;`), a bare type parameter
//! (which may be one), or `#[serde(default)]`, `skip` or
//! `skip_deserializing`, on itself or (`default`) on the struct. A field
//! read through `with` or `deserialize_with` keeps its key but sends no
//! `type`: the value on the wire is not the declared type's. Such an
//! `Option` field still reads not required, though serde then requires the
//! key: a missing one is left unreported, never reported wrongly.
//!
//! An enum sends `cases`, each `value` the variant's name as serde's
//! externally tagged unit variants are written, only when every variant is a
//! unit and neither the enum nor a variant carries a serde attribute or any
//! other that may rename (Ruling F7). Tuple and unit structs, unions and the
//! items a macro expands to send nothing. Two declarations of one path (`cfg`
//! alternatives) open the fields and drop the cases.

use std::collections::{BTreeMap, BTreeSet};

use serde_json::{json, Map, Value};
use syn::spanned::Spanned;
use syn::{Item, Meta};

use crate::facts::Facts;
use crate::resolve::ident_name;

/// Fields or cases listed at most; a longer list is cut and marked truncated.
const MAX_ENTRIES: usize = 64;

/// The derives that leave a struct's keys as serde writes them (Ruling F28).
const DERIVES: [&str; 11] = [
    "Clone",
    "Copy",
    "Debug",
    "Default",
    "Eq",
    "Hash",
    "Ord",
    "PartialEq",
    "PartialOrd",
    "Serialize",
    "Deserialize",
];

/// The crates a derive in [`DERIVES`] may be spelled through.
const DERIVE_CRATES: [&str; 5] = ["serde", "serde_derive", "std", "core", "alloc"];

/// Built-in attributes that change no key.
const INERT: [&str; 12] = [
    "doc",
    "allow",
    "warn",
    "deny",
    "forbid",
    "expect",
    "deprecated",
    "must_use",
    "non_exhaustive",
    "repr",
    "automatically_derived",
    "default",
];

/// The tools whose attributes change no key (`#[rustfmt::skip]`).
const INERT_TOOLS: [&str; 3] = ["rustfmt", "clippy", "diagnostic"];

/// The `serde` keys on a struct that leave every key as its field is named.
const CONTAINER_KEYS: [&str; 4] = ["deny_unknown_fields", "bound", "crate", "expecting"];

/// The `serde` keys on a field that leave its key and how it is read alone.
const FIELD_KEYS: [&str; 6] = [
    "skip_serializing",
    "skip_serializing_if",
    "serialize_with",
    "borrow",
    "bound",
    "getter",
];

/// What one item's attributes say about the keys its deserialiser reads.
#[derive(Default)]
struct Reading {
    /// A key may be read by another name, or a field may come and go.
    open: bool,
    /// `#[serde(default)]`, `skip` or `skip_deserializing`: the key may be missing.
    default: bool,
    /// Some `serde` attribute was read.
    serde: bool,
    /// `with` or `deserialize_with`: the wire value is not the declared type.
    untyped: bool,
    /// `Deserialize` is derived, directly or through a `cfg_attr`: without
    /// it a hand-written impl may read any shape.
    deserialize: bool,
}

/// Queue the `fields` and `cases` of every struct and enum in `items`,
/// inline modules included, on the class nodes the walk declared for them.
pub(super) fn attach(facts: &mut Facts, module: &str, items: &[Item]) {
    let mut found = BTreeMap::new();
    let mut aliases = Vec::new();
    type_aliases(items, &mut aliases);
    collect(module, items, &optional_aliases(&aliases), &mut found);
    for (canonical, attributes) in found {
        // A function or module of the same path, declared first, would take the attributes.
        let first = facts
            .nodes()
            .iter()
            .find(|node| node.canonical_name == canonical);
        if first.is_some_and(|node| node.kind == "class") {
            for (key, value) in attributes {
                facts.node_attribute(&canonical, &key, value);
            }
        }
    }
}

/// Every `type` alias in `items`, inline modules included, by its name.
fn type_aliases<'i>(items: &'i [Item], found: &mut Vec<(String, &'i syn::Type)>) {
    for item in items {
        match item {
            Item::Type(alias) => found.push((ident_name(&alias.ident), &alias.ty)),
            Item::Mod(node) => {
                if let Some((_, inner)) = &node.content {
                    type_aliases(inner, found);
                }
            }
            _ => {}
        }
    }
}

/// The names of the `aliases` that name an `Option` (see [`is_option`]),
/// directly or through another of them (`type Maybe<T> = Option<T>;`):
/// serde reads a missing key of one as `None` (G6-08e). By name alone, so
/// an alias in any of the file's modules counts, which only ever makes a
/// field not required. An alias in another file is not seen.
fn optional_aliases(aliases: &[(String, &syn::Type)]) -> BTreeSet<String> {
    let mut optional = BTreeSet::new();
    loop {
        let more: Vec<&String> = aliases
            .iter()
            .filter(|(name, ty)| {
                !optional.contains(name) && (is_option(ty) || names_alias(ty, &optional))
            })
            .map(|(name, _)| name)
            .collect();
        if more.is_empty() {
            return optional;
        }
        optional.extend(more.into_iter().cloned());
    }
}

/// Whether a type's path ends in one of `aliases`.
fn names_alias(ty: &syn::Type, aliases: &BTreeSet<String>) -> bool {
    let syn::Type::Path(path) = strip(ty) else {
        return false;
    };
    path.qself.is_none()
        && path
            .path
            .segments
            .last()
            .is_some_and(|last| aliases.contains(&ident_name(&last.ident)))
}

/// The attributes of every struct, enum and union in `items`, by path, a
/// field of one of `aliases` not required.
fn collect(
    container: &str,
    items: &[Item],
    aliases: &BTreeSet<String>,
    found: &mut BTreeMap<String, Map<String, Value>>,
) {
    for item in items {
        let (name, attributes) = match item {
            Item::Struct(node) => (&node.ident, struct_fields(node, aliases)),
            Item::Enum(node) => (&node.ident, enum_cases(node)),
            Item::Union(node) => (&node.ident, Map::new()),
            Item::Mod(node) => {
                if let Some((_, inner)) = &node.content {
                    collect(
                        &format!("{container}::{}", ident_name(&node.ident)),
                        inner,
                        aliases,
                        found,
                    );
                }
                continue;
            }
            _ => continue,
        };
        record(
            found,
            format!("{container}::{}", ident_name(name)),
            attributes,
        );
    }
}

/// Hold one declaration's attributes, folding a second declaration of the
/// same path into the first: the fields open, the cases dropped.
fn record(
    found: &mut BTreeMap<String, Map<String, Value>>,
    canonical: String,
    attributes: Map<String, Value>,
) {
    let Some(held) = found.get_mut(&canonical) else {
        found.insert(canonical, attributes);
        return;
    };
    held.remove("cases");
    held.remove("cases_truncated");
    if let Some(fields) = attributes.get("fields") {
        held.entry("fields").or_insert_with(|| fields.clone());
    }
    if held.contains_key("fields") {
        held.insert("fields_open".to_owned(), Value::Bool(true));
    }
}

/// A named-field struct's `fields`, sorted by name, with `fields_open` and
/// `fields_truncated` when they apply; nothing for a tuple or unit struct,
/// or one that does not derive `Deserialize`, whose hand-written impl (in
/// any file, before or after it) may read another shape. A field of one of
/// `aliases` is not required (see [`optional_aliases`]).
fn struct_fields(node: &syn::ItemStruct, aliases: &BTreeSet<String>) -> Map<String, Value> {
    let syn::Fields::Named(named) = &node.fields else {
        return Map::new();
    };
    let mut container = Reading::default();
    for attr in &node.attrs {
        read_container(&attr.meta, &mut container);
    }
    if !container.deserialize {
        return Map::new();
    }
    let params: BTreeSet<String> = node
        .generics
        .type_params()
        .map(|param| ident_name(&param.ident))
        .collect();
    let mut open = container.open;
    let mut fields = BTreeMap::new();
    for field in &named.named {
        let mut reading = Reading::default();
        for attr in &field.attrs {
            read_field(&attr.meta, &mut reading);
        }
        open |= reading.open;
        let Some(ident) = &field.ident else { continue };
        let name = ident_name(ident);
        let optional = container.default
            || reading.default
            || is_option(&field.ty)
            || names_alias(&field.ty, aliases)
            || is_param(&field.ty, &params);
        let mut entry = Map::new();
        entry.insert("name".to_owned(), Value::String(name.clone()));
        if let Some(text) = field.ty.span().source_text().filter(|_| !reading.untyped) {
            entry.insert("type".to_owned(), Value::String(text));
        }
        entry.insert("required".to_owned(), Value::Bool(!optional));
        entry.insert("line".to_owned(), json!(ident.span().start().line));
        fields.insert(name, Value::Object(entry));
    }
    let mut attributes = listed("fields", fields.into_values().collect());
    if open {
        attributes.insert("fields_open".to_owned(), Value::Bool(true));
    }
    attributes
}

/// A unit-only enum's `cases` by name, each its own `value`; nothing when a
/// variant carries data or anything may rename one.
fn enum_cases(node: &syn::ItemEnum) -> Map<String, Value> {
    let mut container = Reading::default();
    for attr in &node.attrs {
        read_container(&attr.meta, &mut container);
    }
    let mut names = BTreeSet::new();
    for variant in &node.variants {
        let mut reading = Reading::default();
        for attr in &variant.attrs {
            read_field(&attr.meta, &mut reading);
        }
        if reading.open || reading.serde || !matches!(variant.fields, syn::Fields::Unit) {
            return Map::new();
        }
        names.insert(ident_name(&variant.ident));
    }
    if container.open || container.serde || !container.deserialize {
        return Map::new();
    }
    let cases = names
        .into_iter()
        .map(|name| json!({ "name": name, "value": name }))
        .collect();
    listed("cases", cases)
}

/// A list under its key, cut at [`MAX_ENTRIES`] and marked when it was.
fn listed(key: &str, mut entries: Vec<Value>) -> Map<String, Value> {
    let mut attributes = Map::new();
    if entries.len() > MAX_ENTRIES {
        entries.truncate(MAX_ENTRIES);
        attributes.insert(format!("{key}_truncated"), Value::Bool(true));
    }
    attributes.insert(key.to_owned(), Value::Array(entries));
    attributes
}

/// Read one attribute of a struct or an enum.
fn read_container(meta: &Meta, reading: &mut Reading) {
    let path = meta.path();
    if path.is_ident("derive") {
        read_derive(meta, reading);
    } else if path.is_ident("serde") {
        read_serde(meta, reading, false);
    } else if path.is_ident("cfg_attr") {
        applied(meta, reading, read_container);
    } else if !path.is_ident("cfg") && !is_inert(path) {
        reading.open = true;
    }
}

/// Read one attribute of a field or a variant; a `cfg` there may remove it.
fn read_field(meta: &Meta, reading: &mut Reading) {
    let path = meta.path();
    if path.is_ident("serde") {
        read_serde(meta, reading, true);
    } else if path.is_ident("cfg_attr") {
        applied(meta, reading, read_field);
    } else if !is_inert(path) {
        reading.open = true;
    }
}

/// Read the attributes a `cfg_attr` applies, as if it applied them; one that
/// does not parse opens.
fn applied(meta: &Meta, reading: &mut Reading, read: fn(&Meta, &mut Reading)) {
    let parsed = meta.require_list().and_then(|list| {
        list.parse_args_with(syn::punctuated::Punctuated::<Meta, syn::Token![,]>::parse_terminated)
    });
    match parsed {
        Ok(metas) => metas.iter().skip(1).for_each(|meta| read(meta, reading)),
        Err(_) => reading.open = true,
    }
}

/// Read a `derive(...)`: one outside [`DERIVES`], or not spelled bare or
/// through one of [`DERIVE_CRATES`], opens, and a `Deserialize` among the
/// rest is recorded; one that does not parse opens.
fn read_derive(meta: &Meta, reading: &mut Reading) {
    let parsed = meta.require_list().and_then(|list| {
        list.parse_args_with(
            syn::punctuated::Punctuated::<syn::Path, syn::Token![,]>::parse_terminated,
        )
    });
    let Ok(paths) = parsed else {
        reading.open = true;
        return;
    };
    for path in &paths {
        let segments: Vec<String> = path
            .segments
            .iter()
            .map(|segment| ident_name(&segment.ident))
            .collect();
        let last = segments.last().map_or("", String::as_str);
        if DERIVES.contains(&last)
            && (segments.len() == 1 || DERIVE_CRATES.contains(&segments[0].as_str()))
        {
            reading.deserialize |= last == "Deserialize";
        } else {
            reading.open = true;
        }
    }
}

/// Read a `serde(...)` attribute's keys: any key not known to leave the
/// keys alone opens, and so does one that does not parse.
fn read_serde(meta: &Meta, reading: &mut Reading, field: bool) {
    reading.serde = true;
    let Meta::List(list) = meta else {
        reading.open = true;
        return;
    };
    let parsed = list.parse_nested_meta(|nested| {
        let key = nested.path.get_ident().map(ident_name).unwrap_or_default();
        if nested.input.peek(syn::Token![=]) {
            let _: syn::Expr = nested.value()?.parse()?;
        } else if nested.input.peek(syn::token::Paren) {
            nested.parse_nested_meta(|inner| {
                if inner.input.peek(syn::Token![=]) {
                    let _: syn::Expr = inner.value()?.parse()?;
                }
                Ok(())
            })?;
        }
        match key.as_str() {
            "default" => reading.default = true,
            "skip" | "skip_deserializing" if field => reading.default = true,
            "with" | "deserialize_with" if field => reading.untyped = true,
            known if field && FIELD_KEYS.contains(&known) => {}
            known if !field && CONTAINER_KEYS.contains(&known) => {}
            _ => reading.open = true,
        }
        Ok(())
    });
    reading.open |= parsed.is_err();
}

/// Whether an attribute is a built-in one, or a tool's, that changes no key.
fn is_inert(path: &syn::Path) -> bool {
    let first = path
        .segments
        .first()
        .map(|segment| ident_name(&segment.ident));
    match first {
        Some(tool) if path.segments.len() > 1 => INERT_TOOLS.contains(&tool.as_str()),
        Some(name) => INERT.contains(&name.as_str()),
        None => false,
    }
}

/// Whether a type is `Option<_>`, bare or as `std::option::Option` or
/// `core::option::Option`, or a `Box`, `Rc` or `Arc` of one: serde reads a
/// missing key as `None`, and those pointers hand the read on.
fn is_option(ty: &syn::Type) -> bool {
    let syn::Type::Path(path) = strip(ty) else {
        return false;
    };
    let segments: Vec<String> = path
        .path
        .segments
        .iter()
        .map(|segment| ident_name(&segment.ident))
        .collect();
    let Some(syn::PathArguments::AngleBracketed(arguments)) =
        path.path.segments.last().map(|segment| &segment.arguments)
    else {
        return false;
    };
    let spelled = match segments.as_slice() {
        [option] => option == "Option",
        [root, module, option] => {
            matches!(root.as_str(), "std" | "core") && module == "option" && option == "Option"
        }
        _ => false,
    };
    let pointer = segments
        .last()
        .is_some_and(|last| matches!(last.as_str(), "Box" | "Rc" | "Arc"));
    let pointee = match arguments.args.first() {
        Some(syn::GenericArgument::Type(inner)) if arguments.args.len() == 1 => Some(inner),
        _ => None,
    };
    path.qself.is_none() && (spelled || (pointer && pointee.is_some_and(is_option)))
}

/// Whether a type is one of the struct's own type parameters, bare: an
/// `Option` for some uses of the struct.
fn is_param(ty: &syn::Type, params: &BTreeSet<String>) -> bool {
    let syn::Type::Path(path) = strip(ty) else {
        return false;
    };
    path.qself.is_none()
        && path
            .path
            .get_ident()
            .is_some_and(|ident| params.contains(&ident_name(ident)))
}

/// A type without the parentheses or invisible groups around it.
fn strip(ty: &syn::Type) -> &syn::Type {
    match ty {
        syn::Type::Paren(inner) => strip(&inner.elem),
        syn::Type::Group(inner) => strip(&inner.elem),
        other => other,
    }
}
