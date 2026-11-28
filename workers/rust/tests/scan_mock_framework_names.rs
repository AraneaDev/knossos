//! Scanning tests of what keeps a mocking crate's value from a test
//! (Decision 9) and of how its names are found (Decision 2): every
//! defeat, the body's and the module's names, and the bounded hops
//! through imports and globs.

mod common;

use common::frameworks::*;
use common::*;
use serde_json::{json, Value};

#[test]
fn framework_values_need_every_condition() {
    let config = "    faux::when!(mock.get).then_return(10);\n";
    let assert = "    assert_eq!(mock.get(), 10);\n";
    let variants: [(&str, String); 25] = [
        (
            "two configurations",
            format!("{config}    faux::when!(mock.get).then_return(11);\n"),
        ),
        (
            "a configuration in a branch",
            format!("    if true {{\n    {config}    }}\n"),
        ),
        (
            "a configuration after the call",
            format!("{assert}{config}"),
        ),
        (
            "&mut handed to a helper",
            format!("{config}    configure(&mut mock);\n"),
        ),
        (
            "a macro handed the mock",
            format!("{config}    let _ = later!(mock);\n"),
        ),
        ("unsafe", format!("{config}    unsafe {{}}\n")),
        (
            "unsafe beside a later macro",
            format!("{config}    unsafe {{}}\n    println!(\"x\");\n"),
        ),
        (
            "a macro_rules! in the body",
            format!("{config}    macro_rules! m {{\n        () => {{}};\n    }}\n"),
        ),
        (
            "a call of an unconfigured member",
            format!("{config}    let _ = mock.other();\n"),
        ),
        (
            "a closure capturing the mock",
            format!("{config}    let f = || mock.get();\n    let _ = f;\n"),
        ),
        (
            "a second binding",
            format!("{config}    let mock = Foo::faux();\n"),
        ),
        (
            "then beside then_return",
            "    faux::when!(mock.get).then_return(10).then(|_| 10);\n".to_owned(),
        ),
        // A clone may share the mock's stubs, and be configured by `&mut`
        // under another name.
        (
            "a clone through UFCS",
            format!("    let mut other = Foo::clone(&mock);\n    faux::when!(other.get).then(|_| 11);\n{config}"),
        ),
        (
            "a clone through Clone::clone",
            format!("    let other = Clone::clone(&mock);\n    let _ = other;\n{config}"),
        ),
        (
            "a clone of a borrow",
            format!("    let other = (&mock).clone();\n    let _ = other;\n{config}"),
        ),
        // Any borrow handed anywhere but to a member call on the mock may be
        // cloned out of sight.
        (
            "a clone of the mock",
            format!("    let other = mock.clone();\n    let _ = other;\n{config}"),
        ),
        (
            "a member call through a mutable borrow",
            format!("    let _ = (&mut mock).get();\n{config}"),
        ),
        (
            "a borrow handed to a helper",
            format!("    let _ = dup(&mock);\n{config}"),
        ),
        (
            "a borrow handed on in a called closure",
            format!("    let _ = (|| dup(&mock))();\n{config}"),
        ),
        (
            "ToOwned::to_owned",
            format!("    let _ = ToOwned::to_owned(&mock);\n{config}"),
        ),
        (
            "a borrow wrapped in Some",
            format!("    let _ = Some(&mock).cloned();\n{config}"),
        ),
        (
            "a borrow in an array",
            format!("    let _ = [&mock].map(Clone::clone);\n{config}"),
        ),
        (
            "a borrow in a struct field",
            format!("    let h = Holder {{ r: &mock }};\n    let _ = h.r.clone();\n{config}"),
        ),
        (
            "a borrow in a tuple",
            format!("    let pair = (&mock, 1);\n    let _ = pair;\n{config}"),
        ),
        (
            "a clone through a bound reference",
            format!("    let r = &mock;\n    let other = r.clone();\n    let _ = other;\n{config}"),
        ),
    ];
    for (index, (why, body)) in variants.iter().enumerate() {
        let body = if *why == "a configuration after the call" {
            body.clone()
        } else {
            format!("{body}{assert}")
        };
        assert_eq!(
            Value::Null,
            faux_value(&format!("framework-defeats-{index}"), &body),
            "{why}"
        );
    }
    // The control: the same mock with nothing in the way.
    assert_eq!(
        json!("10"),
        faux_value("framework-defeats-control", &format!("{config}{assert}"))
    );
    // The clone twin: cloning something else, a separate mock, a member
    // call made through a shared borrow of the mock and a called closure
    // that names no mock leave it.
    let apart = format!(
        "    let name = String::new().clone();\n    let other = Foo::faux();\n    let _ = Clone::clone(&name);\n    let _ = (&mock).get();\n    \
         let _ = (|| touch())();\n    let _ = (other, name);\n{config}{assert}"
    );
    assert_eq!(
        json!("10"),
        faux_value("framework-defeats-clone-twin", &apart)
    );
}

