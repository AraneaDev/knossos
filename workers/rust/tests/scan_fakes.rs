//! Scanning tests of hand-written fakes (Decision 4): an `impl T for Fake`
//! in test code, `T` a project trait, is a `test_double` named at its
//! `impl` token, whose functions are its members; a member pins the literal
//! its body returns only when nothing else may answer the call.

mod common;

use common::*;
use serde_json::{json, Value};

/// The library every case here holds: the trait `Clock` and production
/// code that takes one.
const LIB: &str = "pub trait Clock {\n    fn now(&self) -> u64;\n}\n\
    pub fn elapsed(clock: &dyn Clock) -> u64 {\n    clock.now()\n}\n#[cfg(test)]\nmod tests;\n";

/// The fake every case starts from: `impl` on line 3, `now` on line 4.
const FAKE: &str = "use crate::Clock;\nstruct FakeClock;\nimpl Clock for FakeClock {\n    \
    fn now(&self) -> u64 {\n        42\n    }\n}\n";

/// The fake's double.
const DOUBLE: &str = "src/tests.rs#double:3:1";

/// Scan a crate of `files` (the library and its manifest added) as `name`.
fn scan(name: &str, files: &[(&str, &str)]) -> Vec<Value> {
    let mut all = vec![
        ("Cargo.toml", "[package]\nname = \"probe\"\n"),
        ("src/lib.rs", LIB),
    ];
    all.extend_from_slice(files);
    scan_fixture_with(name, &all, &json!({ "config_files": ["Cargo.toml"] }))
}

/// The `test_double` nodes, by canonical name.
fn doubles(contributions: &[Value]) -> std::collections::BTreeMap<String, Value> {
    nodes_of(contributions, "test_double")
}

/// The first operand of the last assertion of the case on `line` of
/// `src/tests.rs`.
fn first_operand(contributions: &[Value], line: usize) -> Value {
    let cases = nodes_of(contributions, "test_case");
    let case = &cases[&format!("src/tests.rs#test:{line}")];
    let assertions = case["attributes"]["assertions"].as_array().unwrap();
    assertions.last().unwrap()["operands"][0].clone()
}

#[test]
fn a_fake_records_its_impl_members_and_edge() {
    let tests = format!(
        "{FAKE}#[test]\nfn uses() {{\n    assert_eq!(crate::elapsed(&FakeClock), 42);\n}}\n"
    );
    let contributions = scan("fake-members", &[("src/tests.rs", &tests)]);
    let attributes = &doubles(&contributions)[DOUBLE]["attributes"];

    assert_eq!("hand-written", attributes["framework"]);
    assert_eq!("impl", attributes["pattern"]);
    assert_eq!(true, attributes["compile_checked"]);
    assert_eq!(json!({"kind": "class"}), attributes["target"]);
    assert_eq!(Value::Null, attributes["test_case"]);
    assert_eq!(
        json!([{"name": "now", "line": 4, "api": "impl", "returns": [
            {"api": "impl", "line": 4, "once": false, "assignable": "unknown", "typed": false}
        ]}]),
        attributes["members"]
    );
    // `&FakeClock` hands it on.
    assert_eq!(1, attributes["invocations"]);
    assert_eq!(
        vec![(
            format!("rust:test_double:{DOUBLE}"),
            "rust:interface:crate::Clock".to_owned()
        )],
        edges_of(&contributions, "doubles")
    );
    assert!(edges_of(&contributions, "contains").contains(&(
        "rust:module:crate::tests".to_owned(),
        format!("rust:test_double:{DOUBLE}")
    )));
}

#[test]
fn a_fake_echo_is_mock_config() {
    let tests = format!(
        "{FAKE}#[test]\nfn direct() {{\n    assert_eq!(FakeClock.now(), 42);\n}}\n\
         #[test]\nfn bound() {{\n    let clock = FakeClock;\n    assert_eq!(clock.now(), 42);\n}}\n\
         #[test]\nfn moved() {{\n    let clock = FakeClock;\n    let _ = crate::elapsed(&clock);\n    \
         drop(clock);\n    assert_eq!(clock.now(), 42);\n}}\n"
    );
    let contributions = scan("fake-echo", &[("src/tests.rs", &tests)]);
    let echo = json!({"source": "mock-config", "double": DOUBLE, "value": "42"});

    assert_eq!(echo, first_operand(&contributions, 9));
    assert_eq!(echo, first_operand(&contributions, 13));
    // A move hands the handle to code that may do anything with it.
    assert_eq!(
        json!({"source": "mock-config", "double": DOUBLE}),
        first_operand(&contributions, 18)
    );
    // `FakeClock.now()`, `clock.now()`, `&clock`, `clock` moved, `clock.now()`.
    assert_eq!(
        5,
        doubles(&contributions)[DOUBLE]["attributes"]["invocations"]
    );
    let cases = nodes_of(&contributions, "test_case");
    assert_eq!(
        0,
        cases["src/tests.rs#test:9"]["attributes"]["production_calls"]
    );
}

