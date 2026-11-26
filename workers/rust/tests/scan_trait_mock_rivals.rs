//! Scanning tests of what else may take a call on a trait mock (final
//! review I1): a blanket `impl<T: UserRepo> Limits for T` whose
//! `fn limit(self)` takes `repo.limit()` by value before
//! `UserRepo::limit(&self)` is tried. The trait may be brought in by a `use`
//! in the body, a module-level import, a glob or the file itself; any of
//! them leaves the stubbed value unknown, and a test that brings in only the
//! mocked trait, its mock, structs and functions keeps it.

mod common;

use common::*;
use serde_json::{json, Value};

/// The mocked trait, in `src/repo.rs`.
const REPO: &str = "#[mockall::automock]\npub trait UserRepo {\n    fn limit(&self) -> u32;\n    fn count(&self) -> u32;\n}\n";

/// The reviewer's blanket extension, in `src/ext.rs`.
const LIMITS: &str = "use crate::repo::UserRepo;\n\npub trait Limits: UserRepo + Sized {\n    \
    fn limit(self) -> u32 {\n        UserRepo::limit(&self).min(5)\n    }\n}\n\n\
    impl<T: UserRepo> Limits for T {}\n";

/// A `src/ext.rs` that declares no trait.
const PLAIN: &str = "pub struct Helper;\n\npub fn helper() -> u32 {\n    1\n}\n";

/// What one scan of the probe crate gives.
struct Probe {
    /// The operand `repo.limit()` gives the case's assertion.
    operand: Value,
    /// The files `src/service.rs`'s facts were read from.
    reads: Vec<String>,
}

/// Scan the probe crate as `name`: `src/ext.rs` is `ext`, and the test
/// module of `src/service.rs` holds `uses` at its top, `items` after them,
/// and `body` at the top of its case.
fn probe(name: &str, ext: &str, uses: &str, items: &str, body: &str) -> Probe {
    let service = format!(
        "#[cfg(test)]\nmod tests {{\n    use crate::repo::{{MockUserRepo, UserRepo}};\n{uses}\n{items}\n    \
         #[test]\n    fn t() {{\n{body}\n        let mut repo = MockUserRepo::new();\n        \
         repo.expect_limit().return_const(5u32);\n        assert_eq!(repo.limit(), 5);\n    }}\n}}\n"
    );
    let contributions = scan_fixture_with(
        name,
        &[
            ("Cargo.toml", "[package]\nname = \"probe\"\n"),
            (
                "src/lib.rs",
                "pub mod ext;\npub mod repo;\npub mod service;\n",
            ),
            ("src/repo.rs", REPO),
            ("src/ext.rs", ext),
            ("src/service.rs", &service),
        ],
        &json!({ "config_files": ["Cargo.toml"] }),
    );
    let cases = nodes_of(&contributions, "test_case");
    let case = cases.values().next().expect("one case");
    let contribution = contributions
        .iter()
        .find(|contribution| contribution["owner_key"] == "knossos.rust:file:src/service.rs")
        .expect("the service contribution");
    Probe {
        operand: case["attributes"]["assertions"][0]["operands"][0].clone(),
        reads: contribution["reads"]
            .as_object()
            .map(|reads| reads.keys().cloned().collect())
            .unwrap_or_default(),
    }
}

/// The value the operand tells, after checking it is the mock's.
fn value(probe: &Probe) -> Value {
    assert_eq!("mock-config", probe.operand["source"]);
    probe.operand["value"].clone()
}

#[test]
fn the_reviewers_probe_a_body_use_of_the_blanket_trait_takes_the_call() {
    let probe_ = probe(
        "trait-rival-probe",
        LIMITS,
        "",
        "",
        "        use crate::ext::Limits;",
    );
    assert_eq!(Value::Null, value(&probe_));
}

