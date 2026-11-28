//! Scanning tests of the `test_case` facts: which functions are cases, what
//! their assertions compare, and how much production each runs.

mod common;

use common::*;
use serde_json::{json, Value};

/// A Cargo manifest naming the fixture's package.
const MANIFEST: (&str, &str) = ("Cargo.toml", "[package]\nname = \"probe\"\n");

/// Scan `files` beside a manifest, returning contributions.
fn scan_crate(name: &str, files: &[(&str, &str)]) -> Vec<Value> {
    let mut all = vec![MANIFEST];
    all.extend_from_slice(files);
    scan_fixture_with(name, &all, &json!({"config_files": ["Cargo.toml"]}))
}

/// Every `test_case` node of one scan, by canonical name.
fn cases(contributions: &[Value]) -> Vec<Value> {
    contributions
        .iter()
        .flat_map(|contribution| contribution["nodes"].as_array().unwrap().clone())
        .filter(|node| node["kind"] == "test_case")
        .collect()
}

/// The attributes of the case named `name`.
fn case(contributions: &[Value], name: &str) -> Value {
    cases(contributions)
        .into_iter()
        .find(|node| node["attributes"]["name"] == name)
        .unwrap_or_else(|| panic!("no case {name}"))["attributes"]
        .clone()
}

/// `(api, operand count, negated)` of each assertion of a case.
fn shapes(attributes: &Value) -> Vec<(String, usize, bool)> {
    attributes["assertions"]
        .as_array()
        .unwrap()
        .iter()
        .map(|assertion| {
            (
                assertion["api"].as_str().unwrap().to_owned(),
                assertion["operands"].as_array().unwrap().len(),
                assertion["negated"] == true,
            )
        })
        .collect()
}

#[test]
fn finds_test_functions_in_every_form() {
    let lib = "pub fn add(a: u32, b: u32) -> u32 { a + b }\n\n#[cfg(test)]\nmod tests {\n    #[test] fn adds() { assert_eq!(super::add(1, 2), 3); }\n    #[tokio::test] async fn runs() {}\n    #[rstest] fn cased() {}\n    #[test_case(1)] fn numbered(x: u32) {}\n    #[cfg(test)] fn helper() {}\n    proptest! { #[test] fn prop(x in 0..1u32) { assert!(x < 1); } }\n    #[bench] fn timed(b: &mut Bencher) {}\n}\n";
    let it = "#[test]\nfn smoke() {}\n";
    let contributions = scan_crate("cases-forms", &[("src/lib.rs", lib), ("tests/it.rs", it)]);

    let mut found: Vec<(String, String)> = cases(&contributions)
        .iter()
        .map(|node| {
            (
                node["canonical_name"].as_str().unwrap().to_owned(),
                node["attributes"]["name"].as_str().unwrap().to_owned(),
            )
        })
        .collect();
    found.sort();
    let expected = [
        ("src/lib.rs#test:5", "tests::adds"),
        ("src/lib.rs#test:6", "tests::runs"),
        ("src/lib.rs#test:7", "tests::cased"),
        ("src/lib.rs#test:8", "tests::numbered"),
        ("tests/it.rs#test:2", "smoke"),
    ];
    assert_eq!(
        expected
            .iter()
            .map(|(a, b)| ((*a).to_owned(), (*b).to_owned()))
            .collect::<Vec<_>>(),
        found
    );
    let node = cases(&contributions)
        .into_iter()
        .find(|node| node["canonical_name"] == "src/lib.rs#test:5")
        .unwrap();
    assert_eq!("rust:test_case:src/lib.rs#test:5", node["local_id"]);
    assert_eq!("tests::adds", node["display_name"]);
    assert_eq!(true, node["attributes"]["test"]);
    assert_eq!(5, node["evidence"]["start_line"]);
    let lib_edges = &contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/lib.rs")
        .unwrap()["edges"];
    assert!(lib_edges.as_array().unwrap().iter().any(|edge| {
        edge["kind"] == "contains"
            && edge["source"] == "rust:module:crate::tests"
            && edge["target"] == "rust:test_case:src/lib.rs#test:5"
    }));
    let smoke = cases(&contributions)
        .into_iter()
        .find(|node| node["attributes"]["name"] == "smoke")
        .unwrap();
    let it_edges = &contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:tests/it.rs")
        .unwrap()["edges"];
    assert!(it_edges.as_array().unwrap().iter().any(|edge| {
        edge["kind"] == "contains"
            && edge["source"] == "rust:module:tests::it"
            && edge["target"] == smoke["local_id"]
    }));
}