/// One way a fake may lose its pin: why, the test file, and other files.
type Variant<'a> = (&'a str, String, Vec<(&'a str, &'a str)>);

#[test]
fn fake_pins_need_every_condition() {
    let variants: [Variant<'_>; 23] = [
        (
            "a pub fake",
            FAKE.replace("struct FakeClock", "pub(crate) struct FakeClock"),
            vec![],
        ),
        ("a child module", format!("{FAKE}mod more {{}}\n"), vec![]),
        (
            "an impl in another file",
            format!("{FAKE}mod more;\n"),
            vec![(
                "src/tests/more.rs",
                "impl super::FakeClock {\n    pub fn now(&self) -> u64 {\n        7\n    }\n}\n",
            )],
        ),
        (
            "an inherent now beside the trait's",
            format!("{FAKE}impl FakeClock {{\n    fn now(&self) -> u64 {{\n        7\n    }}\n}}\n"),
            vec![],
        ),
        (
            "an inherent now inside a function",
            format!(
                "{FAKE}fn helper() {{\n    impl FakeClock {{\n        fn now(&self) -> u64 {{\n            \
                 7\n        }}\n    }}\n}}\n"
            ),
            vec![],
        ),
        (
            "an item macro naming the fake",
            format!("{FAKE}macro_rules! more {{\n    () => {{ impl FakeClock {{}} }};\n}}\n"),
            vec![],
        ),
        (
            "a body reading self.n",
            FAKE.replace("struct FakeClock;", "struct FakeClock {\n    n: u64,\n}")
                .replace("        42\n", "        self.n\n"),
            vec![],
        ),
        (
            "a cfg on the method",
            FAKE.replace("    fn now", "    #[cfg(test)]\n    fn now"),
            vec![],
        ),
        (
            "a derive of another crate",
            FAKE.replace("struct FakeClock;", "#[derive(Later)]\nstruct FakeClock;"),
            vec![],
        ),
        (
            "an impl for a projection of the fake",
            format!(
                "{FAKE}trait Proj {{\n    type Out;\n}}\nimpl Proj for FakeClock {{\n    type Out = FakeClock;\n}}\n\
                 impl Later for <FakeClock as Proj>::Out {{\n    fn now(&self) -> u64 {{\n        7\n    }}\n}}\n"
            ),
            vec![],
        ),
        (
            "an inherent now through a type alias",
            format!("{FAKE}type F = FakeClock;\nimpl F {{\n    fn now(&self) -> u64 {{\n        7\n    }}\n}}\n"),
            vec![],
        ),
        (
            "an inherent now through a use rename",
            format!(
                "{FAKE}use self::FakeClock as G;\nimpl G {{\n    fn now(&self) -> u64 {{\n        7\n    }}\n}}\n"
            ),
            vec![],
        ),
        (
            "an alias named like another type",
            format!(
                "{FAKE}fn helper() {{\n    struct F;\n}}\ntype F = FakeClock;\n\
                 impl F {{\n    fn now(&self) -> u64 {{\n        7\n    }}\n}}\n"
            ),
            vec![],
        ),
        (
            "a rename named like another type",
            format!(
                "{FAKE}fn helper() {{\n    struct G;\n}}\nuse self::FakeClock as G;\n\
                 impl G {{\n    fn now(&self) -> u64 {{\n        7\n    }}\n}}\n"
            ),
            vec![],
        ),
        (
            "an import inside a body",
            format!("{FAKE}fn helper() {{\n    use crate::ext::Take;\n}}\n"),
            vec![],
        ),
        (
            "a same-file trait declaring the member's name",
            format!("{FAKE}trait Take {{\n    fn now(self) -> u64;\n}}\n"),
            vec![],
        ),
        (
            "an import of another file's trait",
            format!("{FAKE}use crate::ext::Take;\n"),
            vec![(
                "src/ext.rs",
                "pub trait Take {\n    fn now(self) -> u64;\n}\nimpl<T: crate::Clock> Take for T {\n    \
                 fn now(self) -> u64 {\n        43\n    }\n}\n",
            )],
        ),
        (
            "a glob from another file",
            format!("{FAKE}use crate::ext::*;\n"),
            vec![("src/ext.rs", "pub fn unrelated() {}\n")],
        ),
        (
            "a cfg alternative type alias",
            format!("{FAKE}#[cfg(not(test))]\ntype FakeClock = u8;\n"),
            vec![],
        ),
        (
            "a cfg alternative union",
            format!("{FAKE}#[cfg(not(test))]\nunion FakeClock {{\n    a: u8,\n}}\n"),
            vec![],
        ),
        (
            "a macro inside an impl",
            format!("{FAKE}impl FakeClock {{\n    more!();\n}}\n"),
            vec![],
        ),
        (
            "a statement macro that may declare items",
            format!("{FAKE}fn helper() {{\n    more!();\n}}\n"),
            vec![],
        ),
        (
            "an expression macro handed the fake",
            format!("{FAKE}fn helper() -> Vec<FakeClock> {{\n    vec![FakeClock]\n}}\n"),
            vec![],
        ),
    ];
    for (index, (why, fake, more)) in variants.iter().enumerate() {
        let value = if fake.contains("n: u64") {
            "FakeClock { n: 42 }"
        } else {
            "FakeClock"
        };
        let tests = format!(
            "{fake}#[test]\nfn echo() {{\n    let clock = {value};\n    assert_eq!(clock.now(), 42);\n}}\n"
        );
        let mut files = vec![("src/tests.rs", tests.as_str())];
        files.extend(more.iter().copied());
        let contributions = scan(&format!("fake-pins-{index}"), &files);
        let attributes = doubles(&contributions)
            .into_iter()
            .find(|(_, node)| node["attributes"]["members"][0]["name"] == "now")
            .unwrap_or_else(|| panic!("{why}: no double"))
            .1["attributes"]
            .clone();
        assert_eq!(json!([]), attributes["members"][0]["returns"], "{why}");
        let cases = nodes_of(&contributions, "test_case");
        let case = cases
            .values()
            .find(|case| case["attributes"]["name"] == "echo")
            .unwrap_or_else(|| panic!("{why}: no case"));
        let operand = &case["attributes"]["assertions"][0]["operands"][0];
        assert_eq!("mock-config", operand["source"], "{why}");
        assert_eq!(Value::Null, operand["value"], "{why}");
    }
}