#[test]
fn an_imported_blanket_trait_of_another_file_takes_the_call() {
    let imported = probe(
        "trait-rival-import",
        LIMITS,
        "    use crate::ext::Limits;",
        "",
        "",
    );
    assert_eq!(Value::Null, value(&imported));
    assert!(imported.reads.contains(&"src/ext.rs".to_owned()));
    // The healthy twin: the mocked trait, its mock, a struct and a function.
    let healthy = probe(
        "trait-rival-import-healthy",
        PLAIN,
        "    use crate::ext::{helper, Helper};",
        "",
        "",
    );
    assert_eq!(json!("5"), value(&healthy));
}

#[test]
fn a_glob_of_a_module_declaring_a_trait_takes_the_call() {
    let globbed = probe("trait-rival-glob", LIMITS, "    use crate::ext::*;", "", "");
    assert_eq!(Value::Null, value(&globbed));
    // A glob of a module that declares no trait brings none in, and the
    // module is read, so a trait added to it is seen.
    let plain = probe(
        "trait-rival-glob-healthy",
        PLAIN,
        "    use crate::ext::*;",
        "",
        "",
    );
    assert_eq!(json!("5"), value(&plain));
    assert!(plain.reads.contains(&"src/ext.rs".to_owned()));
}

#[test]
fn a_glob_of_a_module_re_exporting_a_trait_takes_the_call() {
    let inner =
        "pub mod inner {\n    pub trait Clamp {\n        fn limit(self) -> u32;\n    }\n    \
        pub struct Plain;\n}\n";
    let trait_ = format!("{inner}pub use inner::Clamp;\n");
    let globbed = probe(
        "trait-rival-glob-export",
        &trait_,
        "    use crate::ext::*;",
        "",
        "",
    );
    assert_eq!(Value::Null, value(&globbed));
    let plain = format!("{inner}pub use inner::Plain;\n");
    let healthy = probe(
        "trait-rival-glob-export-healthy",
        &plain,
        "    use crate::ext::*;",
        "",
        "",
    );
    assert_eq!(json!("5"), value(&healthy));
}

#[test]
fn a_glob_of_a_module_where_a_macro_stands_for_items_takes_the_call() {
    let ext = format!("{PLAIN}macro_rules! limits {{\n    () => {{}};\n}}\nlimits!();\n");
    let globbed = probe(
        "trait-rival-glob-macro",
        &ext,
        "    use crate::ext::*;",
        "",
        "",
    );
    assert_eq!(Value::Null, value(&globbed));
}

#[test]
fn a_trait_of_the_file_with_a_method_of_the_name_takes_the_call() {
    let rival = "    trait Clamp {\n        fn limit(self) -> u32;\n    }";
    let declared = probe("trait-rival-local", PLAIN, "", rival, "");
    assert_eq!(Value::Null, value(&declared));
    // Another trait of the file without such a method takes nothing.
    let other = "    trait Clamp {\n        fn clamp(self) -> u32;\n    }";
    let healthy = probe("trait-rival-local-healthy", PLAIN, "", other, "");
    assert_eq!(json!("5"), value(&healthy));
}

#[test]
fn a_body_use_outside_the_standard_crates_takes_the_call() {
    let foreign = probe(
        "trait-rival-body-use",
        PLAIN,
        "",
        "",
        "        use crate::ext::Helper;",
    );
    assert_eq!(Value::Null, value(&foreign));
    let standard = probe(
        "trait-rival-body-use-std",
        PLAIN,
        "",
        "",
        "        use std::fmt::Write;",
    );
    assert_eq!(json!("5"), value(&standard));
}

#[test]
fn an_import_the_index_cannot_place_takes_the_call() {
    let unknown = probe(
        "trait-rival-unknown",
        PLAIN,
        "    use other_crate::Thing;",
        "",
        "",
    );
    assert_eq!(Value::Null, value(&unknown));
    // mockall's own macros and predicates are no trait.
    let framework = probe(
        "trait-rival-framework",
        PLAIN,
        "    use mockall::predicate::*;\n    use mockall::{automock, Sequence};",
        "",
        "",
    );
    assert_eq!(json!("5"), value(&framework));
}

