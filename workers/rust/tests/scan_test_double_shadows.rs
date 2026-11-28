//! Scanning tests of the names that are not the generated mock they look
//! like (Task 3 review I3–I5): a mocked `new`, a test gate across Cargo
//! targets, and a name the module or the body declares itself. Each gives
//! no mockall double, so no pin and no echo.

mod common;

use common::*;
use serde_json::{json, Value};

/// A one-package crate of `files` beside its manifest.
fn crate_of(name: &str, files: &[(&str, &str)]) -> Vec<Value> {
    let mut all = vec![("Cargo.toml", "[package]\nname = \"probe\"\n")];
    all.extend_from_slice(files);
    scan_fixture_with(name, &all, &json!({ "config_files": ["Cargo.toml"] }))
}

/// The canonical names of the `test_double` nodes.
fn doubles(contributions: &[Value]) -> Vec<String> {
    nodes_of(contributions, "test_double").into_keys().collect()
}

/// The first operand of the first assertion of `case`.
fn first_operand(contributions: &[Value], case: &str) -> Value {
    nodes_of(contributions, "test_case")[case]["attributes"]["assertions"][0]["operands"][0].clone()
}

#[test]
fn a_mocked_new_makes_no_fresh_mock() {
    // t3/holes :74: `MockMaker::new()` is the mocked static `new`, which
    // returns whatever its context's expectation builds.
    let tests = "#[mockall::automock]\npub trait Maker {\n    fn new() -> Self;\n    fn get(&self) -> u64;\n}\n\
        mockall::mock! {\n    pub Built {\n        fn default() -> Self;\n        fn get(&self) -> u64;\n    }\n}\n\
        #[test]\nfn made() {\n    let mut maker = MockMaker::new();\n    maker.expect_get().return_const(42u64);\n    \
        assert_eq!(maker.get(), 42);\n    let fresh = MockMaker::default();\n    let built = MockBuilt::default();\n    \
        let other = MockBuilt::new();\n}\n";
    let contributions = crate_of("shadows-new", &[("tests/maker.rs", tests)]);

    // Only `MockMaker::default()` and `MockBuilt::new()` make mocks.
    assert_eq!(
        vec![
            "tests/maker.rs#double:17:17".to_owned(),
            "tests/maker.rs#double:19:17".to_owned(),
        ],
        doubles(&contributions)
    );
    assert_eq!(
        json!({"source": "unknown"}),
        first_operand(&contributions, "tests/maker.rs#test:13")
    );
}

#[test]
fn a_test_gate_keeps_a_mock_to_its_own_target() {
    // t3/ws app/tests/it.rs: a member named like its directory, whose
    // integration test is another crate than its library.
    let gate =
        "#[cfg_attr(test, mockall::automock)]\npub trait Port {\n    fn read(&self) -> u32;\n}\n";
    let lib = "pub mod gate;\n#[cfg(test)]\nmod tests {\n    use crate::gate::MockPort;\n    #[test]\n    fn inside() {\n        let _port = MockPort::new();\n    }\n}\n";
    let it =
        "use app::gate::MockPort;\n#[test]\nfn outside() {\n    let _port = MockPort::new();\n}\n";
    let contributions = scan_fixture_with(
        "shadows-gate-member",
        &[
            ("Cargo.toml", "[workspace]\nmembers = [\"app\"]\n"),
            ("app/Cargo.toml", "[package]\nname = \"app\"\n"),
            ("app/src/lib.rs", lib),
            ("app/src/gate.rs", gate),
            ("app/tests/it.rs", it),
        ],
        &json!({ "config_files": ["Cargo.toml", "app/Cargo.toml"] }),
    );

    // The library's own tests see it; `tests/it.rs` does not.
    assert_eq!(
        vec!["app/src/lib.rs#double:7:21".to_owned()],
        doubles(&contributions)
    );
}

