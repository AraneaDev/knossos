//! Scanning tests of the mocking crates besides mockall (Decision 2), ported
//! from Momus's `mocks.test.ts`: faux, mry, mockers, mockiato, mocktopus,
//! mock_derive and galvanic. Each double carries its crate's framework,
//! pattern and member api, and pins by its crate's value verbs; a call on a
//! handle is `mock-config`, with a value only where Decision 9 holds.

mod common;

use common::frameworks::*;
use common::*;
use serde_json::{json, Value};

#[test]
fn faux_when_then_return() {
    let source = "#[faux::create]\npub struct Foo {\n    a: u32,\n}\n#[faux::methods]\nimpl Foo {\n    \
        pub fn get_stuff(&self) -> u32 {\n        self.a\n    }\n    \
        pub fn two_args(&self, a: i32, b: i32) -> i32 {\n        a + b\n    }\n}\n\
        #[test]\nfn echo() {\n    let mut mock = Foo::faux();\n    faux::when!(mock.get_stuff).then_return(10);\n    \
        assert_eq!(mock.get_stuff(), 10);\n}\n\
        #[test]\nfn closure() {\n    let mut mock = Foo::faux();\n    faux::when!(mock.get_stuff).then(|_| 20);\n    \
        assert_eq!(mock.get_stuff(), 20);\n}\n\
        #[test]\nfn args() {\n    let mut mock = Foo::faux();\n    faux::when!(mock.two_args(_, 4)).then_return(10);\n    \
        assert_eq!(mock.two_args(1, 4), 10);\n}\n";
    let contributions = scan("faux", source);

    let echo = double(&contributions, "src/lib.rs#double:16:20");
    assert_eq!("faux", echo["framework"]);
    assert_eq!("faux::create", echo["pattern"]);
    assert_eq!(true, echo["compile_checked"]);
    assert_eq!(json!({"kind": "class"}), echo["target"]);
    assert_eq!(
        expected(&[("get_stuff", "when", &["then_return"])]),
        members(&echo)
    );
    assert_eq!(1, echo["invocations"]);
    assert_eq!(
        Some("rust:class:crate::Foo".to_owned()),
        target(&contributions, "16:20")
    );
    assert_eq!(
        json!({"source": "mock-config", "double": "src/lib.rs#double:16:20", "value": "10"}),
        operands(&contributions, 15)[0][0]
    );
    let cases = nodes_of(&contributions, "test_case");
    assert_eq!(
        0,
        cases["src/lib.rs#test:15"]["attributes"]["production_calls"]
    );
    // `then(|_| …)` answers with a closure: no pin, no value.
    let closure = double(&contributions, "src/lib.rs#double:22:20");
    assert_eq!(expected(&[("get_stuff", "when", &[])]), members(&closure));
    assert_eq!(
        json!({"source": "mock-config", "double": "src/lib.rs#double:22:20"}),
        operands(&contributions, 21)[0][0]
    );
    // The member is the method's name, not its matchers.
    let args = double(&contributions, "src/lib.rs#double:28:20");
    assert_eq!(
        expected(&[("two_args", "when", &["then_return"])]),
        members(&args)
    );
}

#[test]
fn faux_when_needs_fauxs_macro() {
    // A bare `when!` the file does not import from faux may be anything:
    // it hands the mock on.
    let source = "#[faux::create]\npub struct Foo {\n    a: u32,\n}\n\
        #[test]\nfn bare() {\n    let mut mock = Foo::faux();\n    when!(mock.get).then_return(10);\n    \
        assert_eq!(mock.get(), 10);\n}\n\
        mod imported {\n    use faux::when;\n    #[test]\n    fn imported() {\n        \
        let mut mock = super::Foo::faux();\n        when!(mock.get).then_return(10);\n        \
        assert_eq!(mock.get(), 10);\n    }\n}\n";
    let contributions = scan("faux-bare", source);

    assert_eq!(
        Value::Array(vec![]),
        double(&contributions, "src/lib.rs#double:7:20")["members"]
    );
    assert_eq!(
        json!({"source": "mock-call", "double": "src/lib.rs#double:7:20"}),
        operands(&contributions, 6)[0][0]
    );
    assert_eq!(
        json!({"source": "mock-config", "double": "src/lib.rs#double:15:24", "value": "10"}),
        operands(&contributions, 14)[0][0]
    );
}