#[test]
fn a_member_named_like_a_blanket_std_method_never_tells_a_value() {
    let repo = "#[mockall::automock]\npub trait UserRepo {\n    fn into(&self) -> u32;\n}\n";
    let service = "#[cfg(test)]\nmod tests {\n    use crate::repo::{MockUserRepo, UserRepo};\n    \
        #[test]\n    fn t() {\n        let mut repo = MockUserRepo::new();\n        \
        repo.expect_into().return_const(5u32);\n        assert_eq!(repo.into(), 5);\n    }\n}\n";
    let contributions = scan_fixture_with(
        "trait-rival-std-name",
        &[
            ("Cargo.toml", "[package]\nname = \"probe\"\n"),
            ("src/lib.rs", "pub mod repo;\npub mod service;\n"),
            ("src/repo.rs", repo),
            ("src/service.rs", service),
        ],
        &json!({ "config_files": ["Cargo.toml"] }),
    );
    let cases = nodes_of(&contributions, "test_case");
    let operand = &cases.values().next().unwrap()["attributes"]["assertions"][0]["operands"][0];
    assert_eq!("mock-config", operand["source"]);
    assert_eq!(Value::Null, operand["value"]);
}

/// The operand of the one assertion of each case of a `mock!` whose
/// struct section declares `size` and whose `impl UserRepo` section
/// declares `limit` and `count`, with `uses` at the top of `tests/it.rs`,
/// by case name.
fn mock_macro(name: &str, uses: &str) -> std::collections::BTreeMap<String, Value> {
    let it = format!(
        "{uses}use probe::repo::UserRepo;\n\nmockall::mock! {{\n    pub Store {{\n        \
         fn size(&self) -> u32;\n    }}\n    impl UserRepo for Store {{\n        fn limit(&self) -> u32;\n        \
         fn count(&self) -> u32;\n    }}\n}}\n\n#[test]\nfn inherent() {{\n    let mut store = MockStore::new();\n    \
         store.expect_size().return_const(2u32);\n    assert_eq!(store.size(), 2);\n}}\n\n\
         #[test]\nfn of_the_trait() {{\n    let mut store = MockStore::new();\n    \
         store.expect_count().return_const(3u32);\n    assert_eq!(store.count(), 3);\n}}\n"
    );
    let contributions = scan_fixture_with(
        name,
        &[
            ("Cargo.toml", "[package]\nname = \"probe\"\n"),
            ("src/lib.rs", "pub mod ext;\npub mod repo;\n"),
            (
                "src/repo.rs",
                "pub trait UserRepo {\n    fn limit(&self) -> u32;\n    fn count(&self) -> u32;\n}\n",
            ),
            ("src/ext.rs", LIMITS),
            ("tests/it.rs", &it),
        ],
        &json!({ "config_files": ["Cargo.toml"] }),
    );
    nodes_of(&contributions, "test_case")
        .values()
        .map(|case| {
            (
                case["attributes"]["name"].as_str().unwrap().to_owned(),
                case["attributes"]["assertions"][0]["operands"][0].clone(),
            )
        })
        .collect()
}

#[test]
fn both_sections_of_a_mock_macro_may_lose_the_call() {
    let rivalled = mock_macro("trait-rival-mock-macro", "use probe::ext::Limits;\n");
    assert_eq!("mock-config", rivalled["of_the_trait"]["source"]);
    assert_eq!(Value::Null, rivalled["of_the_trait"]["value"]);
    // A by-value blanket method takes the call before an inherent
    // `size(&self)` is tried, too.
    assert_eq!("mock-config", rivalled["inherent"]["source"]);
    assert_eq!(Value::Null, rivalled["inherent"]["value"]);
    let healthy = mock_macro("trait-rival-mock-macro-healthy", "");
    assert_eq!(json!("3"), healthy["of_the_trait"]["value"]);
    assert_eq!(json!("2"), healthy["inherent"]["value"]);
}

