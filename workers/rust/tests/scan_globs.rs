//! Scanning tests driving the worker against real files on disk:
//! glob imports and the names they bring in.

mod common;

use common::*;

#[test]
fn a_field_typed_through_a_glob_import_resolves_through_its_re_export() {
    // `holder.rs` names `Store` only through `use crate::prelude::*;`, and
    // the prelude re-exports it from `model`. A call through the field, from
    // the declaring file and from another file's `impl`, reaches the method
    // `model` declares. `Vec` comes from no glob source and types nothing,
    // so `push` on it is no call at all.
    let files = [
        (
            "src/lib.rs",
            "pub mod holder;\npub mod model;\npub mod prelude;\npub mod user;\n",
        ),
        (
            "src/model.rs",
            "pub struct Store;\nimpl Store {\n    pub fn put(&self) {}\n}\n",
        ),
        ("src/prelude.rs", "pub use crate::model::Store;\n"),
        (
            "src/holder.rs",
            "use crate::prelude::*;\npub struct Holder {\n    pub store: Store,\n    pub items: Vec<u8>,\n}\nimpl Holder {\n    pub fn local(&mut self) {\n        self.store.put();\n        self.items.push(1);\n    }\n}\n",
        ),
        (
            "src/user.rs",
            "use crate::holder::Holder;\nimpl Holder {\n    pub fn save(&mut self) {\n        self.store.put();\n        self.items.push(2);\n    }\n}\n",
        ),
    ];
    let contributions = scan_fixture("glob-field-re-export", &files);

    for caller in [
        "rust:method:crate::holder::Holder::local",
        "rust:method:crate::holder::Holder::save",
    ] {
        assert_eq!(
            vec![("rust:method:crate::model::Store::put".to_owned(), true)],
            method_calls(&contributions, caller),
            "{caller}"
        );
    }
    // The answer rests on what the prelude provides, so the file calling
    // through the field read the prelude.
    let walking = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/user.rs")
        .unwrap();
    assert!(
        walking["reads"].get("src/prelude.rs").is_some(),
        "{}",
        walking["reads"]
    );
}

#[test]
fn a_field_typed_through_a_super_glob_resolves_in_another_file() {
    // `child.rs` reaches `Engine` through `use super::*;`, which brings in
    // what its parent module declares.
    let files = [
        ("src/lib.rs", "pub mod parent;\npub mod user;\n"),
        (
            "src/parent/mod.rs",
            "pub mod child;\npub struct Engine;\nimpl Engine {\n    pub fn run(&self) {}\n}\n",
        ),
        (
            "src/parent/child.rs",
            "use super::*;\npub struct Car {\n    pub engine: Box<Engine>,\n}\n",
        ),
        (
            "src/user.rs",
            "use crate::parent::child::Car;\nimpl Car {\n    pub fn drive(&self) {\n        self.engine.run();\n    }\n}\n",
        ),
    ];
    let contributions = scan_fixture("glob-field-super", &files);

    assert_eq!(
        vec![("rust:method:crate::parent::Engine::run".to_owned(), true)],
        method_calls(
            &contributions,
            "rust:method:crate::parent::child::Car::drive"
        )
    );
}

#[test]
fn a_name_two_glob_sources_provide_stays_unresolved() {
    // `a` and `b` both declare `Store`: which one `use crate::a::*;` and
    // `use crate::b::*;` bring in is ambiguous, so neither is guessed.
    let files = [
        ("src/lib.rs", "pub mod a;\npub mod b;\npub mod holder;\npub mod user;\n"),
        (
            "src/a.rs",
            "pub struct Store;\nimpl Store {\n    pub fn put(&self) {}\n}\n",
        ),
        (
            "src/b.rs",
            "pub struct Store;\nimpl Store {\n    pub fn put(&self) {}\n}\n",
        ),
        (
            "src/holder.rs",
            "use crate::a::*;\nuse crate::b::*;\npub struct Holder {\n    pub store: Store,\n}\nimpl Holder {\n    pub fn local(&self) {\n        self.store.put();\n        let other: Store = make();\n        other.put();\n    }\n}\nfn make<T>() -> T {\n    todo!()\n}\n",
        ),
        (
            "src/user.rs",
            "use crate::holder::Holder;\nimpl Holder {\n    pub fn save(&self) {\n        self.store.put();\n    }\n}\n",
        ),
    ];
    let contributions = scan_fixture("glob-field-ambiguous", &files);

    for caller in [
        "rust:method:crate::holder::Holder::local",
        "rust:method:crate::holder::Holder::save",
    ] {
        assert!(
            method_calls(&contributions, caller)
                .iter()
                .all(|(target, _)| !target.ends_with("Store::put")),
            "{caller}: {:?}",
            method_calls(&contributions, caller)
        );
    }
}