#[test]
fn a_case_carries_its_scope_cfg() {
    let lib = "#[cfg(unix)]\nmod tests {\n    #[test]\n    fn on_unix() {}\n}\n";
    let contributions = scan_crate("cases-cfg", &[("src/lib.rs", lib)]);

    let node = &cases(&contributions)[0];
    assert_eq!("unix", node["attributes"]["cfg"]);
    assert_eq!(true, node["attributes"]["test"]);
}

#[test]
fn marks_should_panic() {
    let lib = "#[cfg(test)]\nmod tests {\n    #[test]\n    #[should_panic(expected = \"x\")]\n    fn boom() { panic!(\"x\"); }\n    #[test]\n    fn calm() {}\n}\n";
    let contributions = scan_crate("cases-panic", &[("src/lib.rs", lib)]);

    assert_eq!(true, case(&contributions, "tests::boom")["should_panic"]);
    assert_eq!(
        Value::Null,
        case(&contributions, "tests::calm")["should_panic"]
    );
}

#[test]
fn reads_assertion_forms() {
    let lib = r#"#[cfg(test)]
mod tests {
    #[test]
    fn forms() {
        assert_eq!(a, b, "m {}", c);
        assert_ne!(a, b);
        assert!(a == b);
        assert!(a != b);
        assert!(x);
        assert!(matches!(x, Some(_)));
        assert_matches!(x, Some(_));
        debug_assert_eq!(a, b);
        assert!(a < b && c);
        assert!(a < b);
        assert!(a <= b, "message {}", a);
        prop_assert!(a > b);
        debug_assert!(a >= b);
        std::assert_eq!(a, b);
        println!("{}", a);
        assert_eq!(a);
    }
}
"#;
    let contributions = scan_crate("cases-assertions", &[("src/lib.rs", lib)]);

    let attributes = case(&contributions, "tests::forms");
    let expected = [
        ("assert_eq", 2, false),
        ("assert_ne", 2, true),
        ("assert ==", 2, false),
        ("assert !=", 2, true),
        ("assert", 1, false),
        ("matches", 1, false),
        // `assert_matches!` and `prop_assert!` are outside the standard
        // prelude, so a glob may bring in another macro under either name:
        // neither is read as an assertion (Task 3 review, I2).
        ("debug_assert_eq", 2, false),
        ("assert", 1, false),
        ("assert <", 2, false),
        ("assert <=", 2, false),
        ("debug_assert >=", 2, false),
        ("assert_eq", 2, false),
    ];
    assert_eq!(
        expected
            .iter()
            .map(|(api, count, negated)| ((*api).to_owned(), *count, *negated))
            .collect::<Vec<_>>(),
        shapes(&attributes)
    );
    let lines: Vec<u64> = attributes["assertions"]
        .as_array()
        .unwrap()
        .iter()
        .map(|assertion| assertion["line"].as_u64().unwrap())
        .collect();
    assert_eq!(vec![5, 6, 7, 8, 9, 10, 12, 13, 14, 15, 17, 18], lines);
}

