//! What else may answer a call on a fake (Decision 4, fix round 1): another
//! impl reached through a name that is not provably another type (a type
//! alias, a `use … as` rename), and another trait in scope whose method of
//! the member's name takes the call first (a blanket `impl<T> Take for T`
//! with `fn now(self)` wins the by-value probe step over `Clock::now(&self)`).
//!
//! The declaration index holds no trait's methods, so a trait the file does
//! not declare cannot be ruled out by its methods: any import from outside
//! the file, other than the faked trait itself and the standard crates,
//! spoils the pin, and so does any glob whose source is not one of the
//! file's own modules. The standard crates' blanket traits are ruled out by
//! the names of their methods instead ([`STD_BLANKET`]). The cost is recall
//! only.

use std::collections::BTreeSet;

use syn::visit::Visit;

use super::TestInput;
use crate::resolve::ident_name;

/// The methods the standard crates' blanket traits give every type (or
/// every type of a common bound): a member named so may lose the call.
const STD_BLANKET: [&str; 9] = [
    "into",
    "try_into",
    "from",
    "try_from",
    "to_owned",
    "to_string",
    "borrow",
    "borrow_mut",
    "type_id",
];

/// The item macros known to declare no trait: a `macro_rules!`
/// definition, `thread_local!` and mockall's `mock!`, whose impls are of
/// the traits it names.
const TRAITLESS_MACROS: [&str; 5] = [
    "macro_rules",
    "thread_local",
    "std::thread_local",
    "mock",
    "mockall::mock",
];

/// The standard crates, whose imports bring no project trait.
const STD_CRATES: [&str; 3] = ["std", "core", "alloc"];

/// The file's names that may make another impl or trait answer a fake's
/// call.
#[derive(Debug, Default)]
pub(super) struct Rivals {
    /// Every struct, enum and union the file declares, by name.
    types: BTreeSet<String>,
    /// Every name a `type` alias or a `use` binds in the file.
    bound: BTreeSet<String>,
    /// Every trait the file declares: its canonical path and its
    /// functions.
    traits: Vec<(String, BTreeSet<String>)>,
    /// Each import from outside the file and the standard crates, as the
    /// walk placed it.
    outside: BTreeSet<String>,
    /// Whether a glob from outside the file, an ambiguous import, or a
    /// `use` inside a body that leaves the standard crates may bring in any
    /// trait.
    opened: bool,
    /// Whether a `use` inside a body leaves the standard crates.
    body_use: bool,
    /// Whether a macro may declare a trait a test of the file sees (final
    /// re-review N1): one standing where an item may in a module that holds
    /// a test or that a glob of the file imports, or in a trait's body.
    item_macro: bool,
    /// The modules of the file where a macro stands where an item may.
    macro_modules: BTreeSet<String>,
}

impl Rivals {
    /// The rivals of the file `input` holds.
    pub(super) fn of(input: &TestInput<'_>) -> Self {
        let modules = file_modules(input);
        let mut seen = Seen {
            rivals: Self::default(),
            containers: vec![input.module.to_owned()],
            in_body: 0,
        };
        seen.visit_file(input.file);
        let mut rivals = seen.rivals;
        let scope = input.scope;
        // A macro's items are in scope in its own module, and wherever a
        // glob imports them; a named import of one is an import the index
        // cannot place.
        let seen_by_tests: BTreeSet<String> =
            super::cases::test_functions(&input.file.items, input.module)
                .into_iter()
                .map(|case| case.container)
                .chain(scope.globs.iter().map(|(_, source)| source.clone()))
                .collect();
        rivals.item_macro |= !rivals.macro_modules.is_disjoint(&seen_by_tests);
        for imported in scope.module_aliases.values() {
            match imported {
                None => rivals.opened = true,
                Some(full) => {
                    let ruled_out =
                        standard(full) || scope.declared.contains(full) || modules.contains(full);
                    if !ruled_out {
                        rivals.outside.insert(full.clone());
                    }
                }
            }
        }
        rivals.opened |= scope
            .globs
            .iter()
            .any(|(_, source)| !standard(source) && !modules.contains(source));
        rivals
    }

    /// Whether an impl whose self type is written `owner` may be one of the
    /// fake's: unless the file declares a type of that name and binds no
    /// alias or import under it, the name may stand for the fake.
    pub(super) fn may_be_the_fake(&self, owner: &str) -> bool {
        !self.types.contains(owner) || self.bound.contains(owner)
    }

    /// Whether a trait other than `target` may take a call of `member` on
    /// the fake.
    pub(super) fn rival(&self, target: &str, member: &str) -> bool {
        STD_BLANKET.contains(&member)
            || self.opened
            || self.outside.iter().any(|imported| imported != target)
            || self
                .traits
                .iter()
                .any(|(path, functions)| path != target && functions.contains(member))
    }