#[test]
fn a_generic_type_parameter_is_not_a_glob_imported_type() {
    // `Gen<Thing>` declares its own `Thing`, which shadows the `Thing`
    // `use crate::a::*;` brings in, for its fields and in a generic
    // function's signature alike.
    let files = [
        ("src/lib.rs", "pub mod a;\npub mod imp;\npub mod s;\n"),
        (
            "src/a.rs",
            "pub struct Thing;\nimpl Thing {\n    pub fn go(&self) {}\n}\n",
        ),
        (
            "src/s.rs",
            "use crate::a::*;\npub struct Gen<Thing> {\n    pub x: Thing,\n}\nimpl<Thing> Gen<Thing> {\n    pub fn local(&self, other: Thing) {\n        self.x.go();\n        other.go();\n    }\n}\npub fn generic<Thing>(t: Thing) {\n    t.go();\n}\n",
        ),
        (
            "src/imp.rs",
            "use crate::s::Gen;\nimpl<T> Gen<T> {\n    pub fn f(&self) {\n        self.x.go();\n    }\n}\n",
        ),
    ];
    let contributions = scan_fixture("glob-generic-parameter", &files);

    for caller in [
        "rust:method:crate::s::Gen::local",
        "rust:method:crate::s::Gen::f",
        "rust:function:crate::s::generic",
    ] {
        assert_eq!(
            Vec::<(String, bool)>::new(),
            method_calls(&contributions, caller),
            "{caller}"
        );
    }
}

#[test]
fn a_type_the_module_declares_beats_a_glob_for_a_path_below_it() {
    // `d` declares its own `Widget`, so `Widget::new()` there never names
    // the `Widget` `use crate::a::*;` brings in.
    let files = [
        ("src/lib.rs", "pub mod a;\npub mod d;\n"),
        (
            "src/a.rs",
            "pub struct Widget;\nimpl Widget {\n    pub fn new() -> Self {\n        Widget\n    }\n}\n",
        ),
        (
            "src/d.rs",
            "use crate::a::*;\npub struct Widget;\npub fn bare() {\n    Widget::new();\n}\n",
        ),
    ];
    let contributions = scan_fixture("glob-own-type-wins", &files);

    assert_eq!(
        Vec::<(String, bool)>::new(),
        method_calls(&contributions, "rust:function:crate::d::bare")
    );
}

#[test]
fn a_glob_of_an_enum_does_not_import_its_methods() {
    // `use crate::a::Kind::*;` brings in the variants of `Kind`, never its
    // associated functions, so a bare `helper()` does not name one.
    let files = [
        ("src/lib.rs", "pub mod a;\npub mod e;\n"),
        (
            "src/a.rs",
            "pub enum Kind {\n    Big,\n}\nimpl Kind {\n    pub fn helper() {}\n}\n",
        ),
        (
            "src/e.rs",
            "use crate::a::Kind::*;\npub fn g() {\n    helper();\n}\n",
        ),
    ];
    let contributions = scan_fixture("glob-enum-methods", &files);

    assert_eq!(
        Vec::<(String, bool)>::new(),
        method_calls(&contributions, "rust:function:crate::e::g")
    );
}

#[test]
fn an_explicit_import_or_own_declaration_beats_a_glob_for_a_field() {
    // `c` imports `b::Widget` by name and `d` declares its own `Widget`, so
    // a field of either, looked up from another file, never takes the
    // `Widget` `use crate::a::*;` brings in.
    let declares = "pub struct Widget;\nimpl Widget {\n    pub fn run(&self) {}\n}\n";
    let files = [
        ("src/lib.rs", "pub mod a;\npub mod b;\npub mod c;\npub mod d;\npub mod user;\n"),
        ("src/a.rs", declares),
        ("src/b.rs", declares),
        (
            "src/c.rs",
            "use crate::a::*;\nuse crate::b::Widget;\npub struct Holder {\n    pub w: Widget,\n}\n",
        ),
        (
            "src/d.rs",
            "use crate::a::*;\npub struct Widget;\nimpl Widget {\n    pub fn run(&self) {}\n}\npub struct Holder {\n    pub w: Widget,\n}\n",
        ),
        (
            "src/user.rs",
            "impl crate::c::Holder {\n    pub fn f(&self) {\n        self.w.run();\n    }\n}\nimpl crate::d::Holder {\n    pub fn f(&self) {\n        self.w.run();\n    }\n}\n",
        ),
    ];
    let contributions = scan_fixture("glob-field-explicit-wins", &files);

    assert_eq!(
        vec![("rust:method:crate::b::Widget::run".to_owned(), true)],
        method_calls(&contributions, "rust:method:crate::c::Holder::f")
    );
    assert_eq!(
        vec![("rust:method:crate::d::Widget::run".to_owned(), true)],
        method_calls(&contributions, "rust:method:crate::d::Holder::f")
    );
}

