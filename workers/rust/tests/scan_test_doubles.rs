//! Scanning tests of the `test_double` nodes mockall's mocks give
//! (Decision 3): one per mock instance, resolved through the worker to a
//! generator it has seen, with the members, pins and invocations the test
//! makes, and a `doubles` edge to what the mock stands in for.

mod common;

use common::*;
use serde_json::{json, Value};

/// A one-package crate of `files` beside its manifest.
fn crate_of(name: &str, files: &[(&str, &str)]) -> Vec<Value> {
    let mut all = vec![("Cargo.toml", "[package]\nname = \"probe\"\n")];
    all.extend_from_slice(files);
    scan_fixture_with(name, &all, &json!({ "config_files": ["Cargo.toml"] }))
}

/// The `test_double` nodes, by canonical name.
fn doubles(contributions: &[Value]) -> std::collections::BTreeMap<String, Value> {
    nodes_of(contributions, "test_double")
}

/// The attributes of the double `canonical`.
fn double(contributions: &[Value], canonical: &str) -> Value {
    doubles(contributions)
        .get(canonical)
        .unwrap_or_else(|| panic!("no double {canonical}: {:?}", doubles(contributions).keys()))
        ["attributes"]
        .clone()
}

/// The `case` node's assertions.
fn assertions_of(contributions: &[Value], case: &str) -> Value {
    nodes_of(contributions, "test_case")[case]["attributes"]["assertions"].clone()
}

#[test]
fn automock_instance_records_members_pins_and_edge() {
    let contributions = scan_repository_fixture("test-fidelity/rust/nemesis-experiments/automock");
    let attributes = double(&contributions, "tests/clock_mock.rs#double:10:21");

    assert_eq!("mockall", attributes["framework"]);
    assert_eq!("mockall::automock", attributes["pattern"]);
    assert_eq!(true, attributes["compile_checked"]);
    assert_eq!(false, attributes["automock"]);
    assert_eq!(json!({"kind": "class"}), attributes["target"]);
    assert_eq!("tests/clock_mock.rs#test:9", attributes["test_case"]);
    assert_eq!(
        json!([{"name": "now", "line": 11, "api": "expect", "returns": [
            {"api": "returning", "line": 11, "once": false, "assignable": "unknown", "typed": false}
        ]}]),
        attributes["members"]
    );
    assert_eq!(1, attributes["invocations"]);
    assert_eq!(
        vec![(
            "rust:test_double:tests/clock_mock.rs#double:10:21".to_owned(),
            "rust:interface:tests::clock_mock::Clock".to_owned()
        )],
        edges_of(&contributions, "doubles")
    );
    assert!(edges_of(&contributions, "contains").contains(&(
        "rust:module:tests::clock_mock".to_owned(),
        "rust:test_double:tests/clock_mock.rs#double:10:21".to_owned()
    )));
}

#[test]
fn automock_on_an_impl_targets_its_self_type() {
    let contributions = scan_repository_fixture("test-fidelity/rust/momus-drift");

    assert_eq!(
        vec![
            (
                "rust:test_double:generic_test.rs#double:36:20".to_owned(),
                "rust:class:generic_test::Foo".to_owned()
            ),
            (
                "rust:test_double:generic_test.rs#double:43:20".to_owned(),
                "rust:class:generic_test::A".to_owned()
            ),
        ],
        edges_of(&contributions, "doubles")
    );
    // `expect_bar::<SendFoo>()` configures `bar`.
    let members = &double(&contributions, "generic_test.rs#double:43:20")["members"];
    assert_eq!("bar", members[0]["name"]);
    assert_eq!("return_const", members[0]["returns"][0]["api"]);
}

#[test]
fn a_mock_without_a_generator_is_no_double() {
    // Momus's `drift_test.rs:9` and `healthy_test.rs:9` name a `MockRepo`
    // nothing generates; Nemesis's `gateway_mock.rs` a `MockGateway` it
    // never imports.
    let momus = scan_repository_fixture("test-fidelity/rust/momus-drift");
    let drift = scan_repository_fixture("test-fidelity/rust/nemesis-experiments/automock-drift");

    assert!(doubles(&momus)
        .keys()
        .all(|canonical| canonical.starts_with("generic_test.rs#")));
    assert!(doubles(&drift).is_empty());
}

