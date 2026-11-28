//! What a Cargo manifest says of its `pretty_assertions` dependency, read
//! without a TOML parser (G5-07): the worker reads that crate's
//! `assert_eq!` and `assert_ne!` as the standard ones only when the name is
//! the real crate. A spelling not read leaves the dependency undeclared, so
//! its macros stay untrusted: silence, never a wrong comparison.

/// The crate whose comparison macros compare as the standard ones do.
const PRETTY_ASSERTIONS: &str = "pretty_assertions";

/// The dependency tables of a package, under `[target.….]` too.
const DEPENDENCY_TABLES: [&str; 5] = [
    "dependencies",
    "dev-dependencies",
    "build-dependencies",
    "dev_dependencies",
    "build_dependencies",
];

/// What one manifest says of a dependency named `pretty_assertions`.
#[derive(Debug, Default, PartialEq, Eq)]
pub(crate) struct PrettyDependency {
    /// A dependency table of the package names it.
    pub(crate) declared: bool,
    /// One of those inherits it from the workspace (`workspace = true`).
    pub(crate) inherited: bool,
    /// One of those names another package for it (`package = "x"`).
    pub(crate) foreign: bool,
    /// `[workspace.dependencies]` names it.
    pub(crate) workspace: bool,
    /// The workspace's names another package for it.
    pub(crate) workspace_foreign: bool,
}

impl PrettyDependency {
    /// Whether the package's dependency is the real crate, given whether
    /// the workspace's is (`workspace_real`).
    pub(crate) fn is_real(&self, workspace_real: bool) -> bool {
        self.declared && !self.foreign && (!self.inherited || workspace_real)
    }

    /// Note one key of the dependency, `key = value` as written, in the
    /// workspace's table when `workspace`.
    fn note(&mut self, workspace: bool, key: &str, value: &str) {
        let named = value.trim().trim_matches(['"', '\'']);
        let foreign = key == "package" && crate_name(named) != PRETTY_ASSERTIONS;
        if workspace {
            self.workspace = true;
            self.workspace_foreign |= foreign;
        } else {
            self.declared = true;
            self.foreign |= foreign;
            self.inherited |= key == "workspace" && value.trim() == "true";
        }
    }

    /// Note a dependency's value: a version string, or an inline table
    /// whose keys are noted one by one.
    fn note_value(&mut self, workspace: bool, value: &str) {
        self.note(workspace, "version", "");
        if let Some(inner) = value.trim().strip_prefix('{') {
            for pair in inner.split('}').next().unwrap_or_default().split(',') {
                if let Some((key, value)) = pair.split_once('=') {
                    self.note(workspace, key.trim(), value);
                }
            }
        }
    }
}

/// A Cargo name as Rust code spells it.
fn crate_name(name: &str) -> String {
    name.replace('-', "_")
}

/// Which table a header opens: a dependency table (`own` false) or the
/// table of `pretty_assertions` itself (`own`), with whether it is the
/// workspace's. `None` for any other table.
fn dependency_table(header: &str) -> Option<(bool, bool)> {
    let inner = header
        .strip_prefix('[')?
        .split(']')
        .next()?
        .trim()
        .replace('"', "");
    let (table, own) = match inner.rsplit_once('.') {
        Some((table, leaf)) if crate_name(leaf.trim()) == PRETTY_ASSERTIONS => (table.trim(), true),
        _ => (inner.as_str(), false),
    };
    if table == "workspace.dependencies" {
        return Some((true, own));
    }
    DEPENDENCY_TABLES
        .iter()
        .any(|name| {
            table == *name || (table.starts_with("target.") && table.ends_with(&format!(".{name}")))
        })
        .then_some((false, own))
}

/// What `contents`, one manifest, says of its `pretty_assertions`
/// dependency, in each spelling Cargo takes: `pretty_assertions = "1"`, an
/// inline table, a dotted key (`pretty_assertions.package = "x"`) and a
/// table of its own (`[dev-dependencies.pretty_assertions]`), the
/// workspace's included.
pub(crate) fn pretty_dependency(contents: &str) -> PrettyDependency {
    let mut found = PrettyDependency::default();
    let mut table = None;
    for line in contents.lines() {
        let trimmed = line.trim();
        if trimmed.starts_with('[') {
            table = dependency_table(trimmed);
            if let Some((workspace, true)) = table {
                found.note(workspace, "version", "");
            }
            continue;
        }
        let Some((workspace, own)) = table else {
            continue;
        };
        let Some((key, value)) = trimmed.split_once('=') else {
            continue;
        };
        let key = key.trim().replace('"', "");
        if own {
            found.note(workspace, &key, value);
            continue;
        }
        match key.split_once('.') {
            Some((name, inner)) if crate_name(name.trim()) == PRETTY_ASSERTIONS => {
                found.note(workspace, inner.trim(), value);
            }
            None if crate_name(&key) == PRETTY_ASSERTIONS => found.note_value(workspace, value),
            _ => {}
        }
    }

    found
}
