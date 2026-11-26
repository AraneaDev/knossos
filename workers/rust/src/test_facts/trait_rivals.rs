//! What else may take a call on a mock (final review I1): a call
//! `mock.m()` is the stubbed `m(&self)`'s only when no other trait in scope
//! has a method `m` that the probe reaches first. A blanket
//! `impl<X> Limits for X` with `fn m(self)` takes the call by value before
//! any `m(&self)` is tried, a trait's or an inherent one alike, so the
//! assertion checks that method, not the stub.
//!
//! What the file itself shows is [`Rivals::local_trait_rival`]'s. Beyond it,
//! any trait the file brings in from elsewhere may be one: a name it
//! imports that the declaration index holds as a trait other than the
//! mocked one, a glob of a module that declares or re-exports one, and any
//! import from outside the standard crates the index cannot place. The
//! index holds no trait's methods, so any such trait spoils the value
//! whatever its methods are called. Each lookup is a read, so a change to
//! an imported file judges the value again. The cost is recall only.

use std::cell::OnceCell;
use std::collections::BTreeSet;

use super::input::TestInput;
use super::rivals::{file_modules, standard, Rivals};
use crate::visit::Declarations;

/// The items of the mock frameworks a test imports that are no trait, so
/// take no call on a mock: macros, functions and types. mockall's
/// predicate module is [`PREDICATES`].
const FRAMEWORK_ITEMS: [&str; 13] = [
    "mockall::automock",
    "mockall::mock",
    "mockall::concretize",
    "mockall::Sequence",
    "mockiato::mockable",
    "mry::mry",
    "mry::Any",
    "mry::new",
    "mry::m",
    "faux::create",
    "faux::methods",
    "faux::when",
    "async_trait::async_trait",
];

/// The module of mockall's predicate functions, which holds no trait.
const PREDICATES: &str = "mockall::predicate";

/// How many re-exports and nested globs are followed before the answer is
/// unknown.
const HOPS: usize = 4;

/// What may take a call on the file's mocks, read once, when first
/// asked.
pub(super) struct TraitRivals<'i> {
    /// The file.
    input: &'i TestInput<'i>,
    /// What the file itself shows.
    local: OnceCell<Rivals>,
    /// What it brings in from elsewhere.
    imported: OnceCell<Imported>,
}

impl<'i> TraitRivals<'i> {
    /// The rivals of the file `input` holds, none read yet.
    pub(super) fn new(input: &'i TestInput<'i>) -> Self {
        Self {
            input,
            local: OnceCell::new(),
            imported: OnceCell::new(),
        }
    }

    /// Whether a trait other than `targets`, the traits the mock
    /// implements (none for a mock of inherent methods), may take a call of
    /// `member` on it. A trait the
    /// generating file could not place may be any of them.
    pub(super) fn rival(&self, targets: &[Option<String>], member: &str) -> bool {
        let Some(targets) = targets.iter().cloned().collect::<Option<Vec<String>>>() else {
            return true;
        };
        self.local
            .get_or_init(|| Rivals::of(self.input))
            .local_trait_rival(&targets, member)
            || self
                .imported
                .get_or_init(|| Imported::of(self.input))
                .rival(&targets)
    }
}

/// The traits the file's imports and globs bring in from elsewhere.
#[derive(Debug, Default)]
struct Imported {
    /// Each one the index places.
    traits: BTreeSet<String>,
    /// Whether one the index cannot place may be a trait.
    unknown: bool,
}

impl Imported {
    /// What the imports and globs of the file `input` bring in.
    fn of(input: &TestInput<'_>) -> Self {
        let own = file_modules(input);
        let scope = input.scope;
        let declarations = input.declarations;
        let mut found = Self::default();
        for imported in scope.module_aliases.values() {
            let Some(full) = imported else {
                found.unknown = true;
                continue;
            };
            let ruled_out = standard(full)
                || scope.declared.contains(full)
                || own.contains(full)
                || framework_item(full);
            if !ruled_out {
                found.classify(declarations, full, HOPS);
            }
        }
        for (importer, source) in &scope.globs {
            if !(standard(source) || own.contains(source) || predicates(source)) {
                found.glob(declarations, importer, source, HOPS);
            }
        }
        found
    }

    /// Whether a trait other than `targets` may be in scope.
    fn rival(&self, targets: &[String]) -> bool {
        self.unknown || self.traits.iter().any(|path| !targets.contains(path))
    }

    /// Note what the imported path `path` is: a trait, an item that is
    /// none, a module or type of the project, a re-export followed, or
    /// unknown.
    fn classify(&mut self, declarations: &Declarations, path: &str, hops: usize) {
        if declarations.is_trait(path) {
            self.traits.insert(path.to_owned());
            return;
        }
        if declarations.get(path).is_some()
            || declarations.generated_mock(path).is_some()
            || declarations.alias_of(path).is_some()
            || declarations.declares_below(path)
        {
            return;
        }
        match declarations.exported(path) {
            Some(next) if hops > 0 && next != path => self.classify(declarations, &next, hops - 1),
            _ => self.unknown = true,
        }
    }

    /// Note what a glob of `module`, written in `importer`, brings in.
    fn glob(&mut self, declarations: &Declarations, importer: &str, module: &str, hops: usize) {
        let contents = declarations.glob_contents(module);
        if !contents.known {
            self.unknown = true;
            return;
        }
        self.traits.extend(contents.traits);
        // A private `use` or glob of the module is seen only below it.
        let inside = importer == module || importer.starts_with(&format!("{module}::"));
        for (target, private) in contents.exports {
            if private && !inside {
                continue;
            }
            match target {
                None => self.unknown = true,
                Some(target) if standard(&target) || framework_item(&target) => {}
                Some(target) => self.classify(declarations, &target, hops),
            }
        }
        for (source, private) in contents.globs {
            if (private && !inside) || standard(&source) || predicates(&source) {
                continue;
            }
            if hops == 0 {
                self.unknown = true;
            } else {
                self.glob(declarations, importer, &source, hops - 1);
            }
        }
    }
}

/// Whether `path` is a mock framework's item that is no trait.
fn framework_item(path: &str) -> bool {
    FRAMEWORK_ITEMS.contains(&path) || predicates(path)
}

/// Whether `path` is mockall's predicate module or in it.
fn predicates(path: &str) -> bool {
    path == PREDICATES
        || path
            .strip_prefix(PREDICATES)
            .is_some_and(|rest| rest.starts_with("::"))
}
