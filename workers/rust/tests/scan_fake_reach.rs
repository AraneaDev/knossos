//! Scanning tests of what a test reaches beside its doubles (G5-10, the
//! Task 4 minors): a fake named only as a type is handed on (M4), and a
//! call on a double leaves out only its own call, not a production call of
//! the same name on its line (M3).

mod common;

use common::frameworks::{double, scan};
use common::*;
use serde_json::{json, Value};

/// The library: the trait `Clock` and production code that takes one.
const LIB: &str = "pub trait Clock {\n    fn now(&self) -> u64;\n}\n\
    pub fn elapsed(clock: &dyn Clock) -> u64 {\n    clock.now()\n}\n#[cfg(test)]\nmod tests;\n";

/// Two pinned fakes, `impl` on lines 4 and 11: `Named`, which `tests`
/// names, and `Unnamed`, which nothing names.
const FAKES: &str = "use crate::Clock;\n#[derive(Default)]\nstruct Named;\nimpl Clock for Named {\n    \
    fn now(&self) -> u64 {\n        42\n    }\n}\n#[derive(Default)]\nstruct Unnamed;\nimpl Clock for Unnamed {\n    \
    fn now(&self) -> u64 {\n        7\n    }\n}\nfn read<C: Clock + Default>() -> u64 {\n    C::default().now()\n}\n";

/// The invocations of the two fakes, `Named` then `Unnamed`, when
/// `tests` follows them in `src/tests.rs`.
fn invocations(name: &str, tests: &str) -> (Value, Value) {
    let source = format!("{FAKES}{tests}");
    let contributions = scan_fixture_with(
        name,
        &[
            ("Cargo.toml", "[package]\nname = \"probe\"\n"),
            ("src/lib.rs", LIB),
            ("src/tests.rs", &source),
        ],
        &json!({ "config_files": ["Cargo.toml"] }),
    );
    let doubles = nodes_of(&contributions, "test_double");
    let of = |line: usize| {
        doubles[&format!("src/tests.rs#double:{line}:1")]["attributes"]["invocations"].clone()
    };
    (of(4), of(11))
}

#[test]
fn a_fake_named_only_as_a_type_is_handed_on() {
    // M4: `read::<Named>()` makes and calls a `Named` out of the walk's
    // sight, so it is no zero-reach fake. `Unnamed` still is.
    for tests in [
        "#[test]\nfn turbofish() {\n    assert_eq!(read::<Named>(), 42);\n}\n",
        "#[test]\nfn annotated() {\n    let made: Box<dyn Clock> = Box::new(<Named as Default>::default());\n    \
         assert_eq!(made.now(), 42);\n}\n",
        "#[test]\nfn in_a_type() {\n    let made: Option<Named> = Default::default();\n    \
         assert!(made.is_none());\n}\n",
    ] {
        assert_eq!((json!(1), json!(0)), invocations("reach-type", tests), "{tests}");
    }
    // The type a binding of a fake's value is annotated with hands nothing
    // on: only the call counts.
    let bound = "#[test]\nfn bound() {\n    let clock: Named = Named;\n    assert_eq!(clock.now(), 42);\n}\n";
    assert_eq!((json!(1), json!(0)), invocations("reach-bound", bound));
}

#[test]
fn a_call_on_a_double_leaves_a_production_call_of_its_name_counted() {
    // M3: `mock.get_stuff()` runs the double, but `real.get_stuff()` on
    // the same line runs production.
    let source = "#[faux::create]\npub struct Foo {\n    a: u32,\n}\n#[faux::methods]\nimpl Foo {\n    \
        pub fn get_stuff(&self) -> u32 {\n        self.a\n    }\n}\n\
        #[test]\nfn compared() {\n    let real: Foo = Default::default();\n    let mut mock = Foo::faux();\n    \
        faux::when!(mock.get_stuff).then_return(10);\n    \
        assert_eq!(mock.get_stuff(), real.get_stuff());\n}\n\
        #[test]\nfn alone() {\n    let real: Foo = Default::default();\n    let mut mock = Foo::faux();\n    \
        faux::when!(mock.get_stuff).then_return(10);\n    \
        assert_eq!(mock.get_stuff(), 10);\n    drop(real);\n}\n";
    let contributions = scan("reach-same-line", source);
    let cases = nodes_of(&contributions, "test_case");
    let reach = |line: usize| {
        let attributes = &cases[&format!("src/lib.rs#test:{line}")]["attributes"];
        (
            attributes["production_calls"].clone(),
            attributes["subjects"].clone(),
            attributes["assertions"][0]["operands"][1].clone(),
        )
    };

    // `real.get_stuff()`, once, landing in the file.
    let (calls, subjects, operand) = reach(12);
    assert_eq!((json!(1), json!(["src/lib.rs"])), (calls, subjects));
    assert_eq!("production", operand["source"], "{operand}");
    // The call on the double alone is no production.
    assert_eq!((json!(0), json!([])), {
        let (calls, subjects, _) = reach(19);
        (calls, subjects)
    });
    assert_eq!(
        1,
        double(&contributions, "src/lib.rs#double:14:20")["invocations"]
    );
}