#[test]
fn mry_mock_method_returns() {
    let source = "#[mry::mry]\n#[derive(Default)]\npub struct Cat {\n    name: String,\n}\n\
        #[mry::mry]\nimpl Cat {\n    pub fn meow(&self, count: usize) -> usize {\n        count\n    }\n    \
        pub fn purr() -> usize {\n        1\n    }\n}\n\
        #[mry::mry]\npub trait Dog {\n    fn bark(&self) -> usize;\n}\n\
        #[test]\nfn echo() {\n    let mut cat = mry::new!(Cat { name: String::new() });\n    \
        cat.mock_meow(mry::Any).returns(42);\n    assert_eq!(cat.meow(2), 42);\n}\n\
        #[test]\nfn matcher() {\n    let mut cat = Cat { name: String::new(), ..Default::default() };\n    \
        cat.mock_meow(2).returns(42);\n    assert_eq!(cat.meow(2), 42);\n}\n\
        #[test]\nfn statics() {\n    Cat::mock_purr().returns(5);\n    assert_eq!(Cat::purr(), 5);\n    \
        Cat::mock_purr().assert_called(1);\n}\n\
        #[test]\nfn trait_mock() {\n    let mut dog = MockDog::default();\n    dog.mock_bark().returns(3);\n    \
        assert_eq!(dog.bark(), 3);\n}\n";
    let contributions = scan("mry", source);

    let echo = double(&contributions, "src/lib.rs#double:21:19");
    assert_eq!("mry", echo["framework"]);
    assert_eq!("mry::mry", echo["pattern"]);
    assert_eq!(expected(&[("meow", "mock", &["returns"])]), members(&echo));
    assert_eq!(
        Some("rust:class:crate::Cat".to_owned()),
        target(&contributions, "21:19")
    );
    assert_eq!(
        json!({"source": "mock-config", "double": "src/lib.rs#double:21:19", "value": "42"}),
        operands(&contributions, 20)[0][0]
    );
    // A matcher other than `mry::Any` leaves other calls to the real method.
    assert_eq!(
        json!({"source": "mock-config", "double": "src/lib.rs#double:27:19"}),
        operands(&contributions, 26)[0][0]
    );
    // A static mock is every test's: identity, its pin, and the call that
    // reaches it, never a value.
    let statics = double(&contributions, "src/lib.rs#double:33:5");
    assert_eq!(
        expected(&[("purr", "mock", &["returns"])]),
        members(&statics)
    );
    assert_eq!(1, statics["invocations"]);
    assert_eq!(
        vec![
            json!([{"source": "production"}, {"source": "literal", "text": "5"}]),
            json!([
                {"source": "mock-call", "double": "src/lib.rs#double:33:5"}
            ])
        ],
        operands(&contributions, 32)
    );
    assert_eq!(
        Some("rust:interface:crate::Dog".to_owned()),
        target(&contributions, "39:19")
    );
    assert_eq!(
        json!({"source": "mock-config", "double": "src/lib.rs#double:39:19", "value": "3"}),
        operands(&contributions, 38)[0][0]
    );
}

