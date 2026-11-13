//! The crate-wide declaration index every walk resolves names against.
//!
//! Each file contributes the paths it declares and the `mod` declarations
//! that place a module somewhere other than its declared path; a walk then
//! asks the index whether a name it could not resolve locally is declared
//! exactly once in the project.

use std::cell::RefCell;
use std::collections::{BTreeMap, BTreeSet};

use super::declaration_paths::merge_rename;

/// Canonical name of every top-level and inline-module declaration of the
/// project's Rust files, mapped to how many files declared it. The crate-wide
/// view that lets an `impl` block attach to a type declared in another file
/// and a child-module call resolve to its real target. A count above one means
/// the name is ambiguous across the project and must not be trusted.
///
/// Every name looked up is remembered, found or not, until
/// [`Declarations::take_lookups`] hands the set over: a walk's answer depends
/// on each name it asked about, so the files that could declare those names
/// are the ones its facts were read from.
#[derive(Debug, Default)]
pub struct Declarations {
    /// Canonical name to the number of files declaring it.
    counts: BTreeMap<String, usize>,
    /// The names every file declaring them so far declares under a `cfg`
    /// other than `test`: `cfg` alternatives, of which one build compiles
    /// one, so they count as one declaration.
    gated_only: BTreeSet<String>,
    /// Every name asked about since the last [`Declarations::take_lookups`].
    lookups: RefCell<BTreeSet<String>>,
    /// Each `mod name;` whose module is not the path it is declared at
    /// (`#[path]`, a binary root's children), by that declared path; `None`
    /// when two declarations send one path to two modules. See
    /// [`declared_renames`].
    renames: BTreeMap<String, Option<String>>,
    /// Each name a `use` makes a path of its module, mapped to the
    /// path it imports; `None` when two files map one name apart. See
    /// [`ExportedNames`].
    exports: BTreeMap<String, Option<String>>,
    /// The field types of every struct, by struct then field; `None` for a
    /// struct two files declare, whose fields cannot be told apart. See
    /// [`StructFields`].
    fields: BTreeMap<String, Option<BTreeMap<String, FieldType>>>,
    /// The type every module-level alias names; `None` for a path two files
    /// alias apart. See [`TypeAliases`].
    aliases: BTreeMap<String, Option<String>>,
    /// The type every function and method returns; `None` for a path two
    /// files declare apart. See [`FunctionReturns`].
    returns: BTreeMap<String, Option<String>>,
    /// The `cfg` alternatives of each name `use` items re-export
    /// apart, every one gated; see [`ExportAlternatives`].
    export_alternatives: BTreeMap<String, Option<Vec<String>>>,
    /// The modules each module's glob imports import from, see
    /// [`GlobImports`].
    globs: BTreeMap<String, Vec<(String, bool)>>,
    /// The names of `exports` only a private `use` binds.
    private_exports: BTreeSet<String>,
}

/// The modules each module of one file imports every name of with a glob
/// (`pub use types::*;`, `use super::*;`), by the importing module, in the
/// order written, as the file's own imports place them. A path through the
/// importing module (`crate::v2::Profile`, `super::digest`) names what a
/// source provides when the module itself does not. Each source says whether
/// a private `use` imports it, which only the module and those below it see.
pub type GlobImports = BTreeMap<String, Vec<(String, bool)>>;

/// The names one file's `use` items re-export apart under `cfg`
/// alternatives (`#[cfg(unix)] pub use unix::open;` beside a Windows one),
/// each with every path it may name: [`ExportedNames`] binds such a name to
/// nothing, since no one path is certain, while code written through it
/// reaches whichever compiles.
pub type ExportAlternatives = BTreeMap<String, Vec<String>>;

/// The names one file's `use` items (`pub use cfg::collect;`,
/// `pub(crate) use self::a as b;`, and a private `use unix as platform;`,
/// which the module's descendants can name as `super::platform`) add to the
/// module they are written in, by that path, mapped to the path each
/// imports, as the file's own imports resolve it. A glob re-export names
/// nothing and is not here.
///
/// `None` marks a name two `use` items of the file bind to different paths:
/// which one is meant is unknown, unless every one is a `cfg` alternative
/// (see [`ExportAlternatives`]).
pub type ExportedNames = BTreeMap<String, Option<String>>;

