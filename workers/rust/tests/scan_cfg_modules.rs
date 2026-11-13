//! Scanning tests driving the worker against real files on disk:
//! modules a `cfg` or a `#[path]` gates or places, and test code.

mod common;

use serde_json::Value;

use common::*;

#[test]
fn an_out_of_line_cfg_test_module_is_test_code_down_to_its_module_node() {
    // `#[cfg(test)] mod tests;` in `visit/mod.rs` with the body in
    // `visit/tests.rs`: the file's own module node is test code too, or the
    // module reads as unreferenced production code.
    let files = [
        ("src/lib.rs", "pub mod visit;\n"),
        (
            "src/visit/mod.rs",
            "pub fn walk() {}\n\n#[cfg(test)]\nmod tests;\n",
        ),
        (
            "src/visit/tests.rs",
            "#[test]\nfn walks() {\n    super::walk();\n}\n",
        ),
    ];
    let contributions = scan_fixture("out-of-line-test-module", &files);
    let module = contributions
        .iter()
        .flat_map(|contribution| contribution["nodes"].as_array().unwrap().clone())
        .find(|node| node["kind"] == "module" && node["canonical_name"] == "crate::visit::tests")
        .unwrap();

    assert_eq!(Value::Bool(true), module["attributes"]["test"], "{module}");
}

#[test]
fn re_exports_of_one_name_that_are_not_all_cfg_gated_resolve_to_nothing() {
    // An ungated re-export beside a gated one of the same name does not
    // compile in the gated build; nothing says which the code means.
    let files = [
        ("src/lib.rs", "pub mod user;\npub mod x;\n"),
        (
            "src/x/mod.rs",
            "mod a;\nmod b;\npub use a::run;\n#[cfg(not(unix))]\npub use b::run;\n",
        ),
        ("src/x/a.rs", "pub fn run() {}\n"),
        ("src/x/b.rs", "pub fn run() {}\n"),
        ("src/user.rs", "pub fn go() {\n    crate::x::run();\n}\n"),
    ];
    let contributions = scan_fixture("re-export-partly-gated", &files);
    let calls = targets_of(&contributions, "calls", "rust:function:crate::user::go");

    assert!(
        !calls
            .iter()
            .any(|target| target.ends_with("x::a::run") || target.ends_with("x::b::run")),
        "{calls:?}"
    );
}