#[test]
fn a_clone_of_a_mry_mock_hides_its_value() {
    // The reviewer's `mry_ufcs_clone`.
    let source = "#[mry::mry]\n#[derive(Default, Clone, PartialEq)]\npub struct Cat {\n    name: String,\n}\n\
        #[mry::mry]\nimpl Cat {\n    pub fn meow(&self, count: usize) -> usize {\n        count\n    }\n}\n\
        #[test]\nfn t() {\n    let mut cat = mry::new!(Cat { name: String::new() });\n    \
        let mut c2 = Cat::clone(&cat);\n    c2.mock_meow(mry::Any).returns_with(|c| c);\n    \
        cat.mock_meow(mry::Any).returns(42);\n    assert_eq!(cat.meow(42), 42);\n}\n";
    let contributions = scan("mry-ufcs-clone", source);

    let operand = &operands(&contributions, 13)[0][0];
    assert_eq!("mock-config", operand["source"]);
    assert_eq!(Value::Null, operand["value"]);

    // A helper handed a borrow may clone it; a member call through one may
    // not.
    let helper = |name: &str, handing: &str| {
        let source = format!(
            "#[mry::mry]\n#[derive(Default, Clone)]\npub struct Cat {{\n    name: String,\n}}\n\
             #[mry::mry]\nimpl Cat {{\n    pub fn meow(&self) -> usize {{\n        1\n    }}\n}}\n\
             #[test]\nfn t() {{\n    let mut cat = Cat::default();\n    cat.mock_meow().returns(42);\n{handing}    \
             assert_eq!(cat.meow(), 42);\n}}\n"
        );
        operands(&scan(name, &source), 13)[0][0]["value"].clone()
    };
    assert_eq!(Value::Null, helper("mry-dup", "    let _ = dup(&cat);\n"));
    assert_eq!(
        json!("42"),
        helper("mry-dup-twin", "    let _ = (&cat).meow();\n")
    );
}

#[test]
fn mrys_assert_called_reads_and_configures_nothing() {
    let source = "#[mry::mry]\n#[derive(Default)]\npub struct Cat {\n    name: String,\n}\n\
        #[mry::mry]\nimpl Cat {\n    pub fn meow(&self) -> usize {\n        1\n    }\n}\n\
        #[test]\nfn t() {\n    let mut cat = Cat::default();\n    cat.mock_meow(mry::Any).returns(42);\n    \
        cat.mock_meow(mry::Any).assert_called(0);\n    assert_eq!(cat.meow(), 42);\n}\n\
        #[test]\nfn u() {\n    let mut cat = Cat::default();\n    cat.mock_meow(mry::Any).returns(42);\n    \
        cat.mock_meow(mry::Any).returns(42).assert_called(0);\n    assert_eq!(cat.meow(), 42);\n}\n";
    let contributions = scan("mry-assert-called", source);

    // `assert_called` asserts (a `times` on the double) and leaves the value.
    let first = operands(&contributions, 13);
    assert_eq!(1, first.len() - 1, "{first:?}");
    assert_eq!(json!("42"), first[1][0]["value"]);
    // A chain that also returns is a second configuration.
    assert_eq!(Value::Null, operands(&contributions, 20)[1][0]["value"]);
}

#[test]
fn a_name_the_body_declares_hides_a_mocked_function() {
    let source = "pub fn world() -> &'static str {\n    \"world\"\n}\n\
        #[test]\nfn t() {\n    use other::world;\n    world.mock_safe(|| MockResult::Return(\"x\"));\n}\n";

    assert!(nodes_of(&scan("mocktopus-hidden", source), "test_double").is_empty());
}

