//! Scanning tests of the operands a double gives an assertion (Decisions 7
//! and 9): `mock-config`, with the literal its expectation pins only when
//! nothing may change what the member returns out of sight, and
//! `mock-call` for every other call rooted at a double; and of what the
//! calls on a double leave out of `production_calls`.

mod common;

use common::*;
use serde_json::{json, Value};

/// The trait every case here mocks, and its `#[automock]`.
const HEADER: &str = "#[mockall::automock]\npub trait Clock {\n    fn now(&self) -> u64;\n    fn later(&self) -> u64;\n}\n";

/// The double every case makes, `let mut clock = MockClock::new();` on
/// the case's first line.
const DOUBLE: &str = "tests/clock.rs#double:8:21";

/// The case `fn case() { … }` holding `body` after the double, scanned in
/// a one-package crate: its node's attributes.
fn case(body: &str) -> Value {
    let source = format!(
        "{HEADER}#[test]\nfn case() {{\n    let mut clock = MockClock::new();\n{body}\n}}\n"
    );
    // A root of its own per scan: the tests run at once.
    static SCANS: std::sync::atomic::AtomicUsize = std::sync::atomic::AtomicUsize::new(0);
    let scan = SCANS.fetch_add(1, std::sync::atomic::Ordering::SeqCst);
    let contributions = scan_fixture_with(
        &format!("mock-operands-{scan}"),
        &[
            ("Cargo.toml", "[package]\nname = \"probe\"\n"),
            ("tests/clock.rs", &source),
        ],
        &json!({ "config_files": ["Cargo.toml"] }),
    );
    nodes_of(&contributions, "test_case")["tests/clock.rs#test:7"]["attributes"].clone()
}

/// The operand `clock.now()` gives in `body`'s last assertion.
fn echoed(body: &str) -> Value {
    let attributes = case(body);
    let assertions = attributes["assertions"].as_array().unwrap();
    assertions.last().unwrap()["operands"][0].clone()
}

/// A `mock-config` operand of the double, with `value` when given.
fn config(value: Option<&str>) -> Value {
    match value {
        Some(value) => json!({"source": "mock-config", "double": DOUBLE, "value": value}),
        None => json!({"source": "mock-config", "double": DOUBLE}),
    }
}

#[test]
fn traces_a_local_mock_echo() {
    let contributions = scan_repository_fixture("test-fidelity/rust/nemesis");
    let case = &nodes_of(&contributions, "test_case")["tests/storage_mock.rs#test:15"];

    assert_eq!(
        json!([
            {"source": "mock-config", "value": "1", "double": "tests/storage_mock.rs#double:16:20"},
            {"source": "literal", "text": "1"},
        ]),
        case["attributes"]["assertions"][0]["operands"]
    );
}

#[test]
fn every_literal_form_of_a_value_verb_is_traced() {
    for (verb, value) in [
        ("return_const(5u64)", "5"),
        ("returning(|| 5)", "5"),
        ("returning(|| { 5 })", "5"),
        ("return_once(move || 5)", "5"),
        ("returning_st(|| -5)", "-5"),
    ] {
        let body = format!("    clock.expect_now().{verb};\n    assert_eq!(clock.now(), 5);");
        assert_eq!(config(Some(value)), echoed(&body), "{verb}");
    }
    // `.with`, `.times` and `.in_sequence` never change the value, and
    // `.await` reads it as it is.
    let body = "    clock.expect_now().with(eq(1)).times(1).in_sequence(&mut seq).return_const(5u64);\n    assert_eq!(clock.now().await, 5);";
    assert_eq!(config(Some("5")), echoed(body));
    // A shared borrow handed on changes nothing.
    let body = "    clock.expect_now().return_const(5u64);\n    read(&clock);\n    assert_eq!(clock.now(), 5);";
    assert_eq!(config(Some("5")), echoed(body));
}

#[test]
fn each_defeat_drops_the_value() {
    let pinned = "    clock.expect_now().return_const(5u64);\n";
    for (defeat, body) in [
        (
            "two chains",
            format!("{pinned}    clock.expect_now().return_const(6u64);"),
        ),
        (
            "a chain in a loop",
            "    for _ in 0..1 {\n        clock.expect_now().return_const(5u64);\n    }".to_owned(),
        ),
        (
            "a chain in a branch",
            "    if ready() {\n        clock.expect_now().return_const(5u64);\n    }".to_owned(),
        ),
        (
            "&mut to a helper",
            format!("{pinned}    configure(&mut clock);"),
        ),
        ("checkpoint", format!("{pinned}    clock.checkpoint();")),
        (
            "a macro handed the handle",
            format!("{pinned}    configure!(clock);"),
        ),
        ("unsafe", format!("{pinned}    unsafe {{ touch(); }}")),
        (
            "an assignment",
            format!("{pinned}    clock = MockClock::new();"),
        ),
        (
            "a computed value",
            "    clock.expect_now().returning(move |x| x + 1);".to_owned(),
        ),
        ("a move", format!("{pinned}    let moved = clock;")),
        (
            "a capture",
            format!("{pinned}    let read = || clock.now();"),
        ),
        (
            "a capture by a macro",
            format!("{pinned}    let check = || assert_eq!(clock.now(), 5);"),
        ),
        (
            "a macro handed the handle in a group",
            format!("{pinned}    let _ = configure!({{ clock }});"),
        ),
        (
            "unsafe inside a macro",
            format!("{pinned}    wrap!(unsafe {{ touch() }});"),
        ),
        // Task 3 review I1 (t3/holes :39): a macro defined in the body sees
        // the local handle, and may add an expectation consulted first.
        (
            "a macro_rules! in the body",
            format!("{pinned}    macro_rules! setup {{\n        () => {{\n            touch();\n        }};\n    }}"),
        ),
        (
            "an unknown macro where an item may stand",
            format!("{pinned}    setup!();"),
        ),
        (
            "an unconfigured member",
            format!("{pinned}    clock.later();"),
        ),
        (
            "two value verbs",
            "    clock.expect_now().return_const(5u64).return_const(5u64);".to_owned(),
        ),
        ("a second binding", format!("{pinned}    let clock = 1;")),
    ] {
        let body = format!("{body}\n    assert_eq!(clock.now(), 5);");
        assert_eq!(config(None), echoed(&body), "{defeat}");
    }
    // `unsafe` on the function itself defeats it too.
    let source = format!("{HEADER}#[test]\nunsafe fn case() {{\n    let mut clock = MockClock::new();\n    clock.expect_now().return_const(5u64);\n    assert_eq!(clock.now(), 5);\n}}\n");
    let contributions = scan_fixture_with(
        "mock-operands-unsafe",
        &[
            ("Cargo.toml", "[package]\nname = \"probe\"\n"),
            ("tests/clock.rs", &source),
        ],
        &json!({ "config_files": ["Cargo.toml"] }),
    );
    assert_eq!(
        config(None),
        nodes_of(&contributions, "test_case")["tests/clock.rs#test:7"]["attributes"]["assertions"]
            [0]["operands"][0]
    );
}