/// The operand of the one case's assertion in a one-file crate of
/// `source`.
fn single(name: &str, source: &str) -> Value {
    let contributions = scan_fixture_with(
        name,
        &[
            ("Cargo.toml", "[package]\nname = \"probe\"\n"),
            ("src/lib.rs", source),
        ],
        &json!({ "config_files": ["Cargo.toml"] }),
    );
    let cases = nodes_of(&contributions, "test_case");
    cases.values().next().unwrap()["attributes"]["assertions"][0]["operands"][0].clone()
}

#[test]
fn a_mockiato_and_an_mry_trait_mock_are_trait_mocks() {
    let rival = "pub trait Loud: Sized {\n    fn greet(self) -> u32 {\n        0\n    }\n}\n\
        impl<T> Loud for T {}\n";
    let mockiato = "use mockiato::mockable;\n#[mockable]\npub trait Greeter {\n    fn greet(&self) -> u32;\n}\n\
        #[test]\nfn case() {\n    let mut greeter = GreeterMock::new();\n    \
        greeter.expect_greet().returns(1);\n    assert_eq!(greeter.greet(), 1);\n}\n";
    let mry = "#[mry::mry]\npub trait Greeter {\n    fn greet(&self) -> u32;\n}\n\
        #[test]\nfn case() {\n    let mut greeter = MockGreeter::default();\n    \
        greeter.mock_greet().returns(1);\n    assert_eq!(greeter.greet(), 1);\n}\n";
    for (name, source) in [("mockiato", mockiato), ("mry", mry)] {
        let healthy = single(&format!("trait-rival-{name}-healthy"), source);
        assert_eq!(json!("1"), healthy["value"], "{name}");
        let rivalled = single(&format!("trait-rival-{name}"), &format!("{rival}{source}"));
        assert_eq!("mock-config", rivalled["source"], "{name}");
        assert_eq!(Value::Null, rivalled["value"], "{name}");
    }
}

/// The value `repo.limit()` gives when `src/service.rs`'s test module
/// globs `src/ext.rs`, holding `ext`.
fn globbed(name: &str, ext: &str) -> Value {
    value(&probe(name, ext, "    use crate::ext::*;", "", ""))
}

#[test]
fn what_a_glob_brings_in_is_followed() {
    // Neither the standard crates' names, mockall's, nor a private `use`
    // the test cannot see through the glob brings in a trait.
    let quiet = format!(
        "{PLAIN}pub use std::fmt::Display;\npub use mockall::Sequence;\npub use mockall::predicate::*;\n\
         use other_crate::Thing;\nmacro_rules! unused {{\n    () => {{}};\n}}\n"
    );
    assert_eq!(json!("5"), globbed("trait-rival-glob-quiet", &quiet));
    // A module that only re-exports the mocked trait, or only globs its
    // module, is known.
    let reexport = "pub use crate::repo::UserRepo;\n";
    assert_eq!(json!("5"), globbed("trait-rival-glob-reexport", reexport));
    let reglob = "pub use crate::repo::*;\n";
    assert_eq!(json!("5"), globbed("trait-rival-glob-reglob", reglob));
    // A glob of a glob, and a re-export of a re-export, reach the trait.
    let nested = "pub mod inner {\n    pub trait Clamp {\n        fn limit(self) -> u32;\n    }\n}\npub use inner::*;\n";
    assert_eq!(Value::Null, globbed("trait-rival-glob-nested", nested));
    let chain = "pub mod deep {\n    pub trait Clamp {\n        fn limit(self) -> u32;\n    }\n}\n\
        pub mod inner {\n    pub use super::deep::Clamp;\n}\npub use inner::Clamp;\n";
    assert_eq!(Value::Null, globbed("trait-rival-glob-chain", chain));
}