#[test]
fn mockers_expect_and_return() {
    let source = "use mockers_derive::mocked;\n#[mocked]\npub trait A {\n    fn baz(&self) -> u32;\n    \
        fn bar(&self, arg: u32);\n}\n\
        #[test]\nfn expect() {\n    let scenario = mockers::Scenario::new();\n    \
        let (mock, handle) = scenario.create_mock_for::<dyn A>();\n    \
        scenario.expect(handle.baz().and_return(2));\n    scenario.expect(handle.bar(3).and_return(()));\n    \
        assert_eq!(2, mock.baz());\n}\n\
        #[test]\nfn by_type() {\n    let scenario = mockers::Scenario::new();\n    \
        let (mock, handle) = scenario.create_mock::<AMock>();\n    \
        scenario.expect(handle.baz().and_return(2));\n    assert_eq!(2, mock.baz());\n}\n";
    let contributions = scan("mockers", source);

    let mock = double(&contributions, "src/lib.rs#double:10:26");
    assert_eq!("mockers", mock["framework"]);
    assert_eq!("mockers::Scenario::create_mock", mock["pattern"]);
    assert_eq!(
        expected(&[
            ("baz", "expect", &["and_return"]),
            ("bar", "expect", &["and_return"])
        ]),
        members(&mock)
    );
    assert_eq!(1, mock["invocations"]);
    assert_eq!(
        Some("rust:interface:crate::A".to_owned()),
        target(&contributions, "10:26")
    );
    // The scenario and the handle configure it: never a value.
    assert_eq!(
        json!({"source": "mock-config", "double": "src/lib.rs#double:10:26"}),
        operands(&contributions, 8)[0][1]
    );
    assert_eq!(
        Some("rust:interface:crate::A".to_owned()),
        target(&contributions, "18:26")
    );

    // A `ref` binding is no handle: the call on it is no member of the mock.
    let by_ref =
        "use mockers_derive::mocked;\n#[mocked]\npub trait A {\n    fn baz(&self) -> u32;\n}\n\
        #[test]\nfn t() {\n    let scenario = mockers::Scenario::new();\n    \
        let (ref mock, ref handle) = scenario.create_mock_for::<dyn A>();\n    \
        scenario.expect(handle.baz().and_return(2));\n    assert_eq!(2, mock.baz());\n}\n";
    let contributions = scan("mockers-ref", by_ref);
    assert_eq!(
        json!({"source": "unknown"}),
        operands(&contributions, 7)[0][1]
    );
    assert_eq!(
        json!([]),
        double(&contributions, "src/lib.rs#double:9:34")["members"]
    );
}

#[test]
fn mockiato_expect_returns() {
    let source = "use mockiato::mockable;\n#[mockable]\npub trait Greeter {\n    \
        fn greet(&self, name: &str) -> String;\n}\n\
        #[test]\nfn echo() {\n    let mut greeter = GreeterMock::new();\n    \
        greeter.expect_greet(|arg| arg.partial_eq(\"world\")).returns(\"Hello world\");\n    \
        assert_eq!(\"Hello world\", greeter.greet(\"world\"));\n}\n\
        #[test]\nfn in_order() {\n    let mut greeter = GreeterMock::default();\n    \
        greeter.expect_greet_calls_in_order(|arg| arg.any()).returns_once(\"a\").times(1);\n    \
        assert_eq!(\"a\", greeter.greet(\"world\"));\n}\n\
        #[test]\nfn by_hand() {\n    let greeter = ByHandMock::new();\n    assert_eq!(\"a\", greeter.greet(\"world\"));\n}\n";
    let contributions = scan("mockiato", source);

    let echo = double(&contributions, "src/lib.rs#double:8:23");
    assert_eq!("mockiato", echo["framework"]);
    assert_eq!("mockiato::mockable", echo["pattern"]);
    assert_eq!(
        expected(&[("greet", "expect", &["returns"])]),
        members(&echo)
    );
    assert_eq!(
        Some("rust:interface:crate::Greeter".to_owned()),
        target(&contributions, "8:23")
    );
    assert_eq!(
        json!({"source": "mock-config", "double": "src/lib.rs#double:8:23", "value": "\"Hello world\""}),
        operands(&contributions, 7)[0][1]
    );
    let in_order = double(&contributions, "src/lib.rs#double:14:23");
    assert_eq!(
        expected(&[("greet", "expect", &["returns_once"])]),
        members(&in_order)
    );
    assert_eq!(true, in_order["members"][0]["returns"][0]["once"]);
    // `times` asserts on the double.
    assert_eq!(
        json!([{"source": "mock-call", "double": "src/lib.rs#double:14:23"}]),
        operands(&contributions, 13)[0]
    );
    // `ByHandMock` has no generator in the file: it may be a hand-written type.
    assert_eq!(2, nodes_of(&contributions, "test_double").len());
}

#[test]
fn mocktopus_mock_safe_return() {
    let contributions = scan_repository_fixture("test-fidelity/rust/momus-drift");
    let doubles = nodes_of(&contributions, "test_double");
    let node = &doubles["mocktopus_test.rs#double:12:13"];
    let attributes = &node["attributes"];

    assert_eq!("mocktopus", attributes["framework"]);
    assert_eq!("mocktopus::mock_safe", attributes["pattern"]);
    assert_eq!(json!({"kind": "global"}), attributes["target"]);
    assert_eq!(true, attributes["compile_checked"]);
    assert_eq!("mocktopus_test.rs#test:10", attributes["test_case"]);
    assert_eq!(0, attributes["invocations"]);
    assert_eq!(
        expected(&[("global_fetch", "mock_fn", &["MockResult::Return"])]),
        members(attributes)
    );
    let cases = nodes_of(&contributions, "test_case");
    assert_eq!(
        0,
        cases["mocktopus_test.rs#test:10"]["attributes"]["production_calls"]
    );
}