#[test]
fn a_fake_with_every_condition_pins() {
    // The control of `fake_pins_need_every_condition`: inert attributes,
    // `return 42;`, and other types' impls and macros leave the pin.
    let fake =
        "use crate::Clock;\n#[derive(Clone, Default)]\n#[allow(dead_code)]\nstruct FakeClock;\n\
        impl Clock for FakeClock {\n    fn now(&self) -> u64 {\n        return 42;\n    }\n}\n\
        struct Other;\nimpl Other {\n    fn now(&self) -> u64 {\n        7\n    }\n}\n\
        macro_rules! unrelated {\n    () => {};\n}\n\
        enum Mood {}\nimpl Mood {\n    fn now(&self) -> u64 {\n        7\n    }\n}\n\
        union Bits {\n    a: u8,\n}\nimpl Bits {\n    fn now(&self) -> u64 {\n        7\n    }\n}\n\
        use std::fmt::Debug;\nuse std::collections::*;\n";
    let tests = format!(
        "{fake}#[test]\nfn echo() {{\n    use std::cell::Cell;\n    let clock = FakeClock::default();\n    \
         assert_eq!(clock.now(), 42);\n}}\n"
    );
    let contributions = scan("fake-control", &[("src/tests.rs", &tests)]);

    let attributes = &doubles(&contributions)["src/tests.rs#double:5:1"]["attributes"];
    assert_eq!("impl", attributes["members"][0]["returns"][0]["api"]);
    assert_eq!(
        json!({"source": "mock-config", "double": "src/tests.rs#double:5:1", "value": "42"}),
        first_operand(&contributions, 36)
    );
}