#[test]
fn mock_macro_with_one_impl_targets_the_trait() {
    let lib = "pub mod transport;\n#[cfg(test)]\nmod tests;\n";
    let transport = "pub trait Transport {\n    fn send(&self) -> bool;\n}\n";
    let tests = "use crate::transport::Transport;\nuse mockall::mock;\n\
        mock! {\n    pub Wire {}\n    impl Transport for Wire {\n        fn send(&self) -> bool;\n    }\n}\n\
        mock! {\n    pub Pair<T: Clone> {}\n    impl<F: Fn() -> u32> Transport for Pair<F> {\n        fn send(&self) -> bool;\n    }\n    impl Clone for Pair<F> {\n        fn clone(&self) -> Self;\n    }\n}\n\
        #[test]\nfn wired() {\n    let mut wire = MockWire::new();\n    wire.expect_send().return_const(true);\n    let pair = MockPair::new();\n    let _ = (wire.send(), pair);\n}\n";
    let contributions = crate_of(
        "doubles-mock-macro",
        &[
            ("src/lib.rs", lib),
            ("src/transport.rs", transport),
            ("src/tests.rs", tests),
        ],
    );

    assert_eq!(
        "mockall::mock",
        double(&contributions, "src/tests.rs#double:20:20")["pattern"]
    );
    // Two `impl` sections: which one it stands in for is not one trait.
    assert!(doubles(&contributions).contains_key("src/tests.rs#double:22:16"));
    assert_eq!(
        vec![(
            "rust:test_double:src/tests.rs#double:20:20".to_owned(),
            "rust:interface:crate::transport::Transport".to_owned()
        )],
        edges_of(&contributions, "doubles")
    );
}

#[test]
fn a_mock_macro_struct_alone_has_no_target() {
    // Nemesis's `mock-block`: a struct section and no `impl`.
    let tests = "use mockall::mock;\nmock! {\n    #[derive(Debug)]\n    pub(crate) Store {\n        fn read(&self, key: &str) -> String;\n    }\n}\n\
        #[test]\nfn stored() {\n    let mut store = MockStore::default();\n    store.expect_read().return_const(String::new());\n    assert!(store.read(\"k\").is_empty());\n}\n";
    let contributions = crate_of("doubles-mock-struct", &[("tests/store.rs", tests)]);

    assert_eq!(
        "mockall::mock",
        double(&contributions, "tests/store.rs#double:10:21")["pattern"]
    );
    assert!(edges_of(&contributions, "doubles").is_empty());
}

#[test]
fn use_super_glob_reaches_a_parent_automock() {
    let lib = "pub mod clock;\npub mod service;\n";
    let clock = "#[cfg_attr(test, mockall::automock)]\npub trait Clock {\n    fn now(&self) -> u64;\n}\n\
        #[cfg(test)]\nmod tests {\n    use super::*;\n    #[test]\n    fn own() {\n        let _clock = MockClock::new();\n    }\n}\n";
    // The parent imports the mock; the child's glob reaches the import.
    let service = "#[cfg(test)]\nuse crate::clock::MockClock;\n\
        #[cfg(test)]\nmod tests {\n    use super::*;\n    #[test]\n    fn imported() {\n        let _clock = MockClock::new();\n        let _other = crate::clock::MockClock::new();\n        let _relative = <super::MockClock>::new();\n    }\n}\n";
    let contributions = crate_of(
        "doubles-super-glob",
        &[
            ("src/lib.rs", lib),
            ("src/clock.rs", clock),
            ("src/service.rs", service),
        ],
    );

    assert_eq!(
        vec![
            "src/clock.rs#double:10:22".to_owned(),
            "src/service.rs#double:10:25".to_owned(),
            "src/service.rs#double:8:22".to_owned(),
            "src/service.rs#double:9:22".to_owned(),
        ],
        doubles(&contributions).keys().cloned().collect::<Vec<_>>()
    );
    assert!(edges_of(&contributions, "doubles")
        .iter()
        .all(|(_, target)| target == "rust:interface:crate::clock::Clock"));
}

