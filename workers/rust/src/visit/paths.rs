//! Resolving the paths a file writes to the canonical paths the graph uses.
//!
//! A path is taken through this file's imports and glob imports, the
//! `crate`, `self`, `super` and `Self` roots, the `mod` declarations that
//! place a module elsewhere, and finally the crate-wide declaration index.
//! An answer that rests on a guess says so, so a caller can demand that
//! this file confirms it. The file's own `use` lines are read here first,
//! since they are what every later path resolves through.

use super::globs::Globbed;
use super::state::Walk;
use crate::resolve::{ident_name, rebase};

impl Walk<'_> {
    /// The canonical target a syntactic path names, resolved through `use`.
    ///
    /// A path rooted at `::` or `crate` is absolute and taken as written. A path
    /// rooted at `self` or `super` is relative to `container` and is rebased
    /// through [`rebase`] — `self::foo` in `crate::net` names `crate::net::foo`,
    /// and `super::foo` in `crate::net::http` names `crate::net::foo`. A bare or
    /// aliased head is expanded through the import map. Anything the map cannot
    /// place is attributed to `container`, which is where an unqualified name
    /// declared in the same file lives — unless the head names an alias that
    /// was imported ambiguously (two different `use` lines bound the same
    /// local name to two different full paths). That case is not "unknown";
    /// it is known and poisoned, and guessing a target for it would silently
    /// prefer one of the two conflicting imports over the other with no
    /// basis for the choice, so it returns `None` instead of falling through
    /// to the container guess — see `Aliases::is_ambiguous`.
    ///
    /// Returns `None` for an empty path, or for a `super` chain longer than
    /// `container` has segments to give up — see [`rebase`].
    pub(super) fn path_target(&self, container: &str, path: &syn::Path) -> Option<String> {
        self.resolve_path(container, path).map(|(target, _)| target)
    }

    /// [`Walk::path_target`], plus whether the answer still has to be confirmed
    /// against this file's own declarations before an edge is built from it.
    ///
    /// The second element is `true` in two cases. One is the container-relative
    /// fallback: the path was neither rooted (`crate`, `::`, `self`, `super`)
    /// nor expandable through the import map, so the target was assembled by
    /// assuming the name is declared alongside the code that writes it.
    ///
    /// The other is a path of exactly *one* segment that reaches a rooted
    /// branch, which never means what it renders as. `syn` puts a
    /// `leading_colon` on the bare `default` of `<Widget>::default()`, because
    /// the qualified self takes position 0 and the trait is absent, so the path
    /// reads as the absolute `::default`. A lone `self`, the callee of a call
    /// through an `Fn` receiver (`self()`), rebases onto the *enclosing module*
    /// rather than naming anything inside it. Trusting either produced a target
    /// no declaration can match: the literal one-word name `default`, or a
    /// `rust:function:` reference to a path that names a module, which the
    /// reconciler materialises as an external twin of a real node. Multi-segment
    /// rooted paths (`crate::helper`, `::a::b`, `self::go`, `super::go`) mean
    /// exactly what they say and stay trusted.
    ///
    /// A single segment the *import map* expands stays trusted too: `use
    /// a::b::go;` followed by `go()` names `a::b::go` because a `use` line in
    /// this same file said so.
    ///
    /// Callers that can afford an unconfirmed answer (an `impl` self type, which
    /// only ever attaches methods to a path this same file also declares) take
    /// [`Walk::path_target`] and ignore the flag. A `calls` edge cannot: its
    /// target reaches the reconciler as-is, and an unconfirmed target that names
    /// nothing becomes a fabricated external node. See
    /// [`Calls::visit_call`](super::state::Calls::visit_call).
    ///
    /// A path a visible `use` re-exports (`crate::visit::go` under `pub use
    /// cfg::go;`) names the item it imports, which is where the graph
    /// declares it (see [`Walk::exported`]).
    pub(super) fn resolve_path(&self, container: &str, path: &syn::Path) -> Option<(String, bool)> {
        self.resolve_written(container, path)
            .map(|(target, unconfirmed)| (self.exported(target), unconfirmed))
    }

    /// Every target [`Walk::resolve_path`] gives `path` across its `cfg`
    /// alternatives: once per alternative a `use` binds its head to
    /// (`platform::flush()` under `use unix as platform;` and
    /// `use windows as platform;`), and once per alternative a re-export it
    /// is written through names (`crate::fs::open` under a gated
    /// `pub use unix::open;` and a Windows one). Only one compiles in any
    /// build, so an edge to each is what the code means; a path with no
    /// alternatives gives what `resolve_path` does.
    pub(super) fn resolve_paths(&self, container: &str, path: &syn::Path) -> Vec<(String, bool)> {
        let head = path
            .segments
            .first()
            .map(|segment| ident_name(&segment.ident))
            .unwrap_or_default();
        let alternatives = if path.leading_colon.is_none() {
            self.aliases.alternatives(&head)
        } else {
            Vec::new()
        };
        let written: Vec<(String, bool)> = if alternatives.is_empty() {
            self.resolve_written(container, path).into_iter().collect()
        } else {
            alternatives
                .iter()
                .filter_map(|alternative| {
                    self.aliases.choose(&head, alternative);
                    let target = self.resolve_written(container, path);
                    self.aliases.unchoose();
                    target
                })
                .collect()
        };
        let mut targets: Vec<(String, bool)> = Vec::new();
        for (target, unconfirmed) in written {
            for exported in self.exported_all(target) {
                if !targets.iter().any(|(known, _)| *known == exported) {
                    targets.push((exported, unconfirmed));
                }
            }
        }

        targets
    }

    /// [`Walk::exported`], once per `cfg` alternative a re-export of `path`
    /// or a module above it names (see
    /// [`Declarations::exported_alternatives`](super::declarations::Declarations::exported_alternatives)).
    fn exported_all(&self, path: String) -> Vec<String> {
        if !self.in_project(&path) {
            return vec![path];
        }
        let alternatives = self.declarations.exported_alternatives(&path);
        if alternatives.is_empty() {
            return vec![self.exported(path)];
        }
        alternatives
            .into_iter()
            .filter_map(|alternative| self.renamed(alternative))
            .map(|alternative| self.exported(alternative))
            .collect()
    }

    /// [`Walk::resolve_path`] before re-exports are followed: the path as
    /// the file's imports, roots and `mod` declarations place it.
    pub(super) fn resolve_written(
        &self,
        container: &str,
        path: &syn::Path,
    ) -> Option<(String, bool)> {
        let rendered = path
            .segments
            .iter()
            .map(|segment| ident_name(&segment.ident))
            .collect::<Vec<_>>()
            .join("::");
        if rendered.is_empty() {
            return None;
        }
        let single_segment = path.segments.len() == 1;
        if path.leading_colon.is_some() {
            return match self.library_path(&rendered) {
                Some(library) => self.renamed(library),
                None => Some(rendered),
            }
            .map(|target| (target, single_segment));
        }
        if rendered == "crate" || rendered.starts_with("crate::") {
            return self
                .renamed(self.anchor_crate(&rendered))
                .map(|target| (target, single_segment));
        }
        if rendered == "Self" || rendered.starts_with("Self::") {
            if let Some(target) = &self.current_impl_target {
                return Some((rendered.replacen("Self", target, 1), single_segment));
            }
        }
        if rendered == "self"
            || rendered.starts_with("self::")
            || rendered == "super"
            || rendered.starts_with("super::")
        {
            return rebase(container, &rendered)
                .and_then(|target| self.renamed(target))
                .map(|target| (target, single_segment));
        }
        if let Some(answer) = self.through_renamed_child(container, &rendered) {
            return answer;
        }
        if let Some(answer) = self.through_child_module(container, &rendered) {
            return answer;
        }
        if let Some(expanded) = self.aliases.expand(&rendered) {
            return Some((expanded, false));
        }
        if let Some(library) = self.library_path(&rendered) {
            return self.renamed(library).map(|target| (target, single_segment));
        }
        let head = rendered.split("::").next().unwrap_or(&rendered);
        if self.aliases.is_ambiguous(head) {
            return None;
        }

        // The crate-wide declaration index: an unimported name can still be
        // placed when exactly one file of the project declares it at an
        // address the path could mean. Candidates are tried in Rust scoping
        // order: the enclosing module first, then what its glob imports
        // bring in, then the crate root, then the path as written. So
        // `sign::any_supported_type` from the crate root resolves to the
        // child-module function, and a same-file name to its own container.
        // Unlike the container-relative fallback below, an index hit is
        // trusted outright: the node it names exists in the graph, even when
        // the declaring file is not this one.
        let own = format!("{container}::{rendered}");
        match self.declarations.get(&own) {
            Some(1) => return Some((own, false)),
            // Ambiguous across files: guessing would silently prefer one
            // declaration over another, so the scoping winner must not
            // fall through to a weaker candidate.
            Some(_) => return None,
            None => {}
        }
        // A type the module declares itself shadows any a glob brings in,
        // so a path below it (`Widget::new`) stays in the module, to be
        // confirmed by this file like any other guess there.
        if !single_segment {
            let head = rendered.split("::").next().unwrap_or(&rendered);
            let own_head = format!("{container}::{head}");
            if self.own_declarations.contains(&own_head)
                || self.declarations.get(&own_head).is_some()
            {
                return Some((own, true));
            }
        }
        match self.through_globs(container, &self.glob_sources(container), &rendered) {
            Globbed::One(target) => return Some((target, false)),
            Globbed::Ambiguous => return None,
            Globbed::Absent => {}
        }
        // A prelude trait is in scope everywhere, while a crate-root item
        // of that name is in scope only in the crate root: past the module's
        // own declarations and its globs, the prelude wins.
        if !(single_segment && standard_trait(&rendered).is_some()) {
            for candidate in self.index_candidates(container, &rendered) {
                match self.declarations.get(&candidate) {
                    Some(1) => return Some((candidate, false)),
                    Some(_) => return None,
                    None => {}
                }
            }
        }

        // A standard trait named bare that nothing in scope declares or
        // imports is the standard library's: `impl From<u8> for Str` in
        // `crate::util` implements `std::convert::From`, never a
        // `crate::util::From` the fallback below would guess.
        if single_segment {
            if let Some(standard) = standard_trait(&rendered) {
                return Some((standard.to_owned(), false));
            }
        }

        Some((format!("{container}::{rendered}"), true))
    }

    /// A path whose head is a `mod name;` of `container` that names a module
    /// other than `container::name` (see [`Walk::renamed_children`]), placed
    /// in that module and answered by the declaration index as any other
    /// child-module path is: one declaration is trusted, several resolve to
    /// nothing, none leaves the target to be confirmed by this file. `None`
    /// for any other path.
    #[allow(clippy::option_option)]
    fn through_renamed_child(
        &self,
        container: &str,
        rendered: &str,
    ) -> Option<Option<(String, bool)>> {
        let (head, rest) = rendered.split_once("::")?;
        let Some(child) = self
            .renamed_children
            .get(&(container.to_owned(), head.to_owned()))?
        else {
            return Some(None);
        };
        let target = format!("{child}::{rest}");

        Some(match self.declarations.get(&target) {
            Some(1) => Some((target, false)),
            Some(_) => None,
            None => Some((target, true)),
        })
    }

    /// A multi-segment path whose head is both an import and a child module
    /// the import reaches into: `parse::helper()` beside `mod parse;` and
    /// `pub use parse::parse;` names the module's `helper`, since only a
    /// module has items below it. `None` for any other path.
    #[allow(clippy::option_option)]
    fn through_child_module(
        &self,
        container: &str,
        rendered: &str,
    ) -> Option<Option<(String, bool)>> {
        let (head, rest) = rendered.split_once("::")?;
        let module = format!("{container}::{head}");
        let imported = self.aliases.expand(head)?;
        if !imported.starts_with(&format!("{module}::")) {
            return None;
        }

        Some(
            self.renamed(format!("{module}::{rest}"))
                .map(|target| (target, false)),
        )
    }

    /// The name this file's crate root has in the graph: `crate` for the
    /// package at the project root, the crate's own name for a workspace
    /// member (see `module_path_in_crate`).
    pub(super) fn crate_root(&self) -> &str {
        self.module.split("::").next().unwrap_or("crate")
    }

    /// A path as written, with its root as the graph names it: `crate` is
    /// this file's crate root (a target root's own module, see
    /// [`Walk::crate_module`]), and a project library's crate
    /// name its root (see [`Walk::library_path`]).
    pub(super) fn anchor_crate(&self, path: &str) -> String {
        if path == "crate" {
            return self.crate_module.clone();
        }
        match path.strip_prefix("crate::") {
            Some(rest) => format!("{}::{rest}", self.crate_module),
            None => self.library_path(path).unwrap_or_else(|| path.to_owned()),
        }
    }

    /// A path inside the project, rewritten through every `mod` declaration
    /// of the project that loads a module other than its declared path (see
    /// [`Declarations::renamed`](super::declarations::Declarations::renamed)); `None` when
    /// one of those is ambiguous.
    /// A path outside the project is returned unchanged and asks nothing.
    pub(super) fn renamed(&self, path: String) -> Option<String> {
        if !self.in_project(&path) {
            return Some(path);
        }
        match self.declarations.renamed(&path) {
            Some(placed) => placed,
            None => Some(path),
        }
    }

    /// A path inside the project, followed through every visible `use` that
    /// re-exports it or a module above it (see
    /// [`Declarations::exported`](super::declarations::Declarations::exported)),
    /// to the path the graph declares the item at: `crate::visit::go` under
    /// `pub use cfg::go;` is `crate::visit::cfg::go`. A chain of re-exports
    /// is followed to its end, within a bound a cycle cannot outrun. A path
    /// outside the project, or one nothing re-exports, is returned unchanged.
    pub(super) fn exported(&self, path: String) -> String {
        /// Re-exports followed for one path at most.
        const MAX_HOPS: usize = 8;
        // `Declarations::exported` never rewrites a path through a prefix
        // whose re-export targets something below it, so a path cannot grow
        // by matching one re-export again; the visited set and the bound
        // end a cycle of re-exports.
        let mut current = path;
        let mut visited = std::collections::BTreeSet::new();
        for _ in 0..MAX_HOPS {
            if !self.in_project(&current) || !visited.insert(current.clone()) {
                break;
            }
            let Some(next) = self
                .declarations
                .exported(&current)
                .or_else(|| self.through_glob_imports(&current))
            else {
                break;
            };
            match self.renamed(next) {
                Some(next) if next != current => current = next,
                _ => break,
            }
        }

        current
    }

    /// Whether `path` is rooted in this project: at this file's crate root,
    /// its target root's crate, or a workspace member's crate.
    pub(super) fn in_project(&self, path: &str) -> bool {
        let head = path.split("::").next().unwrap_or(path);
        head == self.crate_root()
            || head == self.crate_module.split("::").next().unwrap_or("")
            || self.layout.is_project_root(head)
    }

    /// A path headed by the crate name of one of the project's libraries,
    /// rewritten onto that library's root in the graph: `my_demo::run` in
    /// `src/main.rs`, `tests/` or `examples/` is `crate::run`, the node the
    /// library's own file declares. A head that is this file's own crate root
    /// is left alone, since it already is a graph root.
    fn library_path(&self, path: &str) -> Option<String> {
        let head = path.split("::").next().unwrap_or(path);
        if head == self.crate_root() {
            return None;
        }

        self.layout.library_path(path)
    }

    /// The paths past the enclosing module and its globs that an
    /// unqualified `rendered` path could name, in scoping order: the crate
    /// root, then the path as written.
    fn index_candidates(&self, container: &str, rendered: &str) -> Vec<String> {
        let mut candidates = Vec::with_capacity(2);
        if container != self.crate_root() {
            candidates.push(format!("{}::{rendered}", self.crate_root()));
        }
        if rendered != format!("{container}::{rendered}") {
            candidates.push(rendered.to_owned());
        }

        candidates
    }
}