#[test]
fn a_static_mock_hides_an_instance_value() {
    let source = "#[mry::mry]\n#[derive(Default)]\npub struct Cat {\n    name: String,\n}\n\
        #[mry::mry]\nimpl Cat {\n    pub fn meow(&self) -> usize {\n        1\n    }\n}\n\
        #[test]\nfn t() {\n    let mut cat = Cat::default();\n    cat.mock_meow().returns(42);\n    \
        Cat::mock_meow().returns(7);\n    assert_eq!(cat.meow(), 42);\n}\n";
    let contributions = scan("mry-static-instance", source);

    let operand = &operands(&contributions, 13)[0][0];
    assert_eq!("mock-config", operand["source"]);
    assert_eq!(Value::Null, operand["value"]);
}

#[test]
fn a_name_the_body_declares_hides_a_framework() {
    let source = format!(
        "{FAUX}#[test]\nfn t() {{\n    struct Foo;\n    impl Foo {{\n        fn faux() -> Self {{\n            Foo\n        }}\n    }}\n    \
         let mock = Foo::faux();\n    assert_eq!(mock.get(), 10);\n}}\n\
         #[test]\nfn u() {{\n    use std::convert as faux;\n    let mut mock = super::Foo::faux();\n    \
         faux::when!(mock.get).then_return(10);\n    assert_eq!(mock.get(), 10);\n}}\n"
    );
    let contributions = scan("framework-hidden", &source);

    let doubles = nodes_of(&contributions, "test_double");
    assert!(
        doubles
            .keys()
            .all(|double| !double.starts_with("src/lib.rs#double:26:")),
        "{:?}",
        doubles.keys()
    );
    assert_eq!(Value::Null, operands(&contributions, 29)[0][0]["value"]);
}

/// The number of doubles each case of `contributions` makes, by case.
fn doubles_per_case(contributions: &[Value]) -> std::collections::BTreeMap<String, usize> {
    let mut per_case = std::collections::BTreeMap::new();
    for node in nodes_of(contributions, "test_double").values() {
        let case = node["attributes"]["test_case"]
            .as_str()
            .unwrap_or("")
            .to_owned();
        *per_case.entry(case).or_insert(0) += 1;
    }
    per_case
}

