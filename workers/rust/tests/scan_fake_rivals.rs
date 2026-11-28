//! Scanning tests of what else may answer a call on a hand-written fake
//! (Decision 4, fix round 1): a trait another file brings into scope, a
//! trait of another module of the file, and the standard crates' blanket
//! methods.

mod common;

use common::*;
use serde_json::{json, Value};

/// Scan a crate of `files` (a manifest added) as `name`.
fn scan(name: &str, files: &[(&str, &str)]) -> Vec<Value> {
    let mut all = vec![("Cargo.toml", "[package]\nname = \"probe\"\n")];
    all.extend_from_slice(files);
    scan_fixture_with(name, &all, &json!({ "config_files": ["Cargo.toml"] }))
}

/// The `test_double` nodes, by canonical name.
fn doubles(contributions: &[Value]) -> std::collections::BTreeMap<String, Value> {
    nodes_of(contributions, "test_double")
}

/// The reviewer's `blanket_xfile` crate: `root` is the crate root's extra
/// items, `ext` the file `src/ext.rs`. Its case is on line 13 or 12.
fn blanket(name: &str, root: &str, ext: &str) -> Value {
    let lib = format!(
        "pub trait Clock {{\n    fn now(&self) -> u64;\n}}\npub fn compute() -> u64 {{\n    42\n}}\n{root}\
         #[cfg(test)]\nmod tests {{\n    use super::*;\n    struct FakeClock;\n    \
         impl Clock for FakeClock {{\n        fn now(&self) -> u64 {{\n            42\n        }}\n    }}\n    \
         #[test]\n    fn t() {{\n        assert_eq!(FakeClock.now(), 42);\n    }}\n}}\n"
    );
    let contributions = scan_fixture_with(
        name,
        &[
            ("Cargo.toml", "[package]\nname = \"probe\"\n"),
            ("src/lib.rs", &lib),
            ("src/ext.rs", ext),
        ],
        &json!({ "config_files": ["Cargo.toml"] }),
    );
    let cases = nodes_of(&contributions, "test_case");
    cases.values().next().unwrap()["attributes"]["assertions"][0]["operands"][0].clone()
}

#[test]
fn a_trait_another_file_brings_in_may_take_the_call() {
    let take = "pub trait Take {\n    fn now(self) -> u64;\n}\n\
        impl<T: crate::Clock> Take for T {\n    fn now(self) -> u64 {\n        crate::compute()\n    }\n}\n";
    // `pub use ext::Take` reaches the test through `use super::*`, and
    // `Take::now(self)` takes the call by value before `Clock::now(&self)`.
    let reached = blanket("blanket-xfile", "mod ext;\npub use ext::Take;\n", take);
    assert_eq!("mock-config", reached["source"]);
    assert_eq!(Value::Null, reached["value"]);
    // The healthy twin: the other file brings nothing into the test's scope.
    let apart = blanket("blanket-apart", "mod ext;\n", "pub fn unrelated() {}\n");
    assert_eq!(json!("42"), apart["value"]);
}

#[test]
fn the_faked_trait_declared_in_a_module_of_the_file_is_no_rival() {
    let it = "pub mod api {\n    pub trait Clock {\n        fn now(&self) -> u64;\n    }\n}\n\
        mod fake {\n    use super::api::Clock;\n    struct Fixed;\n    impl Clock for Fixed {\n        \
        fn now(&self) -> u64 {\n            1\n        }\n    }\n    \
        #[test]\n    fn t() {\n        assert_eq!(Fixed.now(), 1);\n    }\n}\n";
    let contributions = scan("fake-own-trait", &[("tests/it.rs", it)]);

    let attributes = &doubles(&contributions)["tests/it.rs#double:9:5"]["attributes"];
    assert_eq!(
        1,
        attributes["members"][0]["returns"]
            .as_array()
            .unwrap()
            .len()
    );
    // Another trait of the file, in a module of its own, may take the call.
    let rival = format!(
        "{it}mod other {{\n    pub trait Take {{\n        fn now(self) -> u64;\n    }}\n}}\n"
    );
    let contributions = scan("fake-module-rival", &[("tests/it.rs", &rival)]);
    let attributes = &doubles(&contributions)["tests/it.rs#double:9:5"]["attributes"];
    assert_eq!(json!([]), attributes["members"][0]["returns"]);
}

#[test]
fn a_member_named_like_a_blanket_std_method_never_pins() {
    let lib = "pub trait Convert {\n    fn into(&self) -> u64;\n    fn width(&self) -> u64;\n}\n\
        #[cfg(test)]\nmod tests;\n";
    let tests = "use crate::Convert;\nstruct Fake;\nimpl Convert for Fake {\n    fn into(&self) -> u64 {\n        \
        1\n    }\n    fn width(&self) -> u64 {\n        2\n    }\n}\n";
    let contributions = scan_fixture_with(
        "fake-std-names",
        &[
            ("Cargo.toml", "[package]\nname = \"probe\"\n"),
            ("src/lib.rs", lib),
            ("src/tests.rs", tests),
        ],
        &json!({ "config_files": ["Cargo.toml"] }),
    );

    // `Into::into(self)` takes `fake.into()` by value; `width` keeps its pin.
    let attributes = &doubles(&contributions)["src/tests.rs#double:3:1"]["attributes"];
    assert_eq!(json!([]), attributes["members"][0]["returns"]);
    assert_eq!(
        1,
        attributes["members"][1]["returns"]
            .as_array()
            .unwrap()
            .len()
    );
}