#[test]
fn a_constructor_makes_a_handle_when_it_returns_the_fake() {
    let made = |returned: &str, body: &str| {
        format!(
            "{FAKE}impl FakeClock {{\n    fn new() -> {returned} {{\n        {body}\n    }}\n}}\n\
             #[test]\nfn echo() {{\n    let clock = FakeClock::new();\n    assert_eq!(clock.now(), 42);\n}}\n"
        )
    };
    let own = scan(
        "fake-new-self",
        &[("src/tests.rs", &made("Self", "FakeClock"))],
    );
    let named = scan(
        "fake-new-named",
        &[("src/tests.rs", &made("FakeClock", "FakeClock"))],
    );
    let boxed = scan(
        "fake-new-boxed",
        &[(
            "src/tests.rs",
            &made("Box<dyn Clock>", "Box::new(FakeClock)"),
        )],
    );

    let echo = json!({"source": "mock-config", "double": DOUBLE, "value": "42"});
    assert_eq!(echo, first_operand(&own, 14));
    assert_eq!(echo, first_operand(&named, 14));
    // A `Box<dyn Clock>` may hold any clock.
    assert_eq!(json!({"source": "unknown"}), first_operand(&boxed, 14));
}

#[test]
fn default_makes_a_handle_when_the_file_gives_the_fake_one() {
    let made = |name: &str, extra: &str| {
        let tests = format!(
            "{FAKE}{extra}#[test]\nfn echo() {{\n    let clock = FakeClock::default();\n    \
             assert_eq!(clock.now(), 42);\n}}\n"
        );
        let contributions = scan(name, &[("src/tests.rs", &tests)]);
        let cases = nodes_of(&contributions, "test_case");
        let case = cases.values().next().unwrap();
        case["attributes"]["assertions"][0]["operands"][0].clone()
    };
    let echo = json!({"source": "mock-config", "double": DOUBLE, "value": "42"});

    let implemented =
        "impl Default for FakeClock {\n    fn default() -> Self {\n        FakeClock\n    }\n}\n";
    let inherent = "impl FakeClock {\n    fn default() -> Self {\n        FakeClock\n    }\n}\n";
    assert_eq!(echo, made("fake-default-trait", implemented));
    assert_eq!(echo, made("fake-default-inherent", inherent));
    // Nothing the file shows makes `default` return the fake.
    assert_eq!(json!({"source": "unknown"}), made("fake-default-none", ""));
}

#[test]
fn each_trait_impl_is_its_own_double() {
    let lib = format!("{LIB}pub trait Named {{\n    fn name(&self) -> &'static str;\n}}\n");
    let tests = format!(
        "{FAKE}use crate::Named;\nimpl Named for FakeClock {{\n    fn name(&self) -> &'static str {{\n        \"fake\"\n    }}\n}}\n\
         #[test]\nfn echo() {{\n    assert_eq!(FakeClock.name(), \"fake\");\n}}\n"
    );
    let contributions = scan_fixture_with(
        "fake-two-traits",
        &[
            ("Cargo.toml", "[package]\nname = \"probe\"\n"),
            ("src/lib.rs", &lib),
            ("src/tests.rs", &tests),
        ],
        &json!({ "config_files": ["Cargo.toml"] }),
    );

    assert_eq!(
        vec![
            (
                format!("rust:test_double:{DOUBLE}"),
                "rust:interface:crate::Clock".to_owned()
            ),
            (
                "rust:test_double:src/tests.rs#double:9:1".to_owned(),
                "rust:interface:crate::Named".to_owned()
            ),
        ],
        edges_of(&contributions, "doubles")
    );
    // The member's own impl answers, and both doubles share the handles;
    // `Clock`, imported from another file, may declare a `name` of its own.
    assert_eq!(
        json!({"source": "mock-config", "double": "src/tests.rs#double:9:1"}),
        first_operand(&contributions, 15)
    );
    assert_eq!(
        1,
        doubles(&contributions)[DOUBLE]["attributes"]["invocations"]
    );
}

