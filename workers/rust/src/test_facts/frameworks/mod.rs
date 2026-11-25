//! The mocking crates besides mockall (Decisions 2 and 9), ported from
//! Momus's `mocks.ts`: faux, mry, mockers, mockiato, mocktopus, mock_derive
//! and galvanic. HTTP mocks (mockito, wiremock, httpmock) stand in for a
//! remote service, not a project symbol, and give no facts (Decision 5).
//!
//! Each crate's file says three things the function pass
//! ([`super::pass`]) asks about:
//!
//! - which constructs make a double ([`Made`]): a constructor, and for
//!   mocktopus and the static mocks a configuration on a path;
//! - what a method call on a handle is ([`Role`]): a configured member, a
//!   call, or neither;
//! - which verbs of a configuration chain pin a value, and which assert how
//!   often the member is called ([`Verbs`]).
//!
//! Only what the file's own items generate is a generator here
//! ([`Generator`]); a constructor whose generator the file does not hold
//! makes a double only when the crate's API alone tells it apart
//! (`Foo::faux()`, `create_mock_for`, `mry::new!`, `new_mock!`,
//! `f.mock_safe(…)`), and never a target. Every name is resolved through
//! the walk's imports and globs ([`FrameworkContext`]), so a module's own
//! item or a body's `use` beats a glob.

use std::collections::{BTreeMap, BTreeSet};

use crate::facts::Facts;
use crate::resolve::{ident_name, rebase};
use crate::visit::FileScope;

pub(super) mod faux;
pub(super) mod galvanic;
pub(super) mod mock_derive;
pub(super) mod mockers;
pub(super) mod mockiato;
pub(super) mod mocktopus;
pub(super) mod mry;

/// How many import and glob hops a name is followed through.
const MAX_HOPS: usize = 8;

/// Which framework a double is made with.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(super) enum Framework {
    /// mockall (Decision 3).
    Mockall,
    /// faux.
    Faux,
    /// mry.
    Mry,
    /// mockers.
    Mockers,
    /// mockiato.
    Mockiato,
    /// mocktopus.
    Mocktopus,
    /// mock_derive.
    MockDerive,
    /// galvanic-mock.
    Galvanic,
    /// A hand-written fake (Decision 4).
    HandWritten,
}

impl Framework {
    /// The wire name (Decision 2).
    pub(super) fn name(self) -> &'static str {
        match self {
            Self::Mockall => "mockall",
            Self::Faux => "faux",
            Self::Mry => "mry",
            Self::Mockers => "mockers",
            Self::Mockiato => "mockiato",
            Self::Mocktopus => "mocktopus",
            Self::MockDerive => "mock_derive",
            Self::Galvanic => "galvanic",
            Self::HandWritten => "hand-written",
        }
    }

    /// The verbs of its configuration chains.
    pub(super) fn verbs(self) -> &'static Verbs {
        match self {
            Self::Faux => &faux::VERBS,
            Self::Mry => &mry::VERBS,
            Self::Mockers => &mockers::VERBS,
            Self::Mockiato => &mockiato::VERBS,
            Self::MockDerive => &mock_derive::VERBS,
            Self::Mockall | Self::Mocktopus | Self::Galvanic | Self::HandWritten => &NO_VERBS,
        }
    }

    /// What the method `method` called on a handle is.
    pub(super) fn role(self, method: &str) -> Role {
        match self {
            Self::Mry => mry::role(method),
            Self::Mockiato => mockiato::role(method),
            Self::MockDerive => mock_derive::role(method),
            _ => Role::Call,
        }
    }
}

/// One verb that sets what a member returns.
#[derive(Debug)]
pub(super) struct Verb {
    /// Its name.
    pub(super) name: &'static str,
    /// Whether it pins a value (Decision 2's verbs per crate): every other
    /// value verb still answers the call, so it leaves the value unknown.
    pub(super) pins: bool,
    /// Whether it answers one call only.
    pub(super) once: bool,
}

/// The verbs of one framework's configuration chains.
#[derive(Debug)]
pub(super) struct Verbs {
    /// The verbs that set what the member returns.
    pub(super) values: &'static [Verb],
    /// The verbs that assert how often the member is called: an assertion
    /// `times` on the double.
    pub(super) times: &'static [&'static str],
    /// Whether a pinned literal may be the value a call on the handle
    /// returns (Decision 9). A framework whose configuration goes through a
    /// second value (mockers' scenario and handle, mock_derive's method
    /// builder) or a macro (galvanic's `given!`) never tells it.
    pub(super) answers: bool,
}

/// The verbs of a framework with no configuration chain on a handle.
const NO_VERBS: Verbs = Verbs {
    values: &[],
    times: &[],
    answers: false,
};