#[test]
fn a_cycle_of_re_exports_or_globs_ends_unknown() {
    let exports = "pub use self::b::Thing;\npub mod b {\n    pub use super::Thing;\n}\n";
    assert_eq!(Value::Null, globbed("trait-rival-export-cycle", exports));
    let globs = "pub mod a {\n    pub use super::b::*;\n}\npub mod b {\n    pub use super::a::*;\n}\npub use a::*;\n";
    assert_eq!(Value::Null, globbed("trait-rival-glob-cycle", globs));
}

#[test]
fn an_imported_alias_or_module_is_no_trait() {
    let ext = format!("{PLAIN}pub type Alias = Helper;\n");
    let imported = probe(
        "trait-rival-import-alias",
        &ext,
        "    use crate::ext;\n    use crate::ext::Alias;",
        "",
        "",
    );
    assert_eq!(json!("5"), value(&imported));
}

/// The value `repo.limit()` gives in `src/repo/tests.rs`, which globs its
/// parent `src/repo.rs`, holding `extra` after the mocked trait.
fn parent_glob(name: &str, extra: &str) -> Value {
    let repo = format!("{REPO}{extra}#[cfg(test)]\nmod tests;\n");
    let tests = "use super::*;\n\n#[test]\nfn t() {\n    let mut repo = MockUserRepo::new();\n    \
        repo.expect_limit().return_const(5u32);\n    assert_eq!(repo.limit(), 5);\n}\n";
    let contributions = scan_fixture_with(
        name,
        &[
            ("Cargo.toml", "[package]\nname = \"probe\"\n"),
            ("src/lib.rs", "pub mod repo;\n"),
            ("src/repo.rs", &repo),
            ("src/repo/tests.rs", tests),
        ],
        &json!({ "config_files": ["Cargo.toml"] }),
    );
    let cases = nodes_of(&contributions, "test_case");
    let operand = &cases.values().next().unwrap()["attributes"]["assertions"][0]["operands"][0];
    assert_eq!("mock-config", operand["source"]);
    operand["value"].clone()
}

#[test]
fn a_glob_of_the_parent_sees_its_private_imports() {
    assert_eq!(json!("5"), parent_glob("trait-rival-parent", ""));
    let private = parent_glob("trait-rival-parent-private", "use other_crate::Thing;\n");
    assert_eq!(Value::Null, private);
}

#[test]
fn what_the_file_itself_declares_is_judged_by_its_methods() {
    // Another trait of the file, imported or globbed from a module of the
    // file, takes the call only if it has a method of the name; a module of
    // the file is no trait, even an empty one.
    let source = "#[mockall::automock]\npub trait Clock {\n    fn now(&self) -> u64;\n}\n\
        pub trait Other {\n    fn other(&self) -> u64;\n}\nmod empty {}\n\
        #[cfg(test)]\nmod tests {\n    use super::*;\n    use super::{MockClock, Other};\n    use super::empty;\n\n    \
        #[test]\n    fn t() {\n        let mut clock = MockClock::new();\n        \
        clock.expect_now().return_const(5u64);\n        assert_eq!(clock.now(), 5);\n    }\n}\n";
    assert_eq!(json!("5"), single("trait-rival-own", source)["value"]);
}

#[test]
fn a_re_export_of_a_re_export_of_a_struct_brings_no_trait() {
    let chain = "pub mod deep {\n    pub struct Plain;\n}\n\
        pub mod inner {\n    pub use super::deep::Plain;\n}\npub use inner::Plain;\n";
    assert_eq!(json!("5"), globbed("trait-rival-glob-chain-healthy", chain));
}

#[test]
fn a_private_glob_of_the_globbed_module_is_not_seen_from_outside() {
    let hidden = format!(
        "{PLAIN}mod hidden {{\n    pub trait Clamp {{\n        fn limit(self) -> u32;\n    }}\n}}\nuse hidden::*;\n"
    );
    assert_eq!(json!("5"), globbed("trait-rival-glob-private", &hidden));
}

