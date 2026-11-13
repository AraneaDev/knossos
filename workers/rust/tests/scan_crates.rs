//! Scanning tests driving the worker against real files on disk:
//! crate roots, packages, workspace members and executables.

mod common;

use serde_json::Value;

use common::*;

#[test]
fn a_crate_root_main_marks_the_module_executable() {
    let contributions = scan_fixture("executable-main", &[("src/main.rs", "fn main() {}\n")]);
    let module = contributions[0]["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .find(|node| node["kind"] == "module")
        .unwrap();
    assert_eq!(serde_json::json!(true), module["attributes"]["executable"]);
}

#[test]
fn a_library_file_without_main_is_not_executable() {
    let contributions = scan_fixture("not-executable", &[("src/lib.rs", "pub fn go() {}\n")]);
    let module = contributions[0]["nodes"].as_array().unwrap()[0].clone();
    assert_eq!(serde_json::json!({}), module["attributes"]);
}

#[test]
fn a_crate_manifest_adds_a_package_node_to_the_crate_root() {
    let contributions = scan_fixture_with(
        "crate-package",
        &[
            ("Cargo.toml", "[package]\nname = \"demo\"\n"),
            ("src/main.rs", "fn main() {}\n"),
        ],
        &serde_json::json!({ "config_files": ["Cargo.toml"] }),
    );
    let main_contribution = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/main.rs")
        .unwrap();
    let nodes = main_contribution["nodes"].as_array().unwrap();
    let package = nodes
        .iter()
        .find(|node| node["kind"] == "package")
        .expect("crate package node missing");
    assert_eq!("demo", package["canonical_name"]);
    let edges = main_contribution["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "contains"
            && edge["source"] == "rust:package:demo"
            && edge["target"] == "rust:module:crate"
    }));
}

