//! The project's traits in the declaration index, and what a glob import of
//! a module may bring into scope: what may take a call on a trait mock
//! before the mocked trait does (see `crate::test_facts`).

use std::collections::BTreeSet;
use std::ops::Bound;

use super::Declarations;

/// Every trait the indexed files declare, and every module of theirs where
/// a macro stands where an item may, so may declare one.
#[derive(Debug, Default)]
pub(super) struct TraitIndex {
    /// The canonical path of every trait.
    traits: BTreeSet<String>,
    /// The modules a macro may declare a trait in.
    opaque: BTreeSet<String>,
}

/// What a glob import of one module may bring into scope, see
/// [`Declarations::glob_contents`].
#[derive(Debug, Default)]
pub struct GlobContents {
    /// Whether the index knows the module: some file declares, re-exports
    /// or glob-imports a name in it, and no macro stands where an item may
    /// in it.
    pub known: bool,
    /// Each trait declared directly in the module.
    pub traits: Vec<String>,
    /// Each path a `use` of the module binds a name to, `None` when two
    /// bind it apart, with whether only a private `use` binds it.
    pub exports: Vec<(Option<String>, bool)>,
    /// The module's own glob imports, each with whether it is private.
    pub globs: Vec<(String, bool)>,
}

impl Declarations {
    /// Record one file's traits and the modules a macro of it may declare
    /// one in (see [`crate::visit::trait_paths`]).
    pub fn add_traits(&mut self, traits: &BTreeSet<String>, opaque: &BTreeSet<String>) {
        self.traits.traits.extend(traits.iter().cloned());
        self.traits.opaque.extend(opaque.iter().cloned());
    }

    /// Whether some file declares a trait at `path`, remembering that
    /// `path` was asked about.
    pub fn is_trait(&self, path: &str) -> bool {
        self.lookups.borrow_mut().insert(path.to_owned());
        self.traits.traits.contains(path)
    }

    /// Whether some file declares or re-exports a name below `path`, so
    /// that `path` is a module or a type of the project, remembering
    /// `path::*` as asked about: the files of `path` itself are then read.
    pub fn declares_below(&self, path: &str) -> bool {
        let prefix = format!("{path}::");
        self.lookups.borrow_mut().insert(format!("{prefix}*"));
        let below = |key: &String| key.starts_with(&prefix);
        self.counts
            .range::<str, _>((Bound::Included(prefix.as_str()), Bound::Unbounded))
            .next()
            .is_some_and(|(key, _)| below(key))
            || self
                .exports
                .range::<str, _>((Bound::Included(prefix.as_str()), Bound::Unbounded))
                .next()
                .is_some_and(|(key, _)| below(key))
    }

    /// What a glob import of `module` may bring into scope, remembering
    /// `module::*` as asked about (see [`Declarations::declares_below`]).
    pub fn glob_contents(&self, module: &str) -> GlobContents {
        let globs = self.globs_of(module).to_vec();
        let known = (self.declares_below(module) || !globs.is_empty())
            && !self.traits.opaque.contains(module);
        let prefix = format!("{module}::");
        let direct = |key: &str| {
            key.strip_prefix(&prefix)
                .is_some_and(|rest| !rest.contains("::"))
        };
        GlobContents {
            known,
            traits: self
                .traits
                .traits
                .iter()
                .filter(|path| direct(path))
                .cloned()
                .collect(),
            exports: self
                .exports
                .iter()
                .filter(|(name, _)| direct(name))
                .map(|(name, target)| (target.clone(), self.is_private_export(name)))
                .collect(),
            globs,
        }
    }
}