#[test]
fn a_name_the_module_or_body_declares_beats_a_generated_mock() {
    let clock = "#[mockall::automock]\npub trait Clock {\n    fn now(&self) -> u64;\n}\n";
    // t3/ws core/src/lib.rs:26: a hand-written fake beside a glob.
    let glob = "#[cfg(test)]\nmod tests {\n    use crate::clock::*;\n    struct MockClock;\n    \
        impl MockClock {\n        fn new() -> Self {\n            MockClock\n        }\n    }\n    \
        #[test]\n    fn fake() {\n        let _fake = MockClock::new();\n    }\n}\n";
    // t3/holes :61 and :47: a struct, and a `use`, in the test body.
    let body = "#[cfg(test)]\nmod tests {\n    use crate::clock::*;\n    #[test]\n    fn local_struct() {\n        \
        struct MockClock;\n        let mut mock = MockClock::new();\n        mock.expect_now().return_const(42u64);\n        \
        assert_eq!(mock.now(), 42);\n    }\n    #[test]\n    fn local_use() {\n        use crate::other::MockClock;\n        \
        let _mock = MockClock::new();\n    }\n    #[test]\n    fn generated() {\n        let _mock = MockClock::new();\n    }\n}\n";
    let contributions = crate_of(
        "shadows-names",
        &[
            (
                "src/lib.rs",
                "pub mod clock;\npub mod other;\nmod glob;\nmod body;\n",
            ),
            ("src/clock.rs", clock),
            ("src/other.rs", clock),
            ("src/glob.rs", glob),
            ("src/body.rs", body),
        ],
    );

    assert_eq!(
        vec!["src/body.rs#double:18:21".to_owned()],
        doubles(&contributions)
    );
    assert_eq!(
        json!({"source": "unknown"}),
        first_operand(&contributions, "src/body.rs#test:5")
    );
}

#[test]
fn only_a_declared_new_or_default_is_mocked() {
    // An `#[automock] impl` with its own `new`; a `mock!` whose `default`
    // is only a parameter's name, which mocks nothing.
    let tests = "pub struct Shop;\n#[mockall::automock]\nimpl Shop {\n    pub fn new() -> Self {\n        Shop\n    }\n}\n\
        mockall::mock! {\n    pub Plain {\n        fn get(&self, default: u32, new: u32) -> u64;\n    }\n}\n\
        #[test]\nfn made() {\n    let _shop = MockShop::new();\n    let _plain = MockPlain::new();\n    let _other = MockPlain::default();\n}\n";
    let contributions = crate_of("shadows-impl-new", &[("tests/shop.rs", tests)]);

    assert_eq!(
        vec![
            "tests/shop.rs#double:16:18".to_owned(),
            "tests/shop.rs#double:17:18".to_owned(),
        ],
        doubles(&contributions)
    );
}

#[test]
fn a_binary_is_another_crate_than_its_library() {
    let gate = "#[cfg_attr(test, mockall::automock)]\npub trait Port {}\n";
    let bin = "#[cfg(test)]\nmod tests {\n    use probe::gate::MockPort;\n    #[test]\n    fn binary() {\n        let _port = MockPort::new();\n    }\n}\nfn main() {}\n";
    let contributions = crate_of(
        "shadows-bin",
        &[
            ("src/lib.rs", "pub mod gate;\n"),
            ("src/gate.rs", gate),
            ("src/bin/tool.rs", bin),
        ],
    );

    assert!(doubles(&contributions).is_empty());
    // The library's own test build has it.
    let lib = "pub mod gate;\n#[cfg(test)]\nmod tests {\n    #[test]\n    fn library() {\n        let _port = crate::gate::MockPort::new();\n    }\n}\n";
    let control = crate_of(
        "shadows-bin-control",
        &[("src/lib.rs", lib), ("src/gate.rs", gate)],
    );
    assert_eq!(1, doubles(&control).len());
}