#[test]
fn cfg_attr_test_automock_is_invisible_from_tests_dir() {
    let clock =
        "#[cfg_attr(test, mockall::automock)]\npub trait Clock {\n    fn now(&self) -> u64;\n}\n";
    let ungated = "#[mockall::automock]\npub trait Timer {\n    fn now(&self) -> u64;\n}\n";
    let it = "use probe::clock::MockClock;\nuse probe::timer::MockTimer;\n\
        #[test]\nfn outside() {\n    let _clock = MockClock::new();\n    let _timer = MockTimer::new();\n    \
        let _rooted = ::probe::timer::MockTimer::new();\n    let _gated = ::probe::clock::MockClock::new();\n    \
        let _fake = probe::mocks::MockFake::new();\n}\n";
    // Within the crate's test build, the same mock exists.
    let inside = "#[cfg(test)]\nmod tests {\n    #[test]\n    fn inside() {\n        let _clock = crate::clock::MockClock::new();\n    }\n}\n";
    let contributions = crate_of(
        "doubles-cfg-attr",
        &[
            (
                "src/lib.rs",
                "pub mod clock;\npub mod timer;\nmod inside;\n#[cfg(test)]\npub mod mocks;\n",
            ),
            // Test-only as a whole file, by the `cfg` on its `mod`.
            ("src/mocks.rs", "#[mockall::automock]\npub trait Fake {}\n"),
            ("src/clock.rs", clock),
            ("src/timer.rs", ungated),
            ("src/inside.rs", inside),
            ("tests/it.rs", it),
        ],
    );

    assert_eq!(
        vec![
            "src/inside.rs#double:5:22".to_owned(),
            "tests/it.rs#double:6:18".to_owned(),
            "tests/it.rs#double:7:19".to_owned(),
        ],
        doubles(&contributions).keys().cloned().collect::<Vec<_>>()
    );
}

#[test]
fn static_contexts_give_identity_only() {
    let tests = "#[mockall::automock]\npub trait Foo {\n    fn bar(x: u32) -> u32;\n}\n\
        #[test]\nfn context() {\n    let ctx = MockFoo::bar_context();\n    ctx.expect().returning(|_| 5);\n    assert_eq!(MockFoo::bar(1), 5);\n}\n";
    let contributions = crate_of("doubles-context", &[("tests/context.rs", tests)]);
    let attributes = double(&contributions, "tests/context.rs#double:7:15");

    assert_eq!(
        json!([{"name": "bar", "line": 7, "api": "context", "returns": []}]),
        attributes["members"]
    );
    assert_eq!(1, attributes["invocations"]);
    assert_eq!(
        json!([{"source": "mock-config", "double": "tests/context.rs#double:7:15"}, {"source": "literal", "text": "5"}]),
        assertions_of(&contributions, "tests/context.rs#test:6")[0]["operands"]
    );
}

#[test]
fn counts_invocations_and_hand_ons() {
    let header = "#[mockall::automock]\npub trait Foo {\n    fn foo(&self) -> u32;\n}\n";
    let case = |body: &str| {
        let source = format!("{header}#[test]\nfn case() {{\n    let mut mock = MockFoo::new();\n    mock.expect_foo().return_const(1u32);\n    {body}\n}}\n");
        let contributions = crate_of("doubles-invocations", &[("tests/foo.rs", &source)]);
        double(&contributions, "tests/foo.rs#double:7:20")["invocations"].clone()
    };

    for body in [
        "let _boxed = Box::new(mock);",
        "let _borrowed = &mock;",
        "service.run(&mut mock);",
        "Foo::foo(&mock);",
        "<MockFoo as Foo>::foo(&mock, 1);",
        "mock.foo();",
        "vec![mock];",
        "let _held = Holder { mock };",
    ] {
        assert_eq!(json!(1), case(body), "{body}");
    }
    for body in ["mock.expect_foo().times(1);", "mock.checkpoint();", ""] {
        assert_eq!(json!(0), case(body), "{body}");
    }
    assert_eq!(json!(2), case("mock.foo();\n    helper(&mock);"));
}

#[test]
fn times_counts_as_an_assertion_on_the_double() {
    let tests = "#[mockall::automock]\npub trait Foo {\n    fn foo(&self) -> u32;\n}\n\
        #[test]\nfn counted() {\n    let mut mock = MockFoo::new();\n    mock.expect_foo().times(1).return_const(1u32);\n    mock.expect_foo().once();\n    mock.expect_foo().never();\n}\n";
    let contributions = crate_of("doubles-times", &[("tests/times.rs", tests)]);
    let double = "tests/times.rs#double:7:20";
    let times = |line: usize| json!({"api": "times", "line": line, "operands": [{"source": "mock-call", "double": double}]});

    assert_eq!(
        json!([times(8), times(9), times(10)]),
        assertions_of(&contributions, "tests/times.rs#test:6")
    );
}