    /// Whether something the file itself shows may take a call of `member`
    /// on a mock of the traits `targets` (final review I1): a standard
    /// blanket method, another trait the file declares with a method of
    /// that name, a `use` inside a body that leaves the standard crates, or
    /// an item macro of the file that may declare a trait.
    /// What the file imports is [`super::trait_rivals`]'s.
    pub(super) fn local_trait_rival(&self, targets: &[String], member: &str) -> bool {
        STD_BLANKET.contains(&member)
            || self.body_use
            || self.item_macro
            || self
                .traits
                .iter()
                .any(|(path, functions)| !targets.contains(path) && functions.contains(member))
    }
}

/// Every module the file `input` holds: its own and its inline ones.
pub(super) fn file_modules(input: &TestInput<'_>) -> BTreeSet<String> {
    let mut modules = BTreeSet::new();
    collect_modules(input.module, &input.file.items, &mut modules);
    modules
}

/// Whether `path` is in a standard crate.
pub(super) fn standard(path: &str) -> bool {
    let head = path.split("::").next().unwrap_or(path);
    STD_CRATES.contains(&head)
}

/// Add the modules of `items`, placed in `module`, to `out`.
fn collect_modules(module: &str, items: &[syn::Item], out: &mut BTreeSet<String>) {
    out.insert(module.to_owned());
    for item in items {
        if let syn::Item::Mod(node) = item {
            if let Some((_, inner)) = &node.content {
                collect_modules(
                    &format!("{module}::{}", ident_name(&node.ident)),
                    inner,
                    out,
                );
            }
        }
    }
}

/// The visitor that reads the rivals off the file's syntax.
struct Seen {
    /// What it found.
    rivals: Rivals,
    /// The modules around the item being read.
    containers: Vec<String>,
    /// How many function bodies are around it.
    in_body: usize,
}

impl Visit<'_> for Seen {
    fn visit_item_mod(&mut self, node: &syn::ItemMod) {
        let container = self.containers.last().cloned().unwrap_or_default();
        self.containers
            .push(format!("{container}::{}", ident_name(&node.ident)));
        syn::visit::visit_item_mod(self, node);
        self.containers.pop();
    }

    fn visit_block(&mut self, node: &syn::Block) {
        self.in_body += 1;
        syn::visit::visit_block(self, node);
        self.in_body -= 1;
    }

    fn visit_item_struct(&mut self, node: &syn::ItemStruct) {
        self.rivals.types.insert(ident_name(&node.ident));
        syn::visit::visit_item_struct(self, node);
    }

    fn visit_item_enum(&mut self, node: &syn::ItemEnum) {
        self.rivals.types.insert(ident_name(&node.ident));
        syn::visit::visit_item_enum(self, node);
    }

    fn visit_item_union(&mut self, node: &syn::ItemUnion) {
        self.rivals.types.insert(ident_name(&node.ident));
        syn::visit::visit_item_union(self, node);
    }

    fn visit_item_type(&mut self, node: &syn::ItemType) {
        self.rivals.bound.insert(ident_name(&node.ident));
        syn::visit::visit_item_type(self, node);
    }

    fn visit_item_trait(&mut self, node: &syn::ItemTrait) {
        let container = self.containers.last().cloned().unwrap_or_default();
        let functions = node
            .items
            .iter()
            .filter_map(|item| match item {
                syn::TraitItem::Fn(function) => Some(ident_name(&function.sig.ident)),
                _ => None,
            })
            .collect();
        self.rivals.traits.push((
            format!("{container}::{}", ident_name(&node.ident)),
            functions,
        ));
        syn::visit::visit_item_trait(self, node);
    }

    fn visit_item_macro(&mut self, node: &syn::ItemMacro) {
        let path = node
            .mac
            .path
            .segments
            .iter()
            .map(|segment| ident_name(&segment.ident))
            .collect::<Vec<_>>()
            .join("::");
        if !TRAITLESS_MACROS.contains(&path.as_str()) {
            let container = self.containers.last().cloned().unwrap_or_default();
            self.rivals.macro_modules.insert(container);
        }
        syn::visit::visit_item_macro(self, node);
    }

    fn visit_trait_item_macro(&mut self, node: &syn::TraitItemMacro) {
        // A method of a trait of the file that a macro declares.
        self.rivals.item_macro = true;
        syn::visit::visit_trait_item_macro(self, node);
    }

    fn visit_item_use(&mut self, node: &syn::ItemUse) {
        // A body's imports are not in the walk's scope: any that leaves the
        // standard crates may bring in a trait.
        if self.in_body > 0 {
            // `::std::…` and `std::…` alike; `::dep::…` leaves them.
            let leaves_std = match &node.tree {
                syn::UseTree::Path(path) => !STD_CRATES.iter().any(|name| path.ident == name),
                _ => true,
            };
            self.rivals.opened |= leaves_std;
            self.rivals.body_use |= leaves_std;
        }
        syn::visit::visit_item_use(self, node);
    }

    fn visit_use_name(&mut self, node: &syn::UseName) {
        self.rivals.bound.insert(ident_name(&node.ident));
    }

    fn visit_use_rename(&mut self, node: &syn::UseRename) {
        self.rivals.bound.insert(ident_name(&node.rename));
    }
}