#[test]
fn counts_production_calls_and_subjects() {
    let lib = r#"pub mod repo;
pub mod clock;

#[cfg(test)]
mod tests {
    use crate::repo::Repo;
    use crate::clock::MockClock;

    #[test]
    fn saves() { Repo::new().save(1); }
    #[test]
    fn builds() { let v = vec![1]; let s = String::from("a"); }
    #[test]
    fn untyped() { let thing = make(); thing.go(); }
    #[test]
    fn helper_only() { make(); }
    #[test]
    fn untyped_in_macro() { let thing = make(); assert!(thing.go()); }
    #[test]
    fn nested() { fn inner() { crate::repo::Repo::new(); } }
    #[test]
    fn closure() { let f = || Repo::new(); f(); }
    #[test]
    fn mocked() { MockClock::new(); }
    #[test]
    fn in_macro() { assert_eq!(Repo::new().count(), 0); }
    #[test]
    fn same_file() { crate::local(); }

    fn make() -> u32 { 1 }
}

pub fn local() {}
"#;
    let repo = "pub struct Repo;\nimpl Repo {\n    pub fn new() -> Self { Repo }\n    pub fn save(&self, _x: u32) {}\n    pub fn count(&self) -> u32 { 0 }\n}\n";
    let clock =
        "#[cfg_attr(test, mockall::automock)]\npub trait Clock {\n    fn now(&self) -> u64;\n}\n";
    let contributions = scan_crate(
        "cases-production",
        &[
            ("src/lib.rs", lib),
            ("src/repo.rs", repo),
            ("src/clock.rs", clock),
        ],
    );

    let reach = |name: &str| {
        let attributes = case(&contributions, name);
        (
            attributes["production_calls"].as_u64().unwrap(),
            attributes["subjects"].clone(),
        )
    };
    assert_eq!((2, json!(["src/repo.rs"])), reach("tests::saves"));
    assert_eq!((0, json!([])), reach("tests::builds"));
    assert_eq!((1, json!([])), reach("tests::untyped"));
    assert_eq!((0, json!([])), reach("tests::helper_only"));
    assert_eq!((1, json!([])), reach("tests::untyped_in_macro"));
    assert_eq!((0, json!([])), reach("tests::nested"));
    assert_eq!((1, json!(["src/repo.rs"])), reach("tests::closure"));
    assert_eq!((0, json!([])), reach("tests::mocked"));
    assert_eq!((2, json!(["src/repo.rs"])), reach("tests::in_macro"));
    assert_eq!((1, json!(["src/lib.rs"])), reach("tests::same_file"));
    let operand = &case(&contributions, "tests::in_macro")["assertions"][0]["operands"][0];
    assert_eq!(&json!({"source": "production"}), operand);
}

#[test]
fn test_modules_of_other_files_are_not_production() {
    let lib = "pub mod repo;\n#[cfg(test)]\nmod support;\n#[cfg(test)]\nmod tests {\n    #[test]\n    fn uses_support() { crate::support::fixture(); crate::repo::make(); }\n}\n";
    let contributions = scan_crate(
        "cases-test-module",
        &[
            ("src/lib.rs", lib),
            ("src/support.rs", "pub fn fixture() {}\n"),
            ("src/repo.rs", "pub fn make() {}\n"),
        ],
    );

    let attributes = case(&contributions, "tests::uses_support");
    assert_eq!(1, attributes["production_calls"]);
    assert_eq!(json!(["src/repo.rs"]), attributes["subjects"]);
}

#[test]
fn a_workspace_member_keeps_its_own_tests_directory() {
    let it = "mod common;\n#[test]\nfn smoke() {\n    common::setup();\n    core_lib::run();\n}\n";
    let contributions = scan_fixture_with(
        "cases-workspace",
        &[
            ("Cargo.toml", "[workspace]\nmembers = [\"crates/core\"]\n"),
            ("crates/core/Cargo.toml", "[package]\nname = \"core-lib\"\n"),
            ("crates/core/src/lib.rs", "pub fn run() {}\n"),
            ("crates/core/tests/it.rs", it),
            ("crates/core/tests/common/mod.rs", "pub fn setup() {}\n"),
        ],
        &json!({"config_files": ["Cargo.toml", "crates/core/Cargo.toml"]}),
    );

    let smoke = case(&contributions, "smoke");
    assert_eq!(1, smoke["production_calls"]);
    assert_eq!(json!(["crates/core/src/lib.rs"]), smoke["subjects"]);
}

#[test]
fn integration_tests_count_the_library_and_not_their_helpers() {
    let lib = "pub mod repo;\n";
    let repo = "pub struct Repo;\nimpl Repo {\n    pub fn new() -> Self { Repo }\n}\n";
    let it = "mod common;\nuse probe::repo::Repo;\n#[test]\nfn smoke() {\n    let r = Repo::new();\n    common::setup();\n    local();\n}\nfn local() {}\n";
    let common = "pub fn setup() {}\n";
    let contributions = scan_crate(
        "cases-integration",
        &[
            ("src/lib.rs", lib),
            ("src/repo.rs", repo),
            ("tests/it.rs", it),
            ("tests/common/mod.rs", common),
        ],
    );

    let smoke = case(&contributions, "smoke");
    assert_eq!(1, smoke["production_calls"]);
    assert_eq!(json!(["src/repo.rs"]), smoke["subjects"]);
}