#[test]
fn an_automock_trait_impl_is_a_trait_mock() {
    let mocked = "pub trait Repo {\n    fn limit(&self) -> u32;\n}\npub struct Foo;\n\
        #[mockall::automock]\nimpl Repo for Foo {\n    fn limit(&self) -> u32 {\n        1\n    }\n}\n\
        #[test]\nfn case() {\n    let mut foo = MockFoo::new();\n    \
        foo.expect_limit().return_const(5u32);\n    assert_eq!(foo.limit(), 5);\n}\n";
    assert_eq!(
        json!("5"),
        single("trait-rival-automock-impl-healthy", mocked)["value"]
    );
    let rival = "pub trait Loud: Sized {\n    fn limit(self) -> u32 {\n        0\n    }\n}\n\
        impl<T> Loud for T {}\n";
    let rivalled = single("trait-rival-automock-impl", &format!("{rival}{mocked}"));
    assert_eq!("mock-config", rivalled["source"]);
    assert_eq!(Value::Null, rivalled["value"]);
}

/// The rustc probe: `impl<T> Ext for T` with `fn get(self)` takes `s.get()`
/// before the inherent `get(&self)` is tried, and the call returns 2.
const BY_VALUE: &str = "pub trait Ext: Sized {\n    fn get(self) -> u32 {\n        2\n    }\n}\nimpl<T> Ext for T {}\n";

#[test]
fn a_blanket_by_value_method_takes_an_inherent_mocks_call() {
    let faux = "#[faux::create]\npub struct S {\n    a: u32,\n}\n#[faux::methods]\nimpl S {\n    \
        pub fn get(&self) -> u32 {\n        self.a\n    }\n}\n\
        #[test]\nfn case() {\n    let mut s = S::faux();\n    faux::when!(s.get).then_return(1);\n    \
        assert_eq!(s.get(), 1);\n}\n";
    let automock = "pub struct S;\n#[mockall::automock]\nimpl S {\n    pub fn get(&self) -> u32 {\n        1\n    }\n}\n\
        #[test]\nfn case() {\n    let mut s = MockS::new();\n    s.expect_get().return_const(1u32);\n    \
        assert_eq!(s.get(), 1);\n}\n";
    for (name, source) in [("faux", faux), ("automock-inherent", automock)] {
        let healthy = single(&format!("inherent-rival-{name}-healthy"), source);
        assert_eq!("mock-config", healthy["source"], "{name}");
        assert_eq!(json!("1"), healthy["value"], "{name}");
        let rivalled = single(
            &format!("inherent-rival-{name}"),
            &format!("{BY_VALUE}{source}"),
        );
        assert_eq!("mock-config", rivalled["source"], "{name}");
        assert_eq!(Value::Null, rivalled["value"], "{name}");
    }
}

#[test]
fn an_imported_trait_may_take_an_inherent_mocks_call() {
    // A struct mock implements no trait to rule out: any trait the file
    // brings in may be one.
    let faux = "#[cfg(test)]\nmod tests {\n    use crate::ext::Limits;\n\n    #[faux::create]\n    pub struct S {\n        a: u32,\n    }\n    \
        #[faux::methods]\n    impl S {\n        pub fn limit(&self) -> u32 {\n            self.a\n        }\n    }\n\n    \
        #[test]\n    fn t() {\n        let mut s = S::faux();\n        faux::when!(s.limit).then_return(5);\n        \
        assert_eq!(s.limit(), 5);\n    }\n}\n";
    let contributions = scan_fixture_with(
        "inherent-rival-import",
        &[
            ("Cargo.toml", "[package]\nname = \"probe\"\n"),
            (
                "src/lib.rs",
                "pub mod ext;\npub mod repo;\npub mod service;\n",
            ),
            ("src/repo.rs", REPO),
            ("src/ext.rs", LIMITS),
            ("src/service.rs", faux),
        ],
        &json!({ "config_files": ["Cargo.toml"] }),
    );
    let cases = nodes_of(&contributions, "test_case");
    let operand = &cases.values().next().unwrap()["attributes"]["assertions"][0]["operands"][0];
    assert_eq!("mock-config", operand["source"]);
    assert_eq!(Value::Null, operand["value"]);
}