#[test]
fn a_path_through_a_glob_re_export_names_the_item_where_it_is_declared() {
    // `pub use types::*;` and `pub use integration::*;` in `v2/mod.rs` make
    // `crate::v2::declared()`, `super::digest()`, `use super::Profile;` and
    // `v2::Description` name items the children declare; a crate-path alias
    // into another crate's glob facade (`host::verify()`) does too.
    let v2 = "mod integration;\npub mod ledger;\nmod types;\npub use integration::*;\npub use types::*;\n";
    let types = "pub struct Profile;\npub fn digest() -> u8 { 0 }\n";
    let integration = r#"
use super::Profile;
pub struct Description;
pub fn declared() -> Profile { Profile }
"#;
    let ledger = r#"
pub fn replay() -> u8 {
    let _ = crate::v2::declared();
    super::digest()
}
"#;
    let root = "pub mod v2;\npub fn describe(_: &v2::Description) {}\npub mod helper;\npub fn unit() { let _ = v2::Description; }\n";
    let helper = "use other::scm as host;\npub fn check() { host::verify(); }\n";
    let contributions = scan_fixture_with(
        "glob-re-exports",
        &[
            (
                "Cargo.toml",
                "[package]\nname = \"demo\"\n[workspace]\nmembers = [\"other\"]\n",
            ),
            ("src/lib.rs", root),
            ("src/helper.rs", helper),
            ("src/v2/mod.rs", v2),
            ("src/v2/types.rs", types),
            ("src/v2/integration.rs", integration),
            ("src/v2/ledger.rs", ledger),
            ("other/Cargo.toml", "[package]\nname = \"other\"\n"),
            ("other/src/lib.rs", "pub mod scm;\n"),
            ("other/src/scm/mod.rs", "mod native;\npub use native::*;\n"),
            ("other/src/scm/native.rs", "pub fn verify() {}\n"),
        ],
        &serde_json::json!({"config_files": ["Cargo.toml", "other/Cargo.toml"]}),
    );
    let mut missing = Vec::new();
    for (kind, from, to) in [
        (
            "calls",
            "rust:function:crate::v2::ledger::replay",
            "rust:function:crate::v2::integration::declared",
        ),
        (
            "calls",
            "rust:function:crate::v2::ledger::replay",
            "rust:function:crate::v2::types::digest",
        ),
        (
            "references",
            "rust:function:crate::v2::integration::declared",
            "rust:class:crate::v2::types::Profile",
        ),
        (
            "references",
            "rust:function:crate::describe",
            "rust:class:crate::v2::integration::Description",
        ),
        (
            "references",
            "rust:function:crate::unit",
            "rust:class:crate::v2::integration::Description",
        ),
        (
            "calls",
            "rust:function:crate::helper::check",
            "rust:function:other::scm::native::verify",
        ),
    ] {
        let targets = targets_of(&contributions, kind, from);
        if !targets.contains(&to.to_owned()) {
            missing.push(format!("{kind} {from} -> {to}: {targets:?}"));
        }
    }
    assert!(missing.is_empty(), "{missing:#?}");
}

#[test]
fn a_name_a_super_glob_brings_in_through_the_parents_glob_re_export_resolves() {
    // `use super::*;` in `recipe/validate.rs` brings in `Recipe`, which the
    // parent itself only has through `pub use types::*;`.
    let contributions = scan_fixture(
        "glob-through-glob",
        &[
            ("src/lib.rs", "pub mod recipe;\n"),
            ("src/recipe/mod.rs", "mod types;\npub(crate) mod validate;\npub use types::*;\n"),
            ("src/recipe/types.rs", "pub struct Recipe;\n"),
            (
                "src/recipe/validate.rs",
                "use super::*;\npub struct Validated { pub recipe: Recipe }\npub fn load() -> Recipe { let r: Recipe = Recipe; r }\n",
            ),
        ],
    );
    for from in [
        "rust:class:crate::recipe::validate::Validated",
        "rust:function:crate::recipe::validate::load",
    ] {
        let references = targets_of(&contributions, "references", from);
        assert!(
            references.contains(&"rust:class:crate::recipe::types::Recipe".to_owned()),
            "{from}: {references:?}"
        );
    }
}

#[test]
fn a_use_path_starting_from_a_name_a_super_glob_brings_in_resolves() {
    // `mcp.rs` does `use super::*;` and then `use process::run_duplex;`,
    // where `process` is the parent's own `use super::process;`.
    let contributions = scan_fixture(
        "use-through-super-glob",
        &[
            ("src/lib.rs", "pub mod runner;\n"),
            ("src/runner/mod.rs", "pub mod docker;\nmod process;\n"),
            ("src/runner/process.rs", "mod duplex;\npub(super) use duplex::run_duplex;\n"),
            ("src/runner/process/duplex.rs", "pub(crate) fn run_duplex() -> u8 { 0 }\n"),
            ("src/runner/docker.rs", "use super::process;\nmod mcp;\npub fn go() -> u8 { mcp::acquire() }\n"),
            (
                "src/runner/docker/mcp.rs",
                "use super::*;\nuse process::run_duplex;\npub fn acquire() -> u8 { run_duplex() }\n",
            ),
        ],
    );
    let calls = targets_of(
        &contributions,
        "calls",
        "rust:function:crate::runner::docker::mcp::acquire",
    );
    assert!(
        calls.contains(&"rust:function:crate::runner::process::duplex::run_duplex".to_owned()),
        "{calls:?}"
    );
}