#[test]
fn generated_name_lookups_are_reads() {
    let service = "#[cfg(test)]\nmod tests {\n    use crate::clock::MockClock;\n    #[test]\n    fn mocked() {\n        let _clock = MockClock::new();\n    }\n}\n";
    let contributions = crate_of(
        "doubles-reads",
        &[
            ("src/lib.rs", "pub mod clock;\npub mod service;\n"),
            (
                "src/clock.rs",
                "#[cfg_attr(test, mockall::automock)]\npub trait Clock {}\n",
            ),
            ("src/service.rs", service),
        ],
    );
    let reads = contributions
        .iter()
        .find(|contribution| contribution["owner_key"] == "knossos.rust:file:src/service.rs")
        .unwrap()["reads"]
        .clone();

    assert!(reads.get("src/clock.rs").is_some(), "{reads}");
    assert!(doubles(&contributions).contains_key("src/service.rs#double:6:22"));
}

#[test]
fn non_test_output_is_unchanged_by_generated_names() {
    let scan = |attribute: &str| {
        let clock = format!("{attribute}\npub trait Clock {{\n    fn now(&self) -> u64;\n}}\n");
        let service = "use crate::clock::Clock;\npub fn twice(clock: &dyn Clock) -> u64 {\n    clock.now() * 2\n}\n#[cfg(test)]\nmod tests {\n    use super::*;\n    use crate::clock::MockClock;\n    #[test]\n    fn doubled() {\n        let mut clock = MockClock::new();\n        clock.expect_now().return_const(2u64);\n        assert_eq!(twice(&clock), 4);\n    }\n}\n";
        crate_of(
            "doubles-non-test",
            &[
                ("src/lib.rs", "pub mod clock;\npub mod service;\n"),
                ("src/clock.rs", &clock),
                ("src/service.rs", service),
            ],
        )
    };
    let without_tests = |contributions: Vec<Value>| -> Vec<Value> {
        contributions
            .into_iter()
            .map(|mut contribution| {
                let test_fact = |id: &Value| {
                    let id = id.as_str().unwrap_or_default();
                    id.starts_with("rust:test_double:") || id.starts_with("rust:test_case:")
                };
                contribution["nodes"]
                    .as_array_mut()
                    .unwrap()
                    .retain(|node| !test_fact(&node["local_id"]));
                contribution["edges"]
                    .as_array_mut()
                    .unwrap()
                    .retain(|edge| !test_fact(&edge["source"]) && !test_fact(&edge["target"]));
                // The generating file's bytes differ, so does its hash.
                contribution["content_hash"] = Value::Null;
                for (_, hash) in contribution["reads"].as_object_mut().unwrap() {
                    *hash = Value::Null;
                }
                contribution
            })
            .collect()
    };
    let generated = scan("#[cfg_attr(test, mockall::automock)]");
    let plain = scan("#[allow(dead_code)]");

    assert!(!doubles(&generated).is_empty());
    assert!(doubles(&plain).is_empty());
    assert_eq!(without_tests(plain), without_tests(generated));
}

#[test]
fn resolution_is_bounded_and_follows_only_the_modules_own_globs() {
    // A chain of globs eight hops long reaches the mock; nine do not.
    let mut source =
        "mod a0 {\n    #[mockall::automock]\n    pub trait T {\n        fn f(&self);\n    }\n}\n"
            .to_owned();
    for depth in 1..=9 {
        source += &format!(
            "mod a{depth} {{\n    pub use super::a{}::*;\n}}\n",
            depth - 1
        );
    }
    // Imports and globs that go round in a circle name nothing.
    source += "mod c1 {\n    pub use super::c2::MockLoop;\n}\nmod c2 {\n    pub use super::c1::MockLoop;\n}\n\
        mod g1 {\n    pub use super::g2::*;\n}\nmod g2 {\n    pub use super::g1::*;\n}\n";
    // A module without a glob of its own sees none of the others'.
    source +=
        "mod z {\n    #[test]\n    fn bare() {\n        let _bare = MockT::new();\n    }\n}\n";
    source += "#[test]\nfn reached() {\n    let _eight = a8::MockT::new();\n    let _nine = a9::MockT::new();\n    \
        let _import = c1::MockLoop::new();\n    let _glob = g1::MockLoop::new();\n}\n";
    let contributions = crate_of(
        "doubles-bounds",
        &[
            ("tests/chain.rs", &source),
            // The same circle across files, through the index.
            ("src/lib.rs", "pub mod a;\npub mod b;\n"),
            ("src/a.rs", "pub use crate::b::MockLoop;\n"),
            ("src/b.rs", "pub use crate::a::MockLoop;\n"),
            (
                "tests/across.rs",
                "#[test]\nfn across() {\n    let _across = probe::a::MockLoop::new();\n}\n",
            ),
        ],
    );

    assert_eq!(
        vec!["tests/chain.rs#double:54:18".to_owned()],
        doubles(&contributions).keys().cloned().collect::<Vec<_>>()
    );
    // Every hop counted up, never down: no pass failed.
    assert!(!failed(&contributions));
}