#[test]
fn mocktopus_gives_identity_only() {
    let source =
        "#[mocktopus::macros::mockable]\npub fn world() -> &'static str {\n    \"world\"\n}\n\
        #[test]\nfn replaced() {\n    world.mock_safe(|| MockResult::Return(\"mocking\"));\n    \
        assert_eq!(\"mocking\", world());\n}\n";
    let contributions = scan("mocktopus", source);

    // The call of the mocked function invokes the double, and is never
    // `mock-config`.
    assert_eq!(
        1,
        double(&contributions, "src/lib.rs#double:7:5")["invocations"]
    );
    assert_eq!("production", operands(&contributions, 6)[0][1]["source"]);
}

#[test]
fn mock_derive_method_set_result() {
    let source = "use mock_derive::mock;\n#[mock]\npub trait CustomTrait {\n    fn get_int(&self) -> u32;\n}\n\
        #[mock]\nextern \"C\" {\n    pub fn c_double(x: isize) -> isize;\n}\n\
        #[test]\nfn set_result() {\n    let mut mock = MockCustomTrait::new();\n    \
        let method = mock.method_get_int().first_call().set_result(3);\n    mock.set_get_int(method);\n    \
        assert_eq!(3, mock.get_int());\n}\n\
        #[test]\nfn result_of() {\n    let mut mock = MockCustomTrait::new();\n    \
        mock.set_get_int(mock.method_get_int().return_result_of(|| 10));\n    assert_eq!(10, mock.get_int());\n}\n\
        #[test]\nfn external() {\n    let mock = ExternCMocks::method_c_double().first_call().set_result(2);\n    \
        ExternCMocks::set_c_double(mock);\n    unsafe {\n        assert_eq!(2, c_double(1));\n    }\n}\n";
    let contributions = scan("mock-derive", source);

    let mock = double(&contributions, "src/lib.rs#double:12:20");
    assert_eq!("mock_derive", mock["framework"]);
    assert_eq!("mock_derive::mock", mock["pattern"]);
    assert_eq!(
        expected(&[("get_int", "method", &["set_result"])]),
        members(&mock)
    );
    // `set_get_int` hands the configuration over: no call.
    assert_eq!(1, mock["invocations"]);
    assert_eq!(
        Some("rust:interface:crate::CustomTrait".to_owned()),
        target(&contributions, "12:20")
    );
    // The configuration is a value of its own, answered by call number.
    assert_eq!(
        json!({"source": "mock-config", "double": "src/lib.rs#double:12:20"}),
        operands(&contributions, 11)[0][1]
    );
    let result_of = double(&contributions, "src/lib.rs#double:19:20");
    assert_eq!(
        expected(&[("get_int", "method", &["return_result_of"])]),
        members(&result_of)
    );
    let external = double(&contributions, "src/lib.rs#double:25:16");
    assert_eq!(
        expected(&[("c_double", "method", &["set_result"])]),
        members(&external)
    );
    assert_eq!(1, external["invocations"]);
    assert_eq!(None, target(&contributions, "25:16"));
}