/// The declared type of each named field of one file's structs, by the
/// struct's canonical path then field name, resolved through the file's own
/// imports: what a method call through `self.field` in another file's
/// `impl` block resolves through.
pub type StructFields = BTreeMap<String, BTreeMap<String, FieldType>>;

/// The type each module-level `type` alias of one file names, by the alias's
/// canonical path, resolved through the file's own imports: what a receiver
/// another file types by the alias holds. An alias whose type the file
/// cannot resolve is not here.
pub type TypeAliases = BTreeMap<String, String>;

/// The type each function and method of one file returns, by its canonical
/// path, as `Walk::receiver_type` states it through the file's own imports:
/// what a binding of a call's result holds in another file
/// (`let store = report::open_existing(root)?;`). A function whose return
/// type the file cannot resolve is not here; a path the file declares twice
/// with different return types (an inherent method and a trait's of one
/// name, or two `#[cfg]` variants) returns `None`, no one type.
pub type FunctionReturns = BTreeMap<String, Option<String>>;

/// The declared type of one struct field, as far as its own file can tell.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum FieldType {
    /// The path the file's own imports, declarations or rooted paths
    /// resolve the type to, marked when a smart pointer holds it (see
    /// `Walk::receiver_type`).
    Resolved(String),
    /// A type named by one bare segment that the file neither declares nor
    /// imports by name, so only its glob imports can bring it in. Which
    /// glob source provides it is known only once every file is indexed, so
    /// the name is resolved through `sources`, the modules the globs of the
    /// struct's module import from, when the field is looked up.
    Globbed {
        /// The name as written.
        name: String,
        /// The marks of the smart pointers and collections holding the
        /// type, as `Walk::receiver_type` writes them.
        marks: String,
        /// The modules the globs import from, in the order written.
        sources: Vec<String>,
    },
    /// One of the struct's own type parameters, by its position among
    /// them, seen through `&` (`environment: &'a mut E` of
    /// `Context<'a, E, F>` is position 0): a receiver of the struct holds
    /// what its type argument there is.
    Param(usize),
}

impl Declarations {
    /// An empty index.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// How many files declare `name`, remembering that it was asked about.
    pub fn get(&self, name: &str) -> Option<&usize> {
        self.lookups.borrow_mut().insert(name.to_owned());
        self.counts.get(name)
    }

    /// Count one file's declarations, of which the ones in `gated` (see
    /// [`indexed_item_paths`](super::indexed_item_paths)), or all of them when the file itself is
    /// (`file_gated`), compile only under a `cfg`. A name every declaring
    /// file gates is one declaration in several `cfg` alternatives, and
    /// counts once.
    pub fn add_gated_file(
        &mut self,
        paths: &BTreeSet<String>,
        gated: &BTreeSet<String>,
        file_gated: bool,
    ) {
        for path in paths {
            let is_gated = file_gated || gated.contains(path);
            match self.counts.get_mut(path) {
                None => {
                    self.counts.insert(path.clone(), 1);
                    if is_gated {
                        self.gated_only.insert(path.clone());
                    }
                }
                Some(_) if is_gated && self.gated_only.contains(path) => {}
                Some(count) => {
                    *count += 1;
                    self.gated_only.remove(path);
                }
            }
        }
    }

    /// Record one file's renamed `mod` declarations, as [`declared_renames`](super::declared_renames)
    /// returned them; a path two declarations send apart resolves to nothing.
    pub fn add_renames(&mut self, renames: &BTreeMap<String, Option<String>>) {
        for (declared, placed) in renames {
            merge_rename(&mut self.renames, declared, placed.clone());
        }
    }

    /// Record one file's re-exported names, as
    /// [`index_facts`](super::index_facts) returned them; a name two files
    /// map apart resolves to nothing.
    pub fn add_exports(&mut self, exports: &ExportedNames) {
        for (name, target) in exports {
            merge_rename(&mut self.exports, name, target.clone());
        }
    }