/// Whether some file's test-facts pass failed.
fn failed(contributions: &[Value]) -> bool {
    contributions.iter().any(|contribution| {
        contribution["diagnostics"]
            .to_string()
            .contains("RS_TEST_FACTS_FAILED")
    })
}

#[test]
fn every_test_gate_hides_a_generator_from_tests_dir() {
    let generators = "pub struct A;\npub struct B;\npub struct C;\npub struct D;\n\
        #[cfg(test)]\npub mod inner {\n    #[mockall::automock]\n    impl super::A {}\n    pub mod deep {\n        #[mockall::automock]\n        pub trait E {}\n    }\n}\n\
        #[cfg_attr(test, mockall::automock)]\nimpl B {}\n#[cfg(test)]\n#[mockall::automock]\nimpl C {}\n#[mockall::automock]\nimpl D {}\n\
        #[cfg_attr(test, mockall::automock)]\npub mod m {\n    pub fn f() {}\n}\n\
        #[cfg(test)]\nmockall::mock! {\n    pub F {}\n}\n\
        other! {\n    pub G {}\n}\n";
    let it = "use probe::gen::*;\nuse probe::gen::inner::MockA;\nuse probe::gen::inner::deep::MockE;\n#[test]\nfn outside() {\n    \
        let _a = MockA::new();\n    let _b = MockB::new();\n    let _c = MockC::new();\n    let _d = MockD::new();\n    \
        let _e = MockE::new();\n    let _m = mock_m::f_context();\n    let _f = MockF::new();\n    let _g = MockG::new();\n}\n";
    let contributions = crate_of(
        "doubles-gates",
        &[
            ("src/lib.rs", "pub mod gen;\n"),
            ("src/gen.rs", generators),
            ("tests/it.rs", it),
        ],
    );

    // Only the ungated `impl D` is seen from `tests/`.
    assert_eq!(
        vec!["tests/it.rs#double:9:14".to_owned()],
        doubles(&contributions).keys().cloned().collect::<Vec<_>>()
    );
}

#[test]
fn mock_macro_sections_are_read_through_attributes_and_generics() {
    let tests = "use crate::transport::Transport;\n\
        mock! {\n    #[derive(Debug)]\n    pub(crate) Gen<T: Into<u32>> {}\n    \
        impl<F: Fn() -> u32, G: Into<Vec<u8>>> Transport for Gen<F, G> {\n        fn send(&self) -> bool;\n    }\n}\n\
        mock! {\n    Bare {}\n}\n\
        // Malformed, as a file being edited is: no mock, and no failure.\n\
        mock! {\n    pub\n}\nmock! {\n    & & Odd {}\n}\nmock! {\n    pub Open {}\n    impl Transport\n}\n\
        #[test]\nfn made() {\n    let _gen = MockGen::new();\n    let _bare = MockBare::new();\n    let _odd = MockOdd::new();\n    let _open = MockOpen::new();\n}\n";
    let contributions = crate_of(
        "doubles-mock-sections",
        &[
            (
                "src/lib.rs",
                "pub mod transport;\n#[cfg(test)]\nmod tests;\n",
            ),
            (
                "src/transport.rs",
                "pub trait Transport {\n    fn send(&self) -> bool;\n}\n",
            ),
            ("src/tests.rs", tests),
        ],
    );

    assert_eq!(
        vec![(
            "rust:test_double:src/tests.rs#double:25:16".to_owned(),
            "rust:interface:crate::transport::Transport".to_owned()
        )],
        edges_of(&contributions, "doubles")
    );
    assert_eq!(3, doubles(&contributions).len());
    assert!(!failed(&contributions));
}