#[test]
fn case_parameters_are_parameters() {
    let lib = "#[cfg(test)]\nmod tests {\n    #[rstest]\n    #[case(1)]\n    fn cased(#[case] x: u32) { assert_eq!(x, 1); }\n}\n";
    let contributions = scan_crate("cases-parameters", &[("src/lib.rs", lib)]);

    assert_eq!(
        json!([{"source": "parameter"}, {"source": "literal", "text": "1"}]),
        case(&contributions, "tests::cased")["assertions"][0]["operands"]
    );
}

#[test]
fn non_test_files_and_orphans_give_no_test_facts() {
    let lib = "pub fn add() {}\n#[rstest]\nfn outside() {}\n";
    let orphan = "#[test]\nfn lost() {}\n";
    let contributions = scan_crate(
        "cases-orphans",
        &[("src/lib.rs", lib), ("src/orphan.rs", orphan)],
    );

    assert!(cases(&contributions).is_empty());
    let orphan = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/orphan.rs")
        .unwrap();
    assert!(orphan["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .any(|node| node["local_id"] == "rust:function:crate::orphan::lost"));
}

#[test]
fn a_module_in_its_own_mod_rs_is_no_orphan() {
    // `mod net;` loads `src/net/mod.rs` as much as `src/net.rs` (phrourion's
    // `src/tui/mod.rs`), and so does `mod http;` there.
    let lib = "pub mod net;\n";
    let net = "pub mod http;\n#[test]\nfn kept() {}\n";
    let http = "#[test]\nfn deeper() {}\n";
    let contributions = scan_crate(
        "cases-mod-rs",
        &[
            ("src/lib.rs", lib),
            ("src/net/mod.rs", net),
            ("src/net/http/mod.rs", http),
        ],
    );

    let names: Vec<Value> = cases(&contributions)
        .into_iter()
        .map(|node| node["attributes"]["name"].clone())
        .collect();
    assert_eq!(vec![json!("deeper"), json!("kept")], names);
}

#[test]
fn output_is_stable_across_runs() {
    // The repository the worker sits in, or the one a copy of the worker
    // was taken from (`cargo mutants` builds a copy of this crate alone).
    let repository = std::env::var("KNOSSOS_REPOSITORY").map_or_else(
        |_| std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../.."),
        Into::into,
    );
    let root = repository
        .join("tests/Fixtures/test-fidelity/rust/healthy-idioms")
        .canonicalize()
        .unwrap();
    let mut files = Vec::new();
    rust_files(&root, &root, &mut files);
    files.sort();
    let request = json!({
        "jsonrpc": "2.0", "id": 1, "method": "scan",
        "params": {
            "limits": {"max_files": 1000, "max_file_bytes": 2_000_000},
            "root": root.to_str().unwrap(), "files": files, "config_files": ["Cargo.toml"],
        },
    });
    let run = || {
        use std::io::Write;
        let mut child = std::process::Command::new(env!("CARGO_BIN_EXE_knossos-rust-worker"))
            .stdin(std::process::Stdio::piped())
            .stdout(std::process::Stdio::piped())
            .spawn()
            .unwrap();
        child
            .stdin
            .take()
            .unwrap()
            .write_all(format!("{request}\n").as_bytes())
            .unwrap();
        // A heartbeat goes out by the clock, so where one falls differs
        // between runs under load (G5-09); everything else must not.
        String::from_utf8(child.wait_with_output().unwrap().stdout)
            .unwrap()
            .lines()
            .filter(|line| !line.contains("\"method\":\"scan/heartbeat\""))
            .map(|line| format!("{line}\n"))
            .collect::<String>()
    };

    let first = run();
    assert_eq!(first, run());
    assert!(first.contains("\"kind\":\"test_case\""));
}

/// Every `.rs` file below `directory`, relative to `root`.
fn rust_files(root: &std::path::Path, directory: &std::path::Path, out: &mut Vec<String>) {
    for entry in std::fs::read_dir(directory).unwrap() {
        let path = entry.unwrap().path();
        if path.is_dir() {
            rust_files(root, &path, out);
        } else if path.extension().is_some_and(|extension| extension == "rs") {
            out.push(
                path.strip_prefix(root)
                    .unwrap()
                    .to_str()
                    .unwrap()
                    .to_owned(),
            );
        }
    }
}