#[test]
fn every_item_the_body_declares_hides_the_mock() {
    let clock = "#[mockall::automock]\npub trait Clock {}\n";
    for item in [
        "struct MockClock;",
        "enum MockClock {}",
        "union MockClock { a: u8 }",
        "type MockClock = u8;",
        "trait MockClock {}",
        "fn MockClock() {}",
        "mod MockClock {}",
        "const MockClock: u8 = 0;",
        "static MockClock: u8 = 0;",
        "macro_rules! MockClock { () => {}; }",
        "use crate::clock::Other as MockClock;",
        // The control: nothing hides it.
        "",
    ] {
        let tests = format!("#[cfg(test)]\nmod tests {{\n    use crate::clock::*;\n    #[test]\n    fn hidden() {{\n        {item}\n        let _mock = MockClock::new();\n    }}\n}}\n");
        let contributions = crate_of(
            "shadows-body-items",
            &[
                ("src/lib.rs", "pub mod clock;\nmod uses;\n"),
                ("src/clock.rs", clock),
                ("src/uses.rs", &tests),
            ],
        );
        assert_eq!(
            item.is_empty(),
            !doubles(&contributions).is_empty(),
            "{item}"
        );
    }
}

#[test]
fn a_declared_name_beats_a_glob_in_the_file_and_through_the_index() {
    // Through the index: another file's module declares the name beside
    // its glob of the mock's.
    let contributions = crate_of(
        "shadows-indexed-name",
        &[
            ("src/lib.rs", "pub mod clock;\npub mod fakes;\nmod uses;\n"),
            ("src/clock.rs", "#[mockall::automock]\npub trait Clock {}\n"),
            ("src/fakes.rs", "pub use crate::clock::*;\npub struct MockClock;\n"),
            (
                "src/uses.rs",
                "#[cfg(test)]\nmod tests {\n    #[test]\n    fn fake() {\n        let _fake = crate::fakes::MockClock::new();\n    }\n}\n",
            ),
        ],
    );
    assert!(doubles(&contributions).is_empty());
    // The control: without the struct, the glob reaches the mock.
    let control = crate_of(
        "shadows-indexed-control",
        &[
            ("src/lib.rs", "pub mod clock;\npub mod fakes;\nmod uses;\n"),
            ("src/clock.rs", "#[mockall::automock]\npub trait Clock {}\n"),
            ("src/fakes.rs", "pub use crate::clock::*;\n"),
            (
                "src/uses.rs",
                "#[cfg(test)]\nmod tests {\n    #[test]\n    fn fake() {\n        let _fake = crate::fakes::MockClock::new();\n    }\n}\n",
            ),
        ],
    );
    assert_eq!(1, doubles(&control).len());
    // In the file alone: with no manifest, nothing is indexed.
    let flat = "#[mockall::automock]\npub trait Clock {}\n#[cfg(test)]\nmod tests {\n    use super::*;\n    struct MockClock;\n    \
        #[test]\n    fn fake() {\n        let _fake = MockClock::new();\n    }\n}\n";
    let contributions = scan_fixture_with("shadows-flat-name", &[("flat.rs", flat)], &json!({}));
    assert!(doubles(&contributions).is_empty());
    let unhidden = flat.replace("    struct MockClock;\n", "");
    let control = scan_fixture_with(
        "shadows-flat-control",
        &[("flat.rs", &unhidden)],
        &json!({}),
    );
    assert_eq!(1, doubles(&control).len());
}

#[test]
fn a_qualified_mocked_constructor_makes_no_fresh_mock() {
    // t3r1/a :149, :156 and :170: every spelling of a mocked `new` or
    // `default` is the mocked static method; only the `Default` trait's
    // `default` builds a fresh mock.
    let tests = "#[mockall::automock]\npub trait Maker {\n    fn new() -> Self;\n}\n\
        #[mockall::automock]\npub trait Defaulted {\n    fn default() -> Self;\n}\n\
        #[test]\nfn spelled() {\n    let _a = <MockMaker as Maker>::new();\n    let _b = <MockMaker>::new();\n    \
        let _c = <MockDefaulted as Defaulted>::default();\n    let _d = <MockDefaulted>::default();\n    \
        let _e = <MockDefaulted as Default>::default();\n}\n";
    let contributions = crate_of("shadows-qualified-new", &[("tests/qualified.rs", tests)]);

    assert_eq!(
        vec!["tests/qualified.rs#double:15:14".to_owned()],
        doubles(&contributions)
    );
}
