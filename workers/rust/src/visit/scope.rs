//! The names a walked file brings into scope, kept past its walk for the
//! readers that run after it (see `crate::test_facts`).

use std::collections::BTreeMap;

use super::state::Walk;

/// The resolution state a file's walk built: each module's imported names
/// and its glob imports, as the walk placed them. Handed on so a later
/// reader resolves a name the way the walk did, rather than through a second
/// resolver that could drift from it.
#[derive(Debug, Default)]
pub struct FileScope {
    /// Each module's own imported names, by module then name, mapped to the
    /// path imported; `None` marks a name bound twice (see
    /// `Walk::module_aliases`).
    pub(crate) module_aliases: BTreeMap<(String, String), Option<String>>,
    /// The modules each module's glob imports bring every name of into
    /// scope, as `(importing module, source module)` pairs in the order
    /// written (see `Walk::globs`).
    pub(crate) globs: Vec<(String, String)>,
    /// The module `crate::` names in the file: the crate root's, or the
    /// file's own for a target root (`tests/it.rs`), see
    /// `Walk::crate_module`.
    pub(crate) crate_module: String,
}

impl Walk<'_> {
    /// Move the finished walk's resolution state out (see [`FileScope`]).
    pub(super) fn take_scope(&mut self) -> FileScope {
        FileScope {
            module_aliases: std::mem::take(&mut self.module_aliases),
            globs: std::mem::take(&mut self.globs),
            crate_module: self.crate_module.clone(),
        }
    }
}