/// What a method called on a handle is.
#[derive(Debug, PartialEq, Eq)]
pub(super) enum Role {
    /// It configures the member (name, api).
    Member(String, &'static str),
    /// It hands a configuration to the double (mock_derive's `set_m`),
    /// neither a call nor a member.
    Install,
    /// A call on the double.
    Call,
}

/// One double a construct makes.
#[derive(Debug)]
pub(super) struct Made {
    /// Its framework.
    pub(super) framework: Framework,
    /// The mock type, or the mocked path.
    pub(super) mock: String,
    /// The construct's pattern (Decision 2).
    pub(super) pattern: &'static str,
    /// What it stands in for, when the file declares it.
    pub(super) target: Option<(&'static str, String)>,
    /// The wire target kind: `class`, or `global` for mocktopus.
    pub(super) target_kind: &'static str,
    /// The member the construct itself configures (name, api), for the
    /// static mocks, whose configuration names no handle.
    pub(super) member: Option<(String, &'static str)>,
    /// The path a call of the mocked function is written as, for a mock of
    /// a function: each such call is an invocation.
    pub(super) invoked_by: Option<String>,
}

impl Made {
    /// A double of `framework` made by a constructor, standing in for
    /// `target`.
    pub(super) fn instance(
        framework: Framework,
        mock: String,
        pattern: &'static str,
        target: Option<(&'static str, String)>,
    ) -> Self {
        Self {
            framework,
            mock,
            pattern,
            target,
            target_kind: "class",
            member: None,
            invoked_by: None,
        }
    }
}

/// What one of the file's own items generates for a framework.
#[derive(Debug)]
pub(super) struct Generator {
    /// The framework.
    pub(super) framework: Framework,
    /// What a double of it stands in for.
    pub(super) target: Option<(&'static str, String)>,
    /// The associated functions the mocked item declares, by name (the
    /// mocked `new` of a trait, an extern block's functions).
    pub(super) functions: BTreeSet<String>,
    /// What kind of name it is.
    pub(super) kind: Generated,
}

/// What kind of name a generator gives.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(super) enum Generated {
    /// A mock type a constructor makes an instance of (`GreeterMock`).
    Mock,
    /// A type whose every value may be mocked (a faux or mry struct).
    Mockable,
    /// A type whose associated functions are mocked statically (mry's
    /// `Cat::mock_meow`, mock_derive's `ExternCMocks`).
    Statics,
    /// A free function mocked statically (mry's `mock_meow`).
    Function,
}

/// What the framework passes read one file through (the file's imports,
/// its generated names, and the crate each framework path names).
pub(super) struct FrameworkContext<'a> {
    /// The walked file's imports and globs.
    pub(super) scope: &'a FileScope,
    /// The modules the file's items are in: its own and its inline ones.
    pub(super) modules: BTreeSet<String>,
    /// Every trait the file declares, by canonical path.
    pub(super) traits: BTreeSet<String>,
    /// What the file's items generate, by the canonical path of the name.
    pub(super) generated: BTreeMap<String, Generator>,
}

impl<'a> FrameworkContext<'a> {
    /// The context of the file whose items, placed in `module`, the walk
    /// left `scope` and `facts` of.
    pub(super) fn of(
        module: &str,
        items: &[syn::Item],
        scope: &'a FileScope,
        facts: &Facts,
    ) -> Self {
        let traits = facts
            .nodes()
            .iter()
            .filter(|node| node.kind == "interface")
            .map(|node| node.canonical_name.clone())
            .collect();
        let mut context = Self {
            scope,
            modules: BTreeSet::new(),
            traits,
            generated: BTreeMap::new(),
        };
        let mut generated = BTreeMap::new();
        context.collect(module, items, &mut generated);
        context.generated = generated;
        context
    }

    /// Record the modules and generators of `items`, in `container`.
    fn collect(
        &mut self,
        container: &str,
        items: &[syn::Item],
        out: &mut BTreeMap<String, Generator>,
    ) {
        self.modules.insert(container.to_owned());
        for item in items {
            if let syn::Item::Mod(module) = item {
                if let Some((_, inner)) = &module.content {
                    let nested = format!("{container}::{}", ident_name(&module.ident));
                    self.collect(&nested, inner, out);
                }
                continue;
            }
            faux::generators(self, container, item, out);
            mry::generators(self, container, item, out);
            mockers::generators(self, container, item, out);
            mockiato::generators(self, container, item, out);
            mock_derive::generators(self, container, item, out);
        }
    }

    /// Whether `attrs` apply the attribute `wanted` (`faux::create`),
    /// directly or as the last argument of a `cfg_attr`, written in
    /// `container`.
    pub(super) fn has_attribute(
        &self,
        container: &str,
        attrs: &[syn::Attribute],
        wanted: &str,
    ) -> bool {
        attrs.iter().any(|attr| {
            if attr.path().is_ident("cfg_attr") {
                let Ok(arguments) = attr.parse_args_with(
                    syn::punctuated::Punctuated::<syn::Meta, syn::Token![,]>::parse_terminated,
                ) else {
                    return false;
                };
                // The first argument is the predicate, never the attribute.
                return arguments.iter().skip(1).last().is_some_and(|last| {
                    self.full_path(container, last.path()).as_deref() == Some(wanted)
                });
            }
            self.full_path(container, attr.path()).as_deref() == Some(wanted)
        })
    }

    /// The path `path`, written in `container`, spelled out through the
    /// file's imports: `faux::when` for `when` under `use faux::when;`, a
    /// canonical path for a name the file declares. `None` when a hop is
    /// ambiguous or bound twice.
    pub(super) fn full_path(&self, container: &str, path: &syn::Path) -> Option<String> {
        let names: Vec<String> = path
            .segments
            .iter()
            .map(|segment| ident_name(&segment.ident))
            .collect();
        let (head, rest) = names.split_first()?;
        if path.leading_colon.is_some() {
            return Some(names.join("::"));
        }
        let resolved = match head.as_str() {
            "self" | "super" => return rebase(container, &names.join("::")),
            "crate" => self.scope.crate_module.clone(),
            _ => self.head(container, head, 0, &mut BTreeSet::new())?,
        };
        Some(
            std::iter::once(resolved)
                .chain(rest.iter().cloned())
                .collect::<Vec<_>>()
                .join("::"),
        )
    }

    /// What `name` names in `module`: an item the file declares there, an
    /// import, what one glob brings in, or else itself (a crate name).
    fn head(
        &self,
        module: &str,
        name: &str,
        hops: usize,
        seen: &mut BTreeSet<String>,
    ) -> Option<String> {
        let key = format!("{module}::{name}");
        if hops > MAX_HOPS || !seen.insert(key.clone()) {
            return None;
        }
        if self.scope.declared.contains(&key) {
            return Some(key);
        }
        if let Some(imported) = self
            .scope
            .module_aliases
            .get(&(module.to_owned(), name.to_owned()))
        {
            return imported.clone();
        }
        let found: BTreeSet<String> = self
            .scope
            .globs
            .iter()
            .filter(|(importer, _)| importer == module)
            .filter_map(|(_, source)| {
                if self.modules.contains(source) {
                    self.head(source, name, hops + 1, seen)
                } else {
                    Some(format!("{source}::{name}"))
                }
            })
            .collect();
        match found.len() {
            0 if hops == 0 => Some(name.to_owned()),
            1 => found.into_iter().next(),
            _ => None,
        }
    }

    /// The generator the path `path`, written in `container`, names.
    pub(super) fn generator(
        &self,
        container: &str,
        path: &syn::Path,
    ) -> Option<(String, &Generator)> {
        let full = self.generated_path(container, path)?;
        self.generated
            .get_key_value(&full)
            .map(|(key, generator)| (key.clone(), generator))
    }

    /// The canonical path `path`, written in `container`, names when that
    /// is a name a generator gives: through the module's imports and its
    /// globs, a name the module declares itself winning.
    fn generated_path(&self, container: &str, path: &syn::Path) -> Option<String> {
        if path.leading_colon.is_some() {
            return None;
        }
        if let [only] = path.segments.iter().collect::<Vec<_>>().as_slice() {
            return self.in_module(container, &ident_name(&only.ident), 0, &mut BTreeSet::new());
        }
        let full = self.full_path(container, path)?;
        let (module, last) = full.rsplit_once("::")?;
        self.in_module(module, last, 0, &mut BTreeSet::new())
    }

    /// The generated name `name` names in `module`, `hops` imports deep.
    fn in_module(
        &self,
        module: &str,
        name: &str,
        hops: usize,
        seen: &mut BTreeSet<String>,
    ) -> Option<String> {
        let key = format!("{module}::{name}");
        if hops > MAX_HOPS || !seen.insert(key.clone()) {
            return None;
        }
        if self.generated.contains_key(&key) {
            return Some(key);
        }
        if self.scope.declared.contains(&key) {
            return None;
        }
        if let Some(imported) = self
            .scope
            .module_aliases
            .get(&(module.to_owned(), name.to_owned()))
        {
            let (parent, last) = imported.as_deref()?.rsplit_once("::")?;
            return self.in_module(parent, last, hops + 1, seen);
        }
        self.scope
            .globs
            .iter()
            .filter(|(importer, _)| importer == module)
            .find_map(|(_, source)| self.in_module(source, name, hops + 1, seen))
    }

    /// The trait the file declares that `path`, written in `container`,
    /// names, as a target.
    pub(super) fn declared_trait(
        &self,
        container: &str,
        path: &syn::Path,
    ) -> Option<(&'static str, String)> {
        let full = self.full_path(container, path)?;
        self.traits.contains(&full).then_some(("interface", full))
    }
}

/// The written path of `path`'s segments, joined by `::`.
pub(super) fn written(path: &syn::Path) -> String {
    path.segments
        .iter()
        .map(|segment| ident_name(&segment.ident))
        .collect::<Vec<_>>()
        .join("::")
}