#[test]
fn galvanic_given_then_return() {
    let source = "use galvanic_mock::{mockable, use_mocks};\n#[mockable]\npub trait TestTrait {\n    \
        fn func(&self) -> usize;\n}\n\
        #[test]\n#[use_mocks]\nfn given() {\n    let mock = new_mock!(TestTrait);\n    given! {\n        \
        <mock as TestTrait>::func() then_return 42usize always;\n    }\n    expect_interactions! {\n        \
        <mock as TestTrait>::func() times 1;\n    }\n    assert_eq!(42usize, mock.func());\n}\n\
        #[test]\n#[use_mocks]\nfn generic() {\n    let x = new_mock!(TestTrait<i32, Assoc = String>);\n    \
        given! {\n        <x as TestTrait<i32, Assoc = String>>::func(|&a| a < 2) then_return_from |_| 2 always;\n    }\n    \
        assert!(x.func(1) == 2);\n}\n";
    let contributions = scan("galvanic", source);

    let mock = double(&contributions, "src/lib.rs#double:9:16");
    assert_eq!("galvanic", mock["framework"]);
    assert_eq!("galvanic_mock::mockable", mock["pattern"]);
    assert_eq!(
        expected(&[("func", "given", &["then_return"])]),
        members(&mock)
    );
    assert_eq!(1, mock["invocations"]);
    assert_eq!(
        Some("rust:interface:crate::TestTrait".to_owned()),
        target(&contributions, "9:16")
    );
    // `expect_interactions!` asserts; the `given!` macro hides the value.
    assert_eq!(
        vec![
            json!([{"source": "mock-call", "double": "src/lib.rs#double:9:16"}]),
            json!([{"source": "literal", "text": "42"}, {"source": "mock-config", "double": "src/lib.rs#double:9:16"}]),
        ],
        operands(&contributions, 8)
    );
    let generic = double(&contributions, "src/lib.rs#double:21:13");
    assert_eq!(expected(&[("func", "given", &[])]), members(&generic));
}

#[test]
fn frameworks_never_claim_each_others_constructors() {
    let cases: [(&str, &str, &[&str]); 6] = [
        (
            "a mry trait's new is its mocked function",
            "#[mry::mry]\npub trait Cat {\n    fn new(name: String) -> Self;\n}\n\
             #[test]\nfn t() {\n    let cat = MockCat::new(\"Tama\".into());\n}\n",
            &[],
        ),
        (
            "faux's constructor",
            "#[faux::create]\npub struct Foo {\n    a: u32,\n}\n\
             #[test]\nfn t() {\n    let mut mock = Foo::faux();\n    faux::when!(mock).then(|_| ());\n}\n",
            &["faux"],
        ),
        (
            "mockiato's suffix",
            "use mockiato::mockable;\n#[mockable]\npub trait Greeter {\n    fn greet(&self) -> String;\n}\n\
             #[test]\nfn t() {\n    let mut greeter = GreeterMock::new();\n    greeter.expect_greet().returns(\"hi\".into());\n}\n",
            &["mockiato"],
        ),
        (
            "mock_derive behind a cfg_attr",
            "use mock_derive::mock;\n#[cfg_attr(feature = \"nightly\", mock)]\npub trait CustomTrait {\n    \
             fn get_int(&self) -> u32;\n}\n\
             #[test]\nfn t() {\n    let mut mock = MockCustomTrait::new();\n    \
             mock.set_get_int(mock.method_get_int().first_call().set_result(3));\n}\n",
            &["mock_derive"],
        ),
        (
            "mockall's automock",
            "#[mockall::automock]\npub trait Repo {\n    fn find(&self) -> u32;\n}\n\
             #[test]\nfn t() {\n    let mut m = MockRepo::new();\n    m.expect_find().return_const(1_u32);\n}\n",
            &["mockall"],
        ),
        (
            "galvanic's mockable",
            "use galvanic_mock::mockable;\n#[mockable]\npub trait Tested {\n    fn func(&self) -> usize;\n}\n\
             #[test]\nfn t() {\n    let mock = TestedMock::new();\n    let other = new_mock!(Tested);\n}\n",
            &["galvanic"],
        ),
    ];
    for (index, (why, source, wanted)) in cases.iter().enumerate() {
        let contributions = scan(&format!("frameworks-apart-{index}"), source);
        assert_eq!(
            wanted
                .iter()
                .map(|name| (*name).to_owned())
                .collect::<Vec<_>>(),
            frameworks(&contributions),
            "{why}"
        );
    }
}

#[test]
fn a_mockers_mock_macro_names_its_trait() {
    let source = "pub trait A {\n    fn foo(&self, a: u32);\n}\n\
        mockers::mock! {\n    AMock,\n    self,\n    trait A {\n        fn foo(&self, a: u32);\n    }\n}\n\
        #[test]\nfn t() {\n    let scenario = mockers::Scenario::new();\n    \
        let (mock, handle) = scenario.create_mock::<AMock>();\n    scenario.expect(handle.foo(2).and_return(()));\n}\n";
    let contributions = scan("mockers-macro", source);

    assert_eq!(
        Some("rust:interface:crate::A".to_owned()),
        target(&contributions, "14:26")
    );
}