#[test]
fn a_fake_is_a_handle_only_in_its_own_module_and_unhidden() {
    let tests = format!(
        "{FAKE}#[test]\nfn hidden() {{\n    struct FakeClock;\n    impl FakeClock {{\n        \
         fn now(&self) -> u64 {{\n            7\n        }}\n    }}\n    assert_eq!(FakeClock.now(), 42);\n}}\n\
         impl FakeClock {{\n    fn new() -> Self {{\n        FakeClock\n    }}\n}}\n\
         #[test]\nfn qualified() {{\n    assert_eq!(<Other>::FakeClock.now(), 42);\n    \
         let a = <Other>::FakeClock {{}};\n    assert_eq!(a.now(), 42);\n    \
         let ref b = FakeClock;\n    assert_eq!(b.now(), 42);\n    \
         let c = <Q>::FakeClock::new();\n    assert_eq!(c.now(), 42);\n    \
         let d = ::FakeClock::new();\n    assert_eq!(d.now(), 42);\n    \
         let e = FakeClock::<u8>::new();\n    assert_eq!(e.now(), 42);\n    \
         let f = FakeClock::new::<u8>();\n    assert_eq!(f.now(), 42);\n}}\n\
         mod inner {{\n    #[test]\n    fn child() {{\n        assert_eq!(super::FakeClock.now(), 42);\n        \
         assert_eq!(FakeClock.now(), 42);\n    }}\n}}\n"
    );
    let contributions = scan("fake-scopes", &[("src/tests.rs", &tests)]);
    let cases = nodes_of(&contributions, "test_case");
    let first = |name: &str| {
        let case = cases
            .values()
            .find(|case| case["attributes"]["name"] == name)
            .unwrap();
        case["attributes"]["assertions"]
            .as_array()
            .unwrap()
            .iter()
            .map(|assertion| assertion["operands"][0]["source"].clone())
            .collect::<Vec<_>>()
    };

    assert_eq!(vec![json!("unknown")], first("hidden"));
    assert_eq!(
        vec![json!("unknown"); 7],
        first("qualified"),
        "a qualified, a `ref` or a generic spelling is no handle"
    );
    assert_eq!(
        vec![json!("unknown"), json!("unknown")],
        first("inner::child")
    );
}

#[test]
fn an_inline_module_and_an_enum_hold_fakes() {
    let tests = "mod inner {\n    use crate::Clock;\n    enum Fixed {\n        Noon,\n    }\n    \
        impl Clock for Fixed {\n        fn now(&self) -> u64 {\n            12\n        }\n    }\n    \
        #[test]\n    fn t() {\n        assert_eq!(crate::elapsed(&Fixed::Noon), 12);\n    }\n}\n";
    let contributions = scan("fake-inline", &[("src/tests.rs", tests)]);

    let attributes = &doubles(&contributions)["src/tests.rs#double:6:5"]["attributes"];
    assert_eq!("now", attributes["members"][0]["name"]);
    assert_eq!(
        1,
        attributes["members"][0]["returns"]
            .as_array()
            .unwrap()
            .len()
    );
    assert!(edges_of(&contributions, "contains").contains(&(
        "rust:module:crate::tests::inner".to_owned(),
        "rust:test_double:src/tests.rs#double:6:5".to_owned()
    )));
}

#[test]
fn an_external_trait_impl_is_no_fake() {
    let tests = "struct Fake;\nimpl std::fmt::Display for Fake {\n    \
        fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {\n        \
        write!(f, \"fake\")\n    }\n}\n#[test]\nfn shows() {\n    assert_eq!(Fake.to_string(), \"fake\");\n}\n";
    let contributions = scan("fake-external", &[("src/tests.rs", tests)]);

    assert!(doubles(&contributions).is_empty());
}

#[test]
fn a_production_impl_is_no_fake() {
    // A type outside test code implementing the trait is production's.
    let lib = format!("{LIB}pub struct System;\nimpl Clock for System {{\n    fn now(&self) -> u64 {{\n        1\n    }}\n}}\n");
    let contributions = scan_fixture_with(
        "fake-production",
        &[
            ("Cargo.toml", "[package]\nname = \"probe\"\n"),
            ("src/lib.rs", &lib),
            (
                "src/tests.rs",
                "#[test]\nfn t() {\n    assert_eq!(crate::System.now(), 1);\n}\n",
            ),
        ],
        &json!({ "config_files": ["Cargo.toml"] }),
    );

    assert!(doubles(&contributions).is_empty());
}

#[test]
fn a_fake_in_an_integration_test_is_test_code() {
    let it = "use probe::Clock;\nstruct Fixed;\nimpl Clock for Fixed {\n    fn now(&self) -> u64 {\n        \
        1\n    }\n}\n#[test]\nfn t() {\n    assert_eq!(Fixed.now(), 1);\n}\n";
    let contributions = scan("fake-integration", &[("tests/it.rs", it)]);

    let attributes = &doubles(&contributions)["tests/it.rs#double:3:1"]["attributes"];
    assert_eq!("hand-written", attributes["framework"]);
    assert_eq!(
        1,
        attributes["members"][0]["returns"]
            .as_array()
            .unwrap()
            .len()
    );
}