/// The standard library path of a trait the Rust 2021 prelude brings into
/// every module, by its bare name. A trait outside the prelude (`Display`,
/// `Hash`) is in scope only through an import, which resolves it.
pub(super) fn standard_trait(name: &str) -> Option<&'static str> {
    Some(match name {
        "AsMut" => "std::convert::AsMut",
        "AsRef" => "std::convert::AsRef",
        "From" => "std::convert::From",
        "Into" => "std::convert::Into",
        "TryFrom" => "std::convert::TryFrom",
        "TryInto" => "std::convert::TryInto",
        "Clone" => "std::clone::Clone",
        "Copy" => "std::marker::Copy",
        "Send" => "std::marker::Send",
        "Sized" => "std::marker::Sized",
        "Sync" => "std::marker::Sync",
        "Unpin" => "std::marker::Unpin",
        "Default" => "std::default::Default",
        "Eq" => "std::cmp::Eq",
        "Ord" => "std::cmp::Ord",
        "PartialEq" => "std::cmp::PartialEq",
        "PartialOrd" => "std::cmp::PartialOrd",
        "DoubleEndedIterator" => "std::iter::DoubleEndedIterator",
        "ExactSizeIterator" => "std::iter::ExactSizeIterator",
        "Extend" => "std::iter::Extend",
        "FromIterator" => "std::iter::FromIterator",
        "IntoIterator" => "std::iter::IntoIterator",
        "Iterator" => "std::iter::Iterator",
        "Drop" => "std::ops::Drop",
        "Fn" => "std::ops::Fn",
        "FnMut" => "std::ops::FnMut",
        "FnOnce" => "std::ops::FnOnce",
        "ToOwned" => "std::borrow::ToOwned",
        "ToString" => "std::string::ToString",
        _ => return None,
    })
}
