//! Which generated mock a path in test code names (Decision 3), resolved
//! as the walk resolves a type path: the module's own items, its imports
//! (`module_aliases`), then its glob imports (`use super::*`), and through
//! another file's re-exports and globs as the index holds them.
//!
//! The walked file's own generators are asked first (R5-1), so a mock of a
//! file the index does not answer for still resolves; every other answer
//! comes from [`Declarations::generated_mock`], which records the lookup,
//! so editing the generating file reaches the test. Only a last segment
//! starting `Mock` or `mock_` is ever looked up (R5-4): every name a
//! generator gives does, and no other lookup changes a file's reads.

use std::collections::{BTreeMap, BTreeSet};

use crate::layout::Layout;
use crate::resolve::{ident_name, rebase};
use crate::visit::{is_test_module_path, Declarations, FileScope, GeneratedMock, ModuleGates};

/// How many import and glob hops a name is followed through.
const MAX_HOPS: usize = 8;

/// Resolves the mock types a file's test code names.
pub(super) struct MockResolver<'a> {
    /// The mocks the walked file's own items generate.
    pub(super) own: &'a BTreeMap<String, GeneratedMock>,
    /// The walked file's imports and globs.
    pub(super) scope: &'a FileScope,
    /// The scan-wide index.
    pub(super) declarations: &'a Declarations,
    /// The modules declared `#[cfg(test)] mod name;`.
    pub(super) test_modules: &'a ModuleGates,
    /// Where the project's crates are.
    pub(super) layout: &'a Layout,
}

impl<'a> MockResolver<'a> {
    /// The generated mock `path`, written in `container`, names, with its
    /// canonical path; `None` for anything else, or for a mock its test
    /// gate hides from this crate (a `cfg(test)` generator named from
    /// `tests/`).
    pub(super) fn resolve(
        &self,
        container: &str,
        path: &syn::Path,
    ) -> Option<(String, &'a GeneratedMock)> {
        let names: Vec<String> = path
            .segments
            .iter()
            .map(|segment| ident_name(&segment.ident))
            .collect();
        let last = names.last()?;
        if !(last.starts_with("Mock") || last.starts_with("mock_")) {
            return None;
        }
        let (canonical, mock, own) = if path.leading_colon.is_some() {
            // `::probe::clock::MockClock` names a crate, never a module.
            let (head, tail) = names.split_first()?;
            let root = self.layout.libraries.get(head.as_str())?;
            let (name, module) = tail.split_last()?;
            let module = std::iter::once(root.as_str())
                .chain(module.iter().map(String::as_str))
                .collect::<Vec<_>>()
                .join("::");
            self.in_module(&module, name, 0, &mut BTreeSet::new())?
        } else {
            self.find(container, &names)?
        };
        let gated = mock.test_gated || is_test_module_path(parent(&canonical), self.test_modules);
        // A test-only generator exists in its own crate's test build alone.
        if gated && !own && crate_of(&canonical) != crate_of(container) {
            return None;
        }
        Some((canonical, mock))
    }

    /// The canonical path, the mock and whether the walked file generates
    /// it, for the path `names` written in `container`.
    fn find(&self, container: &str, names: &[String]) -> Option<(String, &'a GeneratedMock, bool)> {
        let (head, rest) = names.split_first()?;
        if rest.is_empty() {
            return self.in_module(container, head, 0, &mut BTreeSet::new());
        }
        let tail = rest.join("::");
        let written = format!("{head}::{tail}");
        let full = match head.as_str() {
            "crate" => format!("{}::{tail}", self.scope.crate_module),
            "self" | "super" => rebase(container, &written)?,
            _ => match self
                .scope
                .module_aliases
                .get(&(container.to_owned(), head.clone()))
            {
                Some(Some(imported)) => format!("{imported}::{tail}"),
                Some(None) => return None,
                None => match self.layout.libraries.get(head.as_str()) {
                    Some(root) => format!("{root}::{tail}"),
                    None => format!("{container}::{written}"),
                },
            },
        };
        let (module, name) = full.rsplit_once("::")?;
        self.in_module(module, name, 0, &mut BTreeSet::new())
    }

    /// The mock `name` names in `module`: its own, its import of that name,
    /// else what its globs bring in, `hops` imports deep. A path already
    /// asked about (`seen`) gives nothing more: imports and globs may go
    /// round in a circle, and many globs may lead to one module.
    fn in_module(
        &self,
        module: &str,
        name: &str,
        hops: usize,
        seen: &mut BTreeSet<String>,
    ) -> Option<(String, &'a GeneratedMock, bool)> {
        let own = format!("{module}::{name}");
        if hops > MAX_HOPS || !seen.insert(own.clone()) {
            return None;
        }
        if let Some(mock) = self.own.get(&own) {
            return Some((own, mock, true));
        }
        if let Some(imported) = self
            .scope
            .module_aliases
            .get(&(module.to_owned(), name.to_owned()))
        {
            let (parent, last) = imported.as_deref()?.rsplit_once("::")?;
            return self.in_module(parent, last, hops + 1, seen);
        }
        if let Some(mock) = self.declarations.generated_mock(&own) {
            return Some((own, mock, false));
        }
        if let Some(Some(exported)) = self.declarations.export_of(&own) {
            let (parent, last) = exported.rsplit_once("::")?;
            return self.in_module(parent, last, hops + 1, seen);
        }
        let sources: Vec<String> = self
            .scope
            .globs
            .iter()
            .filter(|(importer, _)| importer == module)
            .map(|(_, source)| source.clone())
            .chain(
                self.declarations
                    .globs_of(module)
                    .iter()
                    .map(|(source, _)| source.clone()),
            )
            .collect();
        sources
            .iter()
            .find_map(|source| self.in_module(source, name, hops + 1, seen))
    }
}

/// The module that holds `path`.
fn parent(path: &str) -> &str {
    path.rsplit_once("::").map_or(path, |(parent, _)| parent)
}

/// The crate a module path sits in, by its first segment: `crate`, a
/// workspace member's name, or a test target's (`tests`).
fn crate_of(path: &str) -> &str {
    path.split("::").next().unwrap_or(path)
}
