//! The paths a file declares, read off its items before the walk: what the
//! declaration index counts, which of them a `cfg` gates, and the `mod`
//! renames that place a module under another name.

use super::cfg::cfg_predicate;
use super::placement::mod_child;
use crate::layout::Layout;
use crate::resolve::{ident_name, is_standard_type};
use std::collections::{BTreeMap, BTreeSet};
use syn::{Item, Type};

/// Record that `declared` names `placed`, or that it is ambiguous when an
/// earlier declaration sent it elsewhere.
pub(super) fn merge_rename(
    renames: &mut BTreeMap<String, Option<String>>,
    declared: &str,
    placed: Option<String>,
) {
    match renames.get(declared) {
        Some(existing) if *existing != placed => {
            renames.insert(declared.to_owned(), None);
        }
        Some(_) => {}
        None => {
            renames.insert(declared.to_owned(), placed);
        }
    }
}

/// Every `mod name;` in one file whose module (see [`mod_child`]) is not the
/// path it is declared at, by that path: what another file writing
/// `crate::name::x` must be rewritten through. `None` marks a path two
/// declarations of the file send to two modules.
#[must_use]
pub fn declared_renames(
    relative: &str,
    module: &str,
    items: &[Item],
    layout: &Layout,
) -> BTreeMap<String, Option<String>> {
    let mut out = BTreeMap::new();
    collect_renames(relative, module, module, items, layout, &mut out);

    out
}

/// [`declared_renames`] for the items of `container`.
fn collect_renames(
    relative: &str,
    module: &str,
    container: &str,
    items: &[Item],
    layout: &Layout,
    out: &mut BTreeMap<String, Option<String>>,
) {
    let mut seen = BTreeMap::new();
    for item in items {
        let Item::Mod(node) = item else {
            continue;
        };
        let (child, _) = mod_child(relative, module, container, node, layout);
        let declared = format!("{container}::{}", ident_name(&node.ident));
        match &node.content {
            Some((_, inner)) => collect_renames(relative, module, &child, inner, layout, out),
            None => merge_rename(&mut seen, &declared, Some(child)),
        }
    }
    out.extend(
        seen.into_iter()
            .filter(|(declared, placed)| placed.as_deref() != Some(declared.as_str())),
    );
}

/// Every declaration a file can serve to the scan-wide index: the canonical
/// path of each struct, enum, union, trait, and top-level function, plus
/// inline modules recursed into. `mod foo;` file modules derive their own
/// entries when their defining file is indexed.
///
/// A file counts once per path: `#[cfg]` alternatives of one item are one
/// declaration, compiled in whichever form the target takes. Two files
/// declaring one path still make it ambiguous. The caller keeps the paths
/// apart from the index and counts them in with [`Declarations::add_gated_file`](super::Declarations::add_gated_file).
#[must_use]
pub fn declaration_paths(module: &str, items: &[Item]) -> BTreeSet<String> {
    let mut paths = BTreeSet::new();
    collect_declaration_paths(module, items, &mut paths);

    paths
}

/// What a file declares to the declaration index apart from its `impl`
/// blocks' methods, and which of those paths compile only under a `cfg`
/// other than `test` (their own, or an enclosing inline module's or `impl`
/// block's): the paths of [`declaration_paths`] without the methods, whose
/// type `index_facts` places through the file's imports instead.
#[must_use]
pub fn indexed_item_paths(module: &str, items: &[Item]) -> (BTreeSet<String>, BTreeSet<String>) {
    let mut paths = BTreeSet::new();
    let mut gated = BTreeSet::new();
    collect_gated_paths(module, items, false, false, &mut paths, &mut gated);

    (paths, gated)
}

/// The paths [`declaration_paths`] returns for one file, each once.
fn collect_declaration_paths(module: &str, items: &[Item], out: &mut BTreeSet<String>) {
    let mut gated = BTreeSet::new();
    collect_gated_paths(module, items, false, true, out, &mut gated);
}

