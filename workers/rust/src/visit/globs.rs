//! Glob imports: resolving what each brings in, and which source provides
//! a name written unqualified.

use super::state::Walk;
use crate::facts::reference;
use crate::resolve::is_root;
use crate::resolve::rebase;

impl Walk<'_> {
    /// Resolve the glob imports [`Walk::collect_uses`] set aside, now that
    /// every alias in the file is known: `use parts::*;` beside
    /// `use crate::left as parts;` imports from `crate::left`, whichever line
    /// comes first.
    pub(super) fn resolve_globs(&mut self) {
        let source = reference("module", &self.module);
        for (container, written, absolute, span, private) in std::mem::take(&mut self.pending_globs)
        {
            // `::dep::*` names the external crate whatever the file calls
            // `dep`; otherwise a leading alias is one this module declares.
            let expanded = if absolute {
                written
            } else {
                let (head, rest) = written.split_once("::").unwrap_or((written.as_str(), ""));
                match self
                    .module_aliases
                    .get(&(container.clone(), head.to_owned()))
                {
                    Some(Some(full)) if rest.is_empty() => full.clone(),
                    Some(Some(full)) => format!("{full}::{rest}"),
                    // `use Kind::*;` beside `enum Kind` names this module's.
                    None if !is_root(head)
                        && self
                            .own_declarations
                            .contains(&format!("{container}::{head}")) =>
                    {
                        format!("{container}::{written}")
                    }
                    _ => written.clone(),
                }
            };
            let Some(full) = rebase(&container, &self.anchor_crate(&expanded))
                .and_then(|full| self.renamed(full))
            else {
                continue;
            };
            self.import(&source, &full, span);
            let glob = (container, full);
            if private {
                self.private_globs.insert(glob.clone());
            }
            if !self.globs.contains(&glob) {
                self.globs.push(glob);
            }
        }
    }

    /// `path` rewritten through the glob imports of a module it goes
    /// through, when that module neither declares nor re-exports the name
    /// below it: `crate::v2::Profile` under `pub use types::*;` in
    /// `crate::v2` is `crate::v2::types::Profile`, and `super::digest` in a
    /// child of a module with `use super::*;` reaches what the grandparent
    /// declares. The module is the longest prefix of `path` with glob
    /// imports; a name its sources provide apart resolves to nothing, as in
    /// [`Walk::through_globs`]. `None` when `path` is declared as written, or
    /// nothing provides it.
    pub(super) fn through_glob_imports(&self, path: &str) -> Option<String> {
        if self.declarations.get(path).is_some() {
            return None;
        }
        let mut module = path;
        while let Some((prefix, _)) = module.rsplit_once("::") {
            module = prefix;
            // A private glob is seen only by the module that writes it and
            // the ones below.
            let inside = self.module == module || self.module.starts_with(&format!("{module}::"));
            let sources: Vec<String> = self
                .declarations
                .globs_of(module)
                .iter()
                .filter(|(_, private)| !private || inside)
                .map(|(source, _)| source.clone())
                .collect();
            if sources.is_empty() {
                continue;
            }
            let rest = &path[module.len() + 2..];
            let head = rest.split("::").next().unwrap_or(rest);
            if self
                .declarations
                .get(&format!("{module}::{head}"))
                .is_some()
            {
                return None;
            }
            return match self.through_globs(module, &sources, rest) {
                Globbed::One(target) if target != path => Some(target),
                _ => None,
            };
        }

        None
    }

    /// The modules the glob imports `container` itself declares import
    /// from, in the order written: only those bring names into it.
    pub(super) fn glob_sources(&self, container: &str) -> Vec<String> {
        self.globs
            .iter()
            .filter(|(declared_in, _)| declared_in == container)
            .map(|(_, glob)| glob.clone())
            .collect()
    }

    /// What an unqualified `rendered` path names through glob imports from
    /// `sources`, asked of the whole index.
    ///
    /// A source provides the path when exactly one file declares the whole
    /// path there, when a file declares its head there, or when a visible
    /// `use` there re-exports its head (followed to where it is declared).
    /// A source the index knows as a type (`use Kind::*;`) is skipped: it
    /// brings in variants, which are not indexed, and never the type's
    /// associated functions. One target is the answer. Several different
    /// ones are ambiguous, as Rust holds two globs naming one item apart,
    /// so none of them is guessed. A source providing nothing by that name,
    /// such as one that does not declare `Vec`, adds nothing, so a standard
    /// name never becomes a made-up path inside the source. Each source is
    /// taken through the project's renamed `mod` declarations first, which
    /// a source recorded before the index was known has not been.
    ///
    /// A source's private `use` (`use super::Profile;` in a child) is
    /// visible, and so brought in, only when `importer`, the module writing
    /// the glob, sits inside the source (`use super::*;`): a parent's
    /// `pub use child::*;` does not import what the child itself imports
    /// privately.
    pub(super) fn through_globs(
        &self,
        importer: &str,
        sources: &[String],
        rendered: &str,
    ) -> Globbed {
        self.through_globs_within(importer, sources, rendered, 0)
    }

    /// [`Walk::through_globs`] `depth` globs away from the module that
    /// wrote the first one. A source that provides the name neither as a
    /// declaration nor as a `use` provides it when its own glob imports do
    /// (`pub use types::*;` in a module a child imports with `use
    /// super::*;`), a private glob only to an importer inside the source.
    pub(super) fn through_globs_within(
        &self,
        importer: &str,
        sources: &[String],
        rendered: &str,
        depth: usize,
    ) -> Globbed {
        /// Globs followed from one name at most, which a cycle of glob
        /// imports cannot outrun.
        const MAX_GLOB_DEPTH: usize = 4;
        let head = rendered.split("::").next().unwrap_or(rendered);
        let mut found: Option<String> = None;
        for source in sources {
            let Some(source) = self.renamed(source.clone()) else {
                continue;
            };
            if self.declarations.get(&source).is_some() {
                continue;
            }
            let full = format!("{source}::{rendered}");
            let named = format!("{source}::{head}");
            let provided = match self.declarations.get(&full) {
                Some(1) => Some(full.clone()),
                Some(_) => return Globbed::Ambiguous,
                None if named == full => None,
                None => match self.declarations.get(&named) {
                    Some(1) => Some(full.clone()),
                    Some(_) => return Globbed::Ambiguous,
                    None => None,
                },
            };
            let hidden = self.declarations.is_private_export(&named)
                && !importer.starts_with(&format!("{source}::"));
            let provided = match provided {
                Some(provided) => provided,
                None if hidden => continue,
                None => match self.declarations.export_of(&named) {
                    Some(Some(_)) => self.exported(full),
                    Some(None) => return Globbed::Ambiguous,
                    None if depth < MAX_GLOB_DEPTH => {
                        let nested: Vec<String> = self
                            .declarations
                            .globs_of(&source)
                            .iter()
                            .filter(|(_, private)| {
                                !private || importer.starts_with(&format!("{source}::"))
                            })
                            .map(|(nested, _)| nested.clone())
                            .collect();
                        match self.through_globs_within(importer, &nested, rendered, depth + 1) {
                            Globbed::One(provided) => provided,
                            Globbed::Ambiguous => return Globbed::Ambiguous,
                            Globbed::Absent => continue,
                        }
                    }
                    None => continue,
                },
            };
            match &found {
                Some(known) if *known != provided => return Globbed::Ambiguous,
                _ => found = Some(provided),
            }
        }

        found.map_or(Globbed::Absent, Globbed::One)
    }
}

/// What glob imports bring in under one name (see [`Walk::through_globs`]).
pub(super) enum Globbed {
    /// No source provides it.
    Absent,
    /// Every source that provides it names this path.
    One(String),
    /// Sources provide it as different items.
    Ambiguous,
}