#[test]
fn a_chain_rooted_at_a_double_is_a_mock_call() {
    let body = "    clock.expect_now().return_const(5u64);\n    assert!(clock.now().is_power_of_two());\n    assert_eq!(clock.later(), 1);";
    let attributes = case(body);
    let call = json!({"source": "mock-call", "double": DOUBLE});

    assert_eq!(json!([call]), attributes["assertions"][0]["operands"]);
    // `later` is configured nowhere: a call, not a configured value.
    assert_eq!(call, attributes["assertions"][1]["operands"][0]);
}

#[test]
fn calls_on_a_double_and_in_its_configuration_are_no_production() {
    let body = "    clock.expect_now().returning(|| \"7\".parse().unwrap());\n    assert!(clock.now().is_power_of_two());";
    assert_eq!(json!(0), case(body)["production_calls"]);
    // A method call on anything else still counts.
    let body = "    clock.expect_now().return_const(1u64);\n    assert!(service.ready());";
    assert_eq!(json!(1), case(body)["production_calls"]);
}

#[test]
fn every_root_of_a_chain_is_its_double() {
    let other = "tests/clock.rs#double:10:21";
    let made = "tests/clock.rs#double:17:13";
    let body = [
        "    clock.expect_now().return_const(5u64);",
        "    let mut other = MockClock::new();",
        "    other.expect_later().return_const(1u64);",
        "    assert!(clock.later().await.is_power_of_two());",
        "    assert_eq!(clock.later().0, 1);",
        "    assert!(clock.later()?.is_power_of_two());",
        "    assert!((clock.later()).is_power_of_two());",
        "    assert_eq!(&clock.later(), &1);",
        "    assert!(MockClock::default().later().is_power_of_two());",
        "    assert_eq!(<MockClock as Clock>::later(&clock), 1);",
        "    assert_eq!(<MockClock as Clock>::later(&other), 1);",
    ]
    .join("\n");
    let attributes = case(&body);
    let firsts: Vec<Value> = attributes["assertions"]
        .as_array()
        .unwrap()
        .iter()
        .map(|assertion| assertion["operands"][0]["double"].clone())
        .collect();

    assert_eq!(
        vec![
            json!(DOUBLE),
            json!(DOUBLE),
            json!(DOUBLE),
            json!(DOUBLE),
            json!(DOUBLE),
            json!(made),
            json!(DOUBLE),
            json!(other),
        ],
        firsts
    );
    // A receiver in parentheses is the handle all the same.
    let body = "    clock.expect_now().return_const(5u64);\n    assert_eq!((clock).now(), 5);";
    assert_eq!(config(Some("5")), echoed(body));
}

#[test]
fn a_shadowed_assertion_macro_is_no_assertion() {
    // Task 3 review I2 (t3/shadowassert :30 and :35): a project
    // `macro_rules! assert_eq` may assert anything, production included.
    let source = format!(
        "{HEADER}#[cfg(test)]\nmod tests {{\n    use super::*;\n    macro_rules! assert_eq {{\n        ($a:expr, $b:expr) => {{\n            ::std::assert_eq!($a, $b)\n        }};\n    }}\n    \
         #[test]\n    fn shadowed_assertion() {{\n        let mut clock = MockClock::new();\n        clock.expect_now().return_const(42u64);\n        assert_eq!(clock.now(), 42);\n    }}\n    \
         #[test]\n    fn shadowed_literal() {{\n        assert_eq!(5, 5);\n        debug_assert_eq!(5, 5);\n    }}\n}}\n"
    );
    let contributions = scan_fixture_with(
        "mock-operands-shadowed-assertion",
        &[
            ("Cargo.toml", "[package]\nname = \"probe\"\n"),
            ("src/lib.rs", &source),
        ],
        &json!({ "config_files": ["Cargo.toml"] }),
    );
    let cases = nodes_of(&contributions, "test_case");
    let apis = |case: &Value| -> Vec<Value> {
        case["attributes"]["assertions"]
            .as_array()
            .unwrap()
            .iter()
            .map(|assertion| assertion["api"].clone())
            .collect()
    };

    assert!(apis(&cases["src/lib.rs#test:15"]).is_empty());
    // `debug_assert_eq` is still the standard one.
    assert_eq!(
        vec![json!("debug_assert_eq")],
        apis(&cases["src/lib.rs#test:21"])
    );
}