#[test]
fn mry_names_only_what_mry_generates() {
    let source = "#[mry::mry]\n#[derive(Default)]\npub struct Cat {\n    name: String,\n}\n\
        #[mry::mry]\nimpl Cat {\n    pub fn meow(&self) -> usize {\n        1\n    }\n    \
        pub fn new() -> Self {\n        Cat::default()\n    }\n}\n\
        #[mry::mry]\npub trait Dog {\n    fn bark(&self) -> usize;\n}\n\
        #[mry::mry]\npub trait Pup {\n    fn default() -> Self;\n}\n\
        #[mry::mry]\npub fn hiss() -> usize {\n    1\n}\npub fn purr() -> usize {\n    1\n}\n\
        #[mry::mry]\npub struct Lone {\n    a: u32,\n}\npub trait Plain {\n    fn get(&self) -> u32;\n}\nmry::m! {\n    pub enum Mood {\n        Calm,\n    }\n    pub struct Owl {\n        a: u32,\n    }\n    impl Owl {\n        \
        pub fn hoot(&self) -> u32 {\n            1\n        }\n    }\n}\n\
        other::m! {\n    pub struct Bat {\n        a: u32,\n    }\n}\n\
        #[faux::create]\npub struct Foo {\n    a: u32,\n}\n\
        use mockiato::mockable;\n#[mockable]\npub trait Greeter {\n    fn greet(&self) -> String;\n}\n\
        #[test]\nfn made() {\n    let _ = MockDog::default();\n    mock_hiss().returns(1);\n    \
        let mut owl = Owl { a: 1 };\n    owl.mock_hoot(mry::Any).returns(3);\n    \
        Owl::mock_hoot(mry::Any).returns(2);\n    let _ = Lone { a: 1 };\n    let _ = Mood::default();\n}\n\
        #[test]\nfn not_made() {\n    let _ = MockPup::default();\n    let _ = Cat::new();\n    \
        let _ = MockDog::default(1);\n    let _ = other::new!(Cat { name: String::new() });\n    \
        mock_purr();\n    mock_Cat();\n    GreeterMock::mock_greet();\n    let _ = Foo::default();\n    \
        let _ = Bat { a: 1 };\n    let _ = MockPlain::default();\n    let _ = Foo { a: 1 };\n    let _ = MockDog {};\n}\n\
        #[test]\nfn path_matcher() {\n    let two = 2;\n    let mut cat = Cat::default();\n    \
        cat.mock_meow(two).returns(42);\n    assert_eq!(cat.meow(2), 42);\n}\n\
        #[test]\nfn bare() {\n    let mut cat = Cat::default();\n    cat.mock_();\n    cat.mock_meow(mry::Any).returns(1);\n}\n";
    let contributions = scan("mry-names", source);
    let per_case = doubles_per_case(&contributions);
    let cases = nodes_of(&contributions, "test_case");
    let case_of = |name: &str| {
        cases
            .iter()
            .find(|(_, node)| node["attributes"]["name"] == name)
            .map(|(canonical, _)| canonical.clone())
            .unwrap()
    };

    assert_eq!(Some(&6), per_case.get(&case_of("made")));
    assert_eq!(None, per_case.get(&case_of("not_made")));
    let owl = nodes_of(&contributions, "test_double")
        .into_values()
        .filter(|node| node["attributes"]["test_case"] == json!(case_of("made")))
        .map(|node| members(&node["attributes"]))
        .collect::<Vec<_>>();
    assert!(owl.contains(&expected(&[("hoot", "mock", &["returns"])])));
    // A matcher that is a local may not be `mry::Any`.
    let matcher = &cases[&case_of("path_matcher")]["attributes"]["assertions"][0]["operands"][0];
    assert_eq!("mock-config", matcher["source"]);
    assert_eq!(Value::Null, matcher["value"]);
    // `mock_()` names no member: it is a call.
    let cat = nodes_of(&contributions, "test_double")
        .into_values()
        .find(|node| node["attributes"]["test_case"] == json!(case_of("bare")))
        .unwrap();
    assert_eq!(
        expected(&[("meow", "mock", &["returns"])]),
        members(&cat["attributes"])
    );
    assert_eq!(1, cat["attributes"]["invocations"]);
}