    /// Record one file's struct field types, as
    /// [`index_facts`](super::index_facts) returned them. A struct another
    /// file declares too keeps no fields: which declaration a receiver holds
    /// is unknown.
    pub fn add_fields(&mut self, fields: &StructFields) {
        for (owner, types) in fields {
            match self.fields.get(owner) {
                Some(_) => {
                    self.fields.insert(owner.clone(), None);
                }
                None => {
                    self.fields.insert(owner.clone(), Some(types.clone()));
                }
            }
        }
    }

    /// `path` with its longest prefix that a `use` re-exports
    /// rewritten onto the path that `use` imports, once: `crate::visit::go`
    /// under `pub use cfg::go;` in `crate::visit` is `crate::visit::cfg::go`.
    /// `None` when no prefix is re-exported, or when the one that is maps
    /// apart. The path is remembered as a lookup: the re-exporting file sits
    /// in a module above it.
    ///
    /// A re-export whose target lies below the re-exported name itself
    /// (`pub use parse::parse;` beside `mod parse;`) binds that name only
    /// in the namespace of the item it imports, a function here, so a
    /// longer path through it (`crate::x::parse::helper`) goes through the
    /// module and is not rewritten. Rewriting it would also match again on
    /// the result, growing the path on every hop.
    pub fn exported(&self, path: &str) -> Option<String> {
        self.lookups.borrow_mut().insert(path.to_owned());
        let (prefix, target) = std::iter::successors(Some(path), |prefix| {
            prefix.rsplit_once("::").map(|(head, _)| head)
        })
        .find_map(|prefix| {
            let target = self.exports.get(prefix)?;
            let through_module = prefix.len() < path.len()
                && target
                    .as_deref()
                    .is_some_and(|target| target.starts_with(&format!("{prefix}::")));
            (!through_module).then_some((prefix, target))
        })?;

        target
            .as_ref()
            .map(|target| format!("{target}{}", &path[prefix.len()..]))
    }

    /// Record the names of one file's `use` bindings that only a private
    /// `use` makes, visible in their module and below it alone.
    pub fn add_private_exports(&mut self, names: &BTreeSet<String>) {
        self.private_exports.extend(names.iter().cloned());
    }

    /// Whether only a private `use` binds `name`, see
    /// [`Declarations::add_private_exports`].
    #[must_use]
    pub fn is_private_export(&self, name: &str) -> bool {
        self.private_exports.contains(name)
    }

    /// Record one file's glob imports, see [`GlobImports`].
    pub fn add_globs(&mut self, globs: &GlobImports) {
        for (module, sources) in globs {
            let known = self.globs.entry(module.clone()).or_default();
            for source in sources {
                if !known.contains(source) {
                    known.push(source.clone());
                }
            }
        }
    }

    /// The modules `module`'s glob imports import from, see
    /// [`GlobImports`]. Remembered as a lookup of `module`'s own path below
    /// it is the caller's: the importing file sits in `module`.
    #[must_use]
    pub fn globs_of(&self, module: &str) -> &[(String, bool)] {
        self.globs.get(module).map_or(&[], Vec::as_slice)
    }

    /// Record one file's re-export alternatives, see [`ExportAlternatives`];
    /// a name two files re-export apart has none.
    pub fn add_export_alternatives(&mut self, alternatives: &ExportAlternatives) {
        for (name, paths) in alternatives {
            let merged = match self.export_alternatives.get(name) {
                Some(known) if known.as_ref() != Some(paths) => None,
                _ => Some(paths.clone()),
            };
            self.export_alternatives.insert(name.clone(), merged);
        }
    }

    /// `path` rewritten through the `cfg` alternatives its longest prefix is
    /// re-exported as (see [`ExportAlternatives`]), one path per
    /// alternative; none when no prefix is. Remembered as a lookup, like
    /// [`Declarations::exported`].
    pub fn exported_alternatives(&self, path: &str) -> Vec<String> {
        self.lookups.borrow_mut().insert(path.to_owned());
        std::iter::successors(Some(path), |prefix| {
            prefix.rsplit_once("::").map(|(head, _)| head)
        })
        .find_map(|prefix| {
            let alternatives = self.export_alternatives.get(prefix)?.as_ref()?;
            Some(
                alternatives
                    .iter()
                    .map(|target| format!("{target}{}", &path[prefix.len()..]))
                    .collect(),
            )
        })
        .unwrap_or_default()
    }