#[test]
fn a_name_the_module_declares_and_imports_under_cfg_keeps_its_declaration() {
    // `LazyLock` is a re-export of `LazyCell` under one `cfg` and a struct
    // of its own under the other: its `impl` blocks belong to the struct,
    // never to `LazyCell`.
    let source = "pub struct LazyCell;\nimpl LazyCell {\n    pub fn new() -> Self {\n        LazyCell\n    }\n}\n#[cfg(not(feature = \"atomics\"))]\npub use LazyCell as LazyLock;\n#[cfg(feature = \"atomics\")]\npub struct LazyLock;\n#[cfg(feature = \"atomics\")]\nimpl LazyLock {\n    pub fn make() -> Self {\n        LazyLock\n    }\n}\n#[cfg(feature = \"atomics\")]\nunsafe impl Send for LazyLock {}\n";
    let contributions = scan_fixture("cfg-declared-and-imported", &[("src/lib.rs", source)]);
    let methods: Vec<String> = contributions[0]["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .filter(|node| node["kind"] == "method")
        .map(|node| node["canonical_name"].as_str().unwrap().to_owned())
        .collect();

    assert!(
        methods.contains(&"crate::LazyLock::make".to_owned()),
        "{methods:?}"
    );
    assert!(
        !methods.iter().any(|m| m.ends_with("LazyCell::make")),
        "{methods:?}"
    );
    assert_eq!(
        vec!["rust:interface:std::marker::Send".to_owned()],
        targets_of(&contributions, "implements", "rust:class:crate::LazyLock")
    );
}

#[test]
fn a_cfg_gated_declaration_carries_its_cfg_down_to_a_file_its_mod_loads() {
    // `#[cfg(target_os = "macos")] #[path = "svc/launchd.rs"] mod launchd;`
    // compiles that file only on macOS, so its `impl Runtime` is a cfg
    // alternative of the Linux one beside the type: the reconciler must not
    // read the two as one item declared twice.
    let svc = r#"
pub struct Runtime;
#[cfg(target_os = "linux")]
impl Runtime { pub fn inspect(&self) -> u8 { 1 } }
#[cfg(target_os = "macos")]
#[path = "svc/launchd.rs"]
mod launchd;
pub fn plain() {}
"#;
    let launchd = "use super::Runtime;\nimpl Runtime { pub fn inspect(&self) -> u8 { 2 } }\n";
    let contributions = scan_fixture(
        "cfg-alternatives",
        &[
            ("src/lib.rs", "pub mod svc;\n"),
            ("src/svc.rs", svc),
            ("src/svc/launchd.rs", launchd),
        ],
    );
    let cfg_of = |file: &str, name: &str| -> Option<String> {
        contributions
            .iter()
            .find(|contribution| contribution["owner_key"] == format!("knossos.rust:file:{file}"))
            .and_then(|contribution| {
                contribution["nodes"]
                    .as_array()
                    .unwrap()
                    .iter()
                    .find(|node| node["canonical_name"] == name)
                    .map(|node| node["attributes"]["cfg"].as_str().map(str::to_owned))
            })
            .flatten()
    };
    assert_eq!(
        Some("target_os = \"linux\"".to_owned()),
        cfg_of("src/svc.rs", "crate::svc::Runtime::inspect")
    );
    assert_eq!(
        Some("target_os = \"macos\"".to_owned()),
        cfg_of("src/svc/launchd.rs", "crate::svc::Runtime::inspect")
    );
    assert_eq!(
        Some("target_os = \"macos\"".to_owned()),
        cfg_of("src/svc/launchd.rs", "crate::svc::launchd")
    );
    assert_eq!(None, cfg_of("src/svc.rs", "crate::svc::plain"));
    assert_eq!(None, cfg_of("src/svc.rs", "crate::svc::Runtime"));
}

#[test]
fn a_file_a_path_attribute_loads_is_the_module_its_declaration_names() {
    // `#[cfg(test)] #[path = "budget_tests.rs"] mod tests;` makes
    // `src/mcp/budget_tests.rs` the test module `crate::mcp::budget::tests`,
    // not a production module `crate::mcp::budget_tests`; its `use super::*`
    // reaches the parent's private items, and so does a production module
    // mounted the same way.
    let budget = r#"
fn catalog_size() -> u8 { 1 }
pub fn budget() -> u8 { catalog_size() }
#[cfg(test)]
#[path = "budget_tests.rs"]
mod tests;
"#;
    let budget_tests = r#"
use super::*;
fn catalog() {}
#[test]
fn budget_works() { catalog(); budget(); }
"#;
    let broker = r#"
#[path = "broker_activation.rs"]
mod activation;
fn descendants() -> u8 { 1 }
pub fn run() { activation::activate(); }
"#;
    let activation = "use super::*;\npub fn activate() { let _ = descendants(); }\n";
    let files = [
        ("src/lib.rs", "pub mod broker;\npub mod mcp;\n"),
        ("src/mcp/mod.rs", "pub mod budget;\n"),
        ("src/mcp/budget.rs", budget),
        ("src/mcp/budget_tests.rs", budget_tests),
        ("src/broker.rs", broker),
        ("src/broker_activation.rs", activation),
    ];
    let contributions = scan_fixture("path-placed-modules", &files);

    let module = node_in(
        &contributions,
        "src/mcp/budget_tests.rs",
        "crate::mcp::budget::tests",
    )
    .expect("the test module is named by its declaration");
    assert_eq!(Some(true), module["attributes"]["test"].as_bool());
    let catalog = node_in(
        &contributions,
        "src/mcp/budget_tests.rs",
        "crate::mcp::budget::tests::catalog",
    )
    .expect("its items are below it");
    assert_eq!(Some(true), catalog["attributes"]["test"].as_bool());
    assert!(targets_of(
        &contributions,
        "calls",
        "rust:function:crate::mcp::budget::tests::budget_works"
    )
    .contains(&"rust:function:crate::mcp::budget::budget".to_owned()));
    assert!(node_in(
        &contributions,
        "src/broker_activation.rs",
        "crate::broker::activation"
    )
    .is_some());
    assert!(
        targets_of(
            &contributions,
            "calls",
            "rust:function:crate::broker::activation::activate"
        )
        .contains(&"rust:function:crate::broker::descendants".to_owned()),
        "{:?}",
        targets_of(
            &contributions,
            "calls",
            "rust:function:crate::broker::activation::activate"
        )
    );
    assert!(
        targets_of(&contributions, "calls", "rust:function:crate::broker::run")
            .contains(&"rust:function:crate::broker::activation::activate".to_owned())
    );

    // The placed file reads the file whose declaration placed it, so an
    // edit to that declaration rescans it.
    let reads = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/mcp/budget_tests.rs")
        .map(|c| {
            c["reads"]
                .as_object()
                .unwrap()
                .keys()
                .cloned()
                .collect::<Vec<_>>()
        })
        .unwrap();
    assert!(reads.contains(&"src/mcp/budget.rs".to_owned()), "{reads:?}");
}

#[test]
fn one_module_name_declared_under_exclusive_cfgs_is_one_module_in_two_files() {
    // `#[cfg(unix)] #[path = "preflight/unix.rs"] mod native;` beside a
    // Windows declaration of the same name: both files are
    // `crate::preflight::native`, so `native::Endpoint::standard()` and
    // `native::restore_fault()` reach their declarations, which are `cfg`
    // alternatives of one another.
    let preflight = r#"
#[cfg(unix)]
#[path = "preflight/unix.rs"]
mod native;
#[cfg(windows)]
#[path = "preflight/windows.rs"]
mod native;
pub fn go() {
    native::Endpoint::standard();
    native::restore_fault();
}
"#;
    let native =
        "pub struct Endpoint;\nimpl Endpoint { pub fn standard() {} }\npub fn restore_fault() {}\n";
    let contributions = scan_fixture(
        "cfg-alternative-modules",
        &[
            ("src/lib.rs", "pub mod preflight;\n"),
            ("src/preflight.rs", preflight),
            ("src/preflight/unix.rs", native),
            ("src/preflight/windows.rs", native),
        ],
    );
    for (file, cfg) in [
        ("src/preflight/unix.rs", "unix"),
        ("src/preflight/windows.rs", "windows"),
    ] {
        let endpoint = node_in(
            &contributions,
            file,
            "crate::preflight::native::Endpoint::standard",
        )
        .unwrap_or_else(|| panic!("{file} declares native::Endpoint::standard"));
        assert_eq!(Some(cfg), endpoint["attributes"]["cfg"].as_str());
    }
    let calls = targets_of(
        &contributions,
        "calls",
        "rust:function:crate::preflight::go",
    );
    for target in [
        "rust:method:crate::preflight::native::Endpoint::standard",
        "rust:function:crate::preflight::native::restore_fault",
    ] {
        assert!(calls.contains(&target.to_owned()), "{target} in {calls:?}");
    }
}

#[test]
fn code_gated_by_a_test_support_feature_is_marked_test_support() {
    // `#[cfg(feature = "test-support")]` and `#[cfg(any(test, feature =
    // "native-catalog-test-support"))] #[doc(hidden)]` compile only for the
    // tests, through a feature dependents' tests turn on; so does a module a
    // gated `mod` loads. `not(feature = "test-support")` is production.
    let lib = r#"
pub mod fixtures_host;
#[cfg(feature = "test-support")]
pub mod fixtures;
#[cfg(feature = "test-support")]
pub fn publication_fixture_error() {}
#[cfg(any(test, feature = "native-catalog-test-support"))]
#[doc(hidden)]
pub fn fixture_catalog() {}
#[cfg(not(feature = "test-support"))]
pub fn production_only() {}
#[cfg(feature = "latest-api")]
pub fn feature_gated() {}
"#;
    let contributions = scan_fixture(
        "test-support-feature",
        &[
            ("src/lib.rs", lib),
            ("src/fixtures.rs", "pub fn rig() {}\n"),
            ("src/fixtures_host.rs", "pub fn plain() {}\n"),
        ],
    );
    let support = |file: &str, name: &str| {
        node_in(&contributions, file, name)
            .unwrap_or_else(|| panic!("{name}"))
            .get("attributes")
            .and_then(|attributes| attributes.get("test_support"))
            .and_then(Value::as_bool)
            == Some(true)
    };
    assert!(support("src/lib.rs", "crate::publication_fixture_error"));
    assert!(support("src/lib.rs", "crate::fixture_catalog"));
    assert!(support("src/fixtures.rs", "crate::fixtures"));
    assert!(support("src/fixtures.rs", "crate::fixtures::rig"));
    assert!(!support("src/lib.rs", "crate::production_only"));
    assert!(!support("src/lib.rs", "crate::feature_gated"));
    assert!(!support(
        "src/fixtures_host.rs",
        "crate::fixtures_host::plain"
    ));
}

#[test]
fn a_file_a_test_module_includes_is_test_code() {
    // `src/tui/editor.rs` has `#[cfg(test)] mod tests { include!(
    // "../mcp_server/evidence_allocation_tests.rs"); }`: the included file is
    // part of that test module, though nothing declares it as a module, and
    // it read as an unreferenced production module.
    let editor = "pub fn edit() {}\n#[cfg(test)]\nmod tests {\n    include!(\"../mcp_server/evidence_allocation_tests.rs\");\n}\n";
    let contributions = scan_fixture(
        "include-in-test-module",
        &[
            ("src/lib.rs", "pub mod mcp_server;\npub mod tui;\n"),
            ("src/tui/mod.rs", "pub mod editor;\n"),
            ("src/tui/editor.rs", editor),
            ("src/mcp_server/mod.rs", "pub fn serve() {}\n"),
            (
                "src/mcp_server/evidence_allocation_tests.rs",
                "#[test]\nfn allocates() {}\nfn helper() {}\n",
            ),
            ("src/mcp_server/plain.rs", "pub fn plain() {}\n"),
        ],
    );
    let file = "src/mcp_server/evidence_allocation_tests.rs";
    let test_of = |canonical: &str| {
        node_in(&contributions, file, canonical).unwrap_or_else(|| panic!("{canonical}"))
            ["attributes"]["test"]
            .as_bool()
    };
    assert_eq!(
        Some(true),
        test_of("crate::mcp_server::evidence_allocation_tests")
    );
    assert_eq!(
        Some(true),
        test_of("crate::mcp_server::evidence_allocation_tests::helper")
    );
    let reads = contributions
        .iter()
        .find(|c| c["owner_key"] == format!("knossos.rust:file:{file}"))
        .map(|c| {
            c["reads"]
                .as_object()
                .unwrap()
                .keys()
                .cloned()
                .collect::<Vec<_>>()
        })
        .unwrap();
    assert!(reads.contains(&"src/tui/editor.rs".to_owned()), "{reads:?}");
    assert_eq!(
        None,
        node_in(
            &contributions,
            "src/mcp_server/plain.rs",
            "crate::mcp_server::plain"
        )
        .unwrap()["attributes"]["test"]
            .as_bool()
    );
}