#[test]
fn a_package_with_library_and_binary_roots_keeps_the_roots_distinct() {
    let contributions = scan_fixture_with(
        "crate-library-and-binary",
        &[
            (
                "Cargo.toml",
                "[package]\nname = \"demo\"\n\n[lib]\nname = \"demo\"\n",
            ),
            ("src/lib.rs", "pub struct Library;\n"),
            ("src/main.rs", "fn main() {}\n"),
        ],
        &serde_json::json!({ "config_files": ["Cargo.toml"] }),
    );
    let library = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/lib.rs")
        .unwrap();
    let binary = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/main.rs")
        .unwrap();
    assert!(library["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .any(|node| { node["kind"] == "module" && node["canonical_name"] == "crate" }));
    assert!(binary["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .any(|node| { node["kind"] == "module" && node["canonical_name"] == "crate::main" }));
    assert!(binary["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .any(|node| { node["kind"] == "package" && node["canonical_name"] == "demo" }));
    assert!(binary["edges"].as_array().unwrap().iter().any(|edge| {
        edge["kind"] == "contains"
            && edge["source"] == "rust:package:demo"
            && edge["target"] == "rust:module:crate::main"
    }));
}

#[test]
fn a_super_chain_escaping_the_crate_root_emits_no_import_edge() {
    let source = "use super::super::super::Unreachable;\n";
    let contributions = scan_fixture("use-super-escapes-root", &[("src/net.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(
        !edges.iter().any(|edge| edge["kind"] == "imports"),
        "a super chain longer than the module path names nothing resolvable"
    );
}

#[test]
fn a_workspace_members_modules_are_rooted_at_its_crate_name() {
    // A member crate's `src/` is its own crate root. Named by directory, its
    // modules came out as `crates::engine::src::app`, so every `crate::` path
    // inside it and every `use engine::...` from a sibling crate named
    // nothing, and the whole crate read as unreferenced.
    let files = [
        ("Cargo.toml", "[workspace]\nmembers = [\"crates/*\"]\n"),
        ("crates/core-lib/Cargo.toml", "[package]\nname = \"core-lib\"\n"),
        ("crates/core-lib/src/lib.rs", "pub mod app;\n"),
        (
            "crates/core-lib/src/app.rs",
            "pub struct Engine;\npub fn boot() -> Engine { crate::app::helper(); Engine }\nfn helper() {}\n",
        ),
        ("crates/cli/Cargo.toml", "[package]\nname = \"cli\"\n"),
        ("crates/cli/src/main.rs", "use core_lib::app::boot;\nfn main() { let _e = boot(); }\n"),
    ];
    let contributions = scan_fixture_with(
        "workspace-members",
        &files,
        &serde_json::json!({
            "config_files": ["Cargo.toml", "crates/cli/Cargo.toml", "crates/core-lib/Cargo.toml"],
        }),
    );
    let modules: Vec<String> = contributions
        .iter()
        .flat_map(|contribution| contribution["nodes"].as_array().unwrap().clone())
        .filter(|node| node["kind"] == "module")
        .map(|node| node["canonical_name"].as_str().unwrap().to_owned())
        .collect();
    let calls = edges_of(&contributions, "calls");

    assert!(modules.contains(&"core_lib::app".to_owned()), "{modules:?}");
    assert!(modules.contains(&"cli".to_owned()), "{modules:?}");
    assert!(
        calls.contains(&(
            "rust:function:core_lib::app::boot".to_owned(),
            "rust:function:core_lib::app::helper".to_owned()
        )),
        "{calls:?}"
    );
    assert!(
        calls.contains(&(
            "rust:function:cli::main".to_owned(),
            "rust:function:core_lib::app::boot".to_owned()
        )),
        "{calls:?}"
    );
}

#[test]
fn a_library_crate_root_is_entered_from_outside() {
    // A library's `lib.rs` is entered by its dependents, and a `cdylib` or
    // wasm crate by a host outside the repository. Nothing in the graph
    // imports it, so its module read as dead.
    let files = [
        ("Cargo.toml", "[package]\nname = \"engine\"\n"),
        ("src/lib.rs", "pub mod grid;\n"),
        ("src/grid.rs", "pub fn get() {}\n"),
    ];
    let contributions = scan_fixture_with(
        "library-root",
        &files,
        &serde_json::json!({ "config_files": ["Cargo.toml"] }),
    );
    let executable: Vec<(String, bool)> = contributions
        .iter()
        .flat_map(|contribution| contribution["nodes"].as_array().unwrap().clone())
        .filter(|node| node["kind"] == "module")
        .map(|node| {
            (
                node["canonical_name"].as_str().unwrap().to_owned(),
                node["attributes"]["executable"] == Value::Bool(true),
            )
        })
        .collect();

    assert!(
        executable.contains(&("crate".to_owned(), true)),
        "{executable:?}"
    );
    assert!(
        executable.contains(&("crate::grid".to_owned(), false)),
        "{executable:?}"
    );
}

#[test]
fn a_manifest_bin_target_is_an_executable_crate_root_using_the_library() {
    // `[[bin]] path = "src/build_dict.rs"` is a crate of its own that uses
    // the library by its crate name. Read as a library module nothing
    // declares, and with `from_bytes(..).expect(..)` losing the receiver's
    // type, the library methods only the binary calls read as unreferenced.
    let files = [
        (
            "Cargo.toml",
            "[package]\nname = \"glyph-solver\"\n\n[lib]\ncrate-type = [\"cdylib\", \"rlib\"]\n\n[[bin]]\nname = \"build-dict\"\npath = \"src/build_dict.rs\"\n\n[[bin]]\nname = \"bench\"\npath = \"src/tools/bench.rs\"\n",
        ),
        ("src/lib.rs", "pub mod trie;\n"),
        (
            "src/trie.rs",
            "pub struct PackedTrie;\nimpl PackedTrie {\n    pub fn from_bytes(_: &[u8]) -> Result<Self, String> { Ok(PackedTrie) }\n    pub fn build() -> Option<Self> { Some(PackedTrie) }\n    pub fn contains(&self, _: &str) -> bool { true }\n    pub fn is_common(&self, _: &str) -> bool { true }\n}\n",
        ),
        (
            "src/build_dict.rs",
            "mod report;\nuse glyph_solver::trie::PackedTrie;\nfn verify(bytes: &[u8]) {\n    let loaded = PackedTrie::from_bytes(bytes).expect(\"valid\");\n    assert!(loaded.contains(\"a\"));\n    let built = PackedTrie::build().unwrap_or_else(|| panic!(\"none\"));\n    built.is_common(\"a\");\n    crate::report::done();\n}\nfn main() {\n    verify(&[]);\n}\n",
        ),
        ("src/report.rs", "pub fn done() {}\n"),
        (
            "src/tools/bench.rs",
            "#![no_main]\nmod helper;\npub fn run() {\n    helper::go();\n}\n",
        ),
        ("src/tools/helper.rs", "pub fn go() {}\n"),
    ];
    let contributions = scan_fixture_with(
        "manifest-bin-target",
        &files,
        &serde_json::json!({ "config_files": ["Cargo.toml"] }),
    );
    let calls = method_calls(&contributions, "rust:function:crate::build_dict::verify");

    for target in [
        "rust:method:crate::trie::PackedTrie::contains",
        "rust:method:crate::trie::PackedTrie::is_common",
    ] {
        assert!(
            calls.iter().any(|(called, _)| called == target),
            "{target} in {calls:?}"
        );
    }
    assert!(
        calls
            .iter()
            .any(|(called, _)| called == "rust:function:crate::report::done"),
        "{calls:?}"
    );
    // A binary root keeps the files its `mod` declarations load beside it.
    let bench = method_calls(&contributions, "rust:function:crate::tools::bench::run");
    assert!(
        bench
            .iter()
            .any(|(called, _)| called == "rust:function:crate::tools::helper::go"),
        "{bench:?}"
    );
    let executable: Vec<String> = contributions
        .iter()
        .flat_map(|contribution| contribution["nodes"].as_array().unwrap().clone())
        .filter(|node| node["kind"] == "module" && node["attributes"]["executable"] == true)
        .map(|node| node["canonical_name"].as_str().unwrap().to_owned())
        .collect();
    // A declared binary is entered by Cargo, `fn main` or not.
    assert!(
        executable.contains(&"crate::tools::bench".to_owned()),
        "{executable:?}"
    );
}
