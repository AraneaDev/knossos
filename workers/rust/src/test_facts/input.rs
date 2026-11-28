//! What the pass reads one file through, apart from the pass itself so the
//! modules that read it need not depend on the module that runs them.

use std::collections::BTreeMap;

use crate::facts::Facts;
use crate::layout::Layout;
use crate::visit::{Declarations, FileScope, ModuleGates};

/// Everything the pass reads one file through, all of it read-only.
pub(crate) struct TestInput<'a> {
    /// The file's project-relative path.
    pub(crate) relative: &'a str,
    /// The module the file is placed in.
    pub(crate) module: &'a str,
    /// The parsed file.
    pub(crate) file: &'a syn::File,
    /// The scan-wide declaration index, asked only quietly (see
    /// [`Declarations::declares_quietly`]).
    pub(crate) declarations: &'a Declarations,
    /// Where the project's crates are.
    pub(crate) layout: &'a Layout,
    /// The modules declared `#[cfg(test)] mod name;`.
    pub(crate) test_modules: &'a ModuleGates,
    /// The names the file's walk brought into scope.
    pub(crate) scope: &'a FileScope,
    /// Every file the scan read, with its hash (`None` when it could not be
    /// read): which of a module's possible files exists.
    pub(crate) read: &'a BTreeMap<String, Option<String>>,
    /// The walk's facts for the file.
    pub(crate) facts: &'a Facts,
}