#[test]
fn each_framework_names_only_what_it_generates() {
    let galvanic = "pub trait TestTrait {\n    fn func(&self) -> usize;\n}\n\
        #[test]\nfn t() {\n    let mock = new_mock!(TestTrait for MyMock);\n    let _ = new_mock!(TestTrait #[allow(dead_code)]);\n    given! {\n        \
        <mock as TestTrait>::func() then_return 1 always;\n        !mock as TestTrait>::bang() then_return 1 always;\n        \
        <mock is TestTrait>::is_() then_return 1 always;\n        <mock as TestTrait>.:dot() then_return 1 always;\n        \
        <mock as TestTrait>:.colon() then_return 1 always;\n        <mock as TestTrait>::bracket[1] then_return 1 always;\n    }\n}\n";
    let contributions = scan("galvanic-shapes", galvanic);
    // The mock's own attributes after the trait name are no part of it.
    assert_eq!(2, nodes_of(&contributions, "test_double").len());
    let mock = nodes_of(&contributions, "test_double")
        .into_values()
        .next()
        .unwrap();
    assert_eq!(
        expected(&[("func", "given", &["then_return"])]),
        members(&mock["attributes"])
    );
    assert_eq!(
        Some("rust:interface:crate::TestTrait".to_owned()),
        target(&contributions, "6:16")
    );

    let derive = "use mock_derive::mock;\n#[mock]\npub trait Built {\n    fn new() -> Self;\n}\n\
        #[mock]\npub trait Plain {\n    fn get(&self) -> u32;\n}\n\
        #[mock]\nextern \"C\" {\n    fn ext();\n}\nextern \"C\" {\n    fn bare();\n}\n\
        #[mry::mry]\npub trait Dog {\n    fn bark(&self) -> usize;\n}\n\
        #[test]\nfn t() {\n    let _ = MockBuilt::new();\n    let _ = ExternCMocks::new();\n    \
        let _ = MockDog::new();\n    let _ = MockPlain::method_get();\n    let _ = ExternCMocks::method_bare();\n    \
        let _ = ExternCMocks::method_ext();\n}\n";
    let contributions = scan("mock-derive-shapes", derive);
    let made = nodes_of(&contributions, "test_double");
    assert_eq!(1, made.len(), "{:?}", made.keys());
    assert_eq!(
        expected(&[("ext", "method", &[])]),
        members(&made.values().next().unwrap()["attributes"])
    );

    let mockers = "pub trait A {\n    fn foo(&self);\n}\n\
        not_mock! {\n    BMock,\n    self,\n    trait A {\n        fn foo(&self);\n    }\n}\n\
        mock! {\n    CMock,\n    self,\n    unsafe trait A {\n        fn foo(&self);\n    }\n}\n\
        #[mockers_derive::mocked(Custom)]\npub trait D {\n    fn bar(&self);\n}\n\
        #[test]\nfn t() {\n    let s = mockers::Scenario::new();\n    let (b, bh) = s.create_mock::<BMock>();\n    \
        let (c, ch) = s.create_mock::<CMock>();\n    let (q, qh) = s.create_mock::<<A as Tr>::Out>();\n    \
        let (d, dh) = s.create_mock::<Custom>();\n}\n";
    let contributions = scan("mockers-shapes", mockers);
    assert_eq!(3, nodes_of(&contributions, "test_double").len());
    assert_eq!(None, target(&contributions, "25:19"));
    assert_eq!(
        Some("rust:interface:crate::A".to_owned()),
        target(&contributions, "26:19")
    );
    assert_eq!(
        Some("rust:interface:crate::D".to_owned()),
        target(&contributions, "28:19")
    );

    let mockiato =
        "use mockiato::mockable;\n#[mockable]\npub trait Built {\n    fn new() -> Self;\n}\n\
        #[test]\nfn t() {\n    let _ = BuiltMock::new();\n}\n";
    assert!(nodes_of(&scan("mockiato-shapes", mockiato), "test_double").is_empty());
}

#[test]
fn a_constructor_is_handed_its_arguments() {
    // A mock moved into another double's constructor leaves the test's
    // sight: invoked, and no value.
    let holder = "pub trait Holds {\n    fn held(&self) -> u32;\n}\n";
    let cases = [
        "    let _ = Wrap { foo: mock };\n",
        "    let _ = Wrap::new(mock);\n",
        "    let scenario = mockers::Scenario::new();\n    let _ = scenario.create_named_mock_for::<dyn Holds>(mock);\n",
        "    let _ = mry::new!(Cat { foo: mock });\n",
    ];
    for (index, handing) in cases.iter().enumerate() {
        let source = format!(
            "{FAUX}{holder}#[mry::mry]\npub struct Cat {{\n    foo: Foo,\n}}\n\
             #[cfg(test)]\nmod tests {{\n    use super::*;\n    struct Wrap {{\n        foo: Foo,\n    }}\n    \
             impl Wrap {{\n        fn new(foo: Foo) -> Self {{\n            Wrap {{ foo }}\n        }}\n    }}\n    \
             impl Holds for Wrap {{\n        fn held(&self) -> u32 {{\n            1\n        }}\n    }}\n    \
             #[test]\n    fn t() {{\n        let mut mock = Foo::faux();\n        \
             faux::when!(mock.get).then_return(10);\n    {handing}        assert_eq!(mock.get(), 10);\n    }}\n}}\n"
        );
        let contributions = scan(&format!("constructor-arguments-{index}"), &source);
        let cases = nodes_of(&contributions, "test_case");
        let case = cases.values().next().unwrap();
        let operand = &case["attributes"]["assertions"][0]["operands"][0];
        assert_eq!("mock-config", operand["source"], "{handing}");
        assert_eq!(Value::Null, operand["value"], "{handing}");
        let faux = nodes_of(&contributions, "test_double")
            .into_values()
            .find(|node| node["attributes"]["framework"] == "faux")
            .unwrap();
        assert_eq!(2, faux["attributes"]["invocations"], "{handing}");
    }
}