#[test]
fn every_constructor_form_makes_one_double() {
    let tests = "#[mockall::automock]\npub trait Foo {\n    fn foo(&self) -> u32;\n    fn bar() -> u32;\n    fn baz() -> u32;\n}\n\
        #[mockall::automock]\npub mod outer {\n    pub fn new() -> u32 {\n        1\n    }\n}\n\
        #[test]\nfn forms() {\n    let a: MockFoo = Default::default();\n    let b: MockFoo = Other::default();\n    \
        let c: MockFoo = Default::default(1);\n    let d = <MockFoo as Default>::default();\n    let ref e = MockFoo::new();\n    \
        let context = mock_outer::new_context();\n    context.expect().return_const(1u32);\n    let _ = mock_outer::new();\n}\n";
    let contributions = crate_of("doubles-forms", &[("tests/forms.rs", tests)]);
    let found = doubles(&contributions);

    assert_eq!(
        vec![
            "tests/forms.rs#double:15:22",
            "tests/forms.rs#double:18:13",
            "tests/forms.rs#double:19:17",
            "tests/forms.rs#double:20:19",
        ],
        found.keys().map(String::as_str).collect::<Vec<_>>()
    );
    // Bound to no one name, the mock is handed on where it stands.
    assert_eq!(
        1,
        found["tests/forms.rs#double:19:17"]["attributes"]["invocations"]
    );
    // `mock_outer::new()` is the context's static call, not a constructor.
    let context = &found["tests/forms.rs#double:20:19"]["attributes"];
    assert_eq!(1, context["invocations"]);
    assert_eq!("new", context["members"][0]["name"]);
}

#[test]
fn a_static_call_reaches_its_own_context() {
    let tests = "#[mockall::automock]\npub trait Foo {\n    fn foo(&self) -> u32;\n    fn bar() -> u32;\n    fn baz() -> u32;\n}\n\
        #[test]\nfn contexts() {\n    let mut plain = MockFoo::new();\n    plain.expect_foo();\n    let baz = MockFoo::baz_context();\n    \
        let bar = MockFoo::bar_context();\n    bar.called();\n    let _ = MockFoo::bar();\n}\n";
    let contributions = crate_of("doubles-contexts", &[("tests/contexts.rs", tests)]);
    let invocations: Vec<Value> = doubles(&contributions)
        .values()
        .map(|double| double["attributes"]["invocations"].clone())
        .collect();

    // `baz` (line 11), `bar` (line 12), then `plain` (line 9), by name.
    assert_eq!(vec![json!(0), json!(2), json!(0)], invocations);
}

#[test]
fn import_chains_are_followed_eight_hops_deep() {
    // In the file: `use` items, which the walk's imports already follow to
    // the end, so even nine of them are one hop.
    let mut chain = "mod i0 {\n    #[mockall::automock]\n    pub trait T {}\n}\n".to_owned();
    for depth in 1..=9 {
        chain += &format!(
            "mod i{depth} {{\n    pub use super::i{}::MockT;\n}}\n",
            depth - 1
        );
    }
    chain += "#[test]\nfn imported() {\n    let _eight = i8::MockT::new();\n    let _nine = i9::MockT::new();\n}\n";
    // Across files: re-exports, followed through the index one hop each.
    let mut files = vec![
        ("src/e0.rs".to_owned(), "#[mockall::automock]\npub trait T {}\n".to_owned()),
        ("tests/chain.rs".to_owned(), chain),
        (
            "tests/across.rs".to_owned(),
            "#[test]\nfn exported() {\n    let _eight = probe::e8::MockT::new();\n    let _nine = probe::e9::MockT::new();\n}\n"
                .to_owned(),
        ),
    ];
    let mut lib = String::from("pub mod e0;\n");
    for depth in 1..=9 {
        lib += &format!("pub mod e{depth};\n");
        files.push((
            format!("src/e{depth}.rs"),
            format!("pub use crate::e{}::MockT;\n", depth - 1),
        ));
    }
    files.push(("src/lib.rs".to_owned(), lib));
    let borrowed: Vec<(&str, &str)> = files
        .iter()
        .map(|(path, source)| (path.as_str(), source.as_str()))
        .collect();
    let contributions = crate_of("doubles-import-chains", &borrowed);

    assert_eq!(
        vec![
            "tests/across.rs#double:3:18".to_owned(),
            "tests/chain.rs#double:34:18".to_owned(),
            "tests/chain.rs#double:35:17".to_owned(),
        ],
        doubles(&contributions).keys().cloned().collect::<Vec<_>>()
    );
}