    /// What a `use` binds the path `name` to, exactly: `Some(None)`
    /// when two of them bind it apart, `None` when none binds it. Remembered
    /// as a lookup, like [`Declarations::exported`].
    #[allow(clippy::option_option)]
    pub fn export_of(&self, name: &str) -> Option<Option<String>> {
        self.lookups.borrow_mut().insert(name.to_owned());
        self.exports.get(name).cloned()
    }

    /// Record one file's type aliases, as [`index_facts`](super::index_facts)
    /// returned them. A path another file aliases too names no one type.
    pub fn add_aliases(&mut self, aliases: &TypeAliases) {
        for (alias, target) in aliases {
            let known = self.aliases.get(alias).cloned();
            self.aliases.insert(
                alias.clone(),
                match known {
                    Some(earlier) if earlier.as_deref() != Some(target.as_str()) => None,
                    _ => Some(target.clone()),
                },
            );
        }
    }

    /// Record one file's function return types, as
    /// [`index_facts`](super::index_facts) returned them. A path another
    /// file declares a different return type at returns no one type.
    pub fn add_returns(&mut self, returns: &FunctionReturns) {
        for (function, returned) in returns {
            let known = self.returns.get(function).cloned();
            self.returns.insert(
                function.clone(),
                match known {
                    Some(earlier) if earlier != *returned => None,
                    _ => returned.clone(),
                },
            );
        }
    }

    /// The type the function at `path` returns, as its own file resolved
    /// it, remembering that `path` was asked about.
    pub fn return_of(&self, path: &str) -> Option<String> {
        self.lookups.borrow_mut().insert(path.to_owned());
        self.returns.get(path)?.clone()
    }

    /// The type the alias at `path` names, as its own file resolved it,
    /// remembering that `path` was asked about.
    pub fn alias_of(&self, path: &str) -> Option<String> {
        self.lookups.borrow_mut().insert(path.to_owned());
        self.aliases.get(path)?.clone()
    }

    /// The declared type of `owner`'s field `field`, as the struct's own
    /// file resolved it, remembering that `owner` was asked about: the file
    /// declaring it sits in a module above it.
    pub fn field_type(&self, owner: &str, field: &str) -> Option<FieldType> {
        self.lookups.borrow_mut().insert(owner.to_owned());
        self.fields.get(owner)?.as_ref()?.get(field).cloned()
    }

    /// `path` with its longest prefix that a `mod` declaration renamed
    /// rewritten onto the module that declaration loads, repeatedly, so
    /// `crate::a::x` under `#[path = "impl_a.rs"] mod a;` is
    /// `crate::impl_a::x`. `Some(None)` when a prefix is ambiguous, `None`
    /// when no prefix was renamed. Every path asked about is remembered as a
    /// lookup: the declaring file sits in a module above it.
    #[allow(clippy::option_option)]
    pub fn renamed(&self, path: &str) -> Option<Option<String>> {
        let mut current = path.to_owned();
        let mut changed = false;
        for _ in 0..=path.matches("::").count() {
            self.lookups.borrow_mut().insert(current.clone());
            let found = std::iter::successors(Some(current.as_str()), |prefix| {
                prefix.rsplit_once("::").map(|(head, _)| head)
            })
            .find_map(|prefix| Some((prefix.len(), self.renames.get(prefix)?)));
            match found {
                Some((_, None)) => return Some(None),
                Some((length, Some(placed))) => {
                    current = format!("{placed}{}", &current[length..]);
                    changed = true;
                }
                None => break,
            }
        }

        changed.then_some(Some(current))
    }

    /// Every name asked about since the last call, leaving the set empty.
    pub fn take_lookups(&self) -> BTreeSet<String> {
        std::mem::take(&mut *self.lookups.borrow_mut())
    }
}