#[test]
fn a_body_item_hides_mry_and_faux_constructors() {
    let source = "#[mry::mry]\n#[derive(Default)]\npub struct Cat {\n    name: String,\n}\n\
        #[faux::create]\npub struct Foo {\n    a: u32,\n}\n\
        #[test]\nfn literal() {\n    struct Cat {\n        name: String,\n    }\n    \
        let _ = Cat { name: String::new() };\n}\n\
        #[test]\nfn renamed() {\n    use std::convert as mry;\n    let _ = mry::new!(Cat { name: String::new() });\n}\n\
        #[test]\nfn arguments() {\n    let _ = Foo::faux(1);\n    let _ = <Foo>::faux();\n}\n";

    assert!(nodes_of(&scan("mry-hidden", source), "test_double").is_empty());
}

/// The modules `{prefix}1` to `{prefix}{depth}`, each glob-importing the
/// next, the last importing `last`.
fn glob_chain(prefix: &str, depth: usize, last: &str) -> String {
    let mut source = String::new();
    for level in 1..depth {
        source += &format!(
            "pub mod {prefix}{level} {{\n    pub use super::{prefix}{}::*;\n}}\n",
            level + 1
        );
    }
    source + &format!("pub mod {prefix}{depth} {{\n    pub use {last};\n}}\n")
}

/// How many doubles of `framework` the scan of `source` gives.
fn count(name: &str, source: &str, framework: &str) -> usize {
    frameworks(&scan(name, source))
        .iter()
        .filter(|made| *made == framework)
        .count()
}

#[test]
fn a_generated_name_is_followed_eight_hops() {
    let greeter = "use mockiato::mockable;\n#[mockable]\npub trait Greeter {\n    fn greet(&self) -> String;\n}\n";
    let tests = "mod tests {\n    use super::g1::*;\n    #[test]\n    fn t() {\n        \
        let _ = GreeterMock::new();\n    }\n    #[test]\n    fn u() {\n        \
        let _ = super::GreeterMock::new();\n        let _ = crate::GreeterMock::new();\n    }\n}\n";
    let near = format!(
        "{greeter}{}{tests}",
        glob_chain("g", 7, "super::GreeterMock")
    );
    let far = format!(
        "{greeter}{}{tests}",
        glob_chain("g", 8, "super::GreeterMock")
    );

    // Seven globs and the import: eight hops, all three found.
    assert_eq!(3, count("hops-near", &near, "mockiato"));
    // Nine hops: only the `super::` and `crate::` paths are.
    assert_eq!(2, count("hops-far", &far, "mockiato"));
}

#[test]
fn crate_in_an_integration_test_names_the_test_file() {
    // `tests/it.rs` is a crate root of its own: `crate::` is its module.
    let it = "use mockiato::mockable;\n#[mockable]\npub trait Greeter {\n    fn greet(&self) -> String;\n}\n\
        mod inner {\n    #[test]\n    fn t() {\n        let _ = crate::GreeterMock::new();\n    }\n}\n";
    let contributions = scan_fixture_with(
        "crate-in-tests",
        &[
            ("Cargo.toml", "[package]\nname = \"probe\"\n"),
            ("src/lib.rs", "pub fn f() {}\n"),
            ("tests/it.rs", it),
        ],
        &json!({ "config_files": ["Cargo.toml"] }),
    );

    assert_eq!(vec!["mockiato".to_owned()], frameworks(&contributions));
}

#[test]
fn a_framework_macro_is_followed_eight_hops() {
    let config = |depth: usize| {
        format!(
            "{FAUX}{}pub mod empty {{}}\nmod tests {{\n    use super::w1::*;\n    use super::empty::*;\n    \
             #[test]\n    fn t() {{\n        let mut mock = super::Foo::faux();\n        \
             when!(mock.get).then_return(10);\n        assert_eq!(mock.get(), 10);\n    }}\n}}\n",
            glob_chain("w", depth, "faux::when")
        )
    };
    let value = |name: &str, depth: usize| {
        let contributions = scan(name, &config(depth));
        let cases = nodes_of(&contributions, "test_case");
        let case = cases.values().next().unwrap();
        case["attributes"]["assertions"][0]["operands"][0]["value"].clone()
    };

    // A glob from a module without the name adds no reading of it.
    assert_eq!(json!("10"), value("macro-hops-near", 8));
    assert_eq!(Value::Null, value("macro-hops-far", 9));
}