/// The paths [`declaration_paths`] returns, with the ones that compile only
/// under a `cfg` (`under_cfg`, or an item's own) also in `gated`.
fn collect_gated_paths(
    module: &str,
    items: &[Item],
    under_cfg: bool,
    with_impls: bool,
    out: &mut BTreeSet<String>,
    gated: &mut BTreeSet<String>,
) {
    for item in items {
        let item_gated = under_cfg || cfg_predicate(super::cfg::item_attrs(item)).is_some();
        let before: BTreeSet<String> = if item_gated {
            out.clone()
        } else {
            BTreeSet::new()
        };
        // An inline module's items are walked here, so only this function
        // recurses.
        if let Some((nested, inner)) =
            collect_item_paths(module, item, item_gated, with_impls, out, gated)
        {
            collect_gated_paths(&nested, inner, item_gated, with_impls, out, gated);
        }
        if item_gated {
            gated.extend(out.difference(&before).cloned());
        }
    }
}

/// The paths one item adds to [`declaration_paths`], see
/// [`collect_gated_paths`], or for an inline module its path and items, for
/// the caller to walk.
fn collect_item_paths<'a>(
    module: &str,
    item: &'a Item,
    under_cfg: bool,
    with_impls: bool,
    out: &mut BTreeSet<String>,
    gated: &mut BTreeSet<String>,
) -> Option<(String, &'a [Item])> {
    {
        match item {
            Item::Struct(node) => record(out, module, &ident_name(&node.ident)),
            Item::Enum(node) => record(out, module, &ident_name(&node.ident)),
            Item::Union(node) => record(out, module, &ident_name(&node.ident)),
            Item::Trait(node) => record(out, module, &ident_name(&node.ident)),
            Item::Fn(node) => record(out, module, &ident_name(&node.sig.ident)),
            Item::Impl(node) if with_impls => {
                // A method is declared in an `impl` block rather than beside
                // its type, so indexing only top-level items left every method
                // unresolvable: a call to one could never be vouched for, and
                // the method read as uncalled however many callers it had.
                //
                // Only an unqualified `impl Type` is indexed. A qualified
                // target (`impl crate::facts::Facts`) names a type this module
                // may not own, and an index hit is trusted outright — so
                // guessing `{module}::Facts` there would vouch for a path that
                // does not exist. The walk resolves those through `use`
                // aliases, which this collector does not have.
                // A trait impl for a primitive or prelude type
                // (`impl Shout for str`) declares its methods on a block of
                // its own, not on a type of this module (see `Walk::walk_impl`).
                let foreign = |name: &str| node.trait_.is_some() && is_standard_type(name);
                if let Some(name) = impl_target_name(&node.self_ty).filter(|name| !foreign(name)) {
                    let owner = format!("{module}::{name}");
                    for member in &node.items {
                        if let syn::ImplItem::Fn(method) = member {
                            record(out, &owner, &ident_name(&method.sig.ident));
                            if under_cfg || cfg_predicate(&method.attrs).is_some() {
                                gated.insert(format!("{owner}::{}", ident_name(&method.sig.ident)));
                            }
                        }
                    }
                }
            }
            Item::Mod(node) => {
                if let Some((_, inner)) = &node.content {
                    return Some((format!("{module}::{}", ident_name(&node.ident)), inner));
                }
            }
            _ => {}
        }
    }
    None
}

/// The bare type name of an `impl` block's target, or `None` when the target
/// is anything this collector cannot place from the module path alone.
///
/// Deliberately narrow: one path segment, no leading `::`. Generic arguments
/// on that segment are fine (`impl Walk<'_>` is still `Walk`), because they do
/// not change which type is being implemented.
fn impl_target_name(ty: &Type) -> Option<String> {
    let Type::Path(path) = ty else {
        return None;
    };
    if path.qself.is_some() || path.path.leading_colon.is_some() || path.path.segments.len() != 1 {
        return None;
    }

    path.path
        .segments
        .last()
        .map(|segment| ident_name(&segment.ident))
}

/// Note one canonical path one file declares.
fn record(out: &mut BTreeSet<String>, module: &str, name: &str) {
    out.insert(format!("{module}::{name}"));
}
