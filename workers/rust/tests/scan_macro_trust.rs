//! Scanning tests of what the worker trusts about a package's macros: a
//! package nested in another's module tree (G5-04), and the
//! `pretty_assertions` comparisons, read as assertions only when the name
//! is the real crate (G5-07).

mod common;

use common::*;
use serde_json::Value;

/// Every case's first operand by `file case`, for a tree whose manifests
/// are `configs`.
fn operands_with(name: &str, files: &[(&str, &str)], configs: &[&str]) -> Vec<(String, Value)> {
    let contributions =
        scan_fixture_with(name, files, &serde_json::json!({ "config_files": configs }));
    let mut found: Vec<(String, Value)> = contributions
        .iter()
        .flat_map(|contribution| {
            let nodes = contribution["nodes"].as_array().unwrap().iter();
            nodes.map(move |node| (contribution, node))
        })
        .filter(|(_, node)| node["kind"] == "test_case")
        .map(|(contribution, node)| {
            (
                format!(
                    "{} {}",
                    contribution["owner_key"]
                        .as_str()
                        .unwrap()
                        .trim_start_matches("knossos.rust:file:"),
                    node["attributes"]["name"].as_str().unwrap()
                ),
                node["attributes"]["assertions"][0]["operands"][0].clone(),
            )
        })
        .collect();
    found.sort_by(|a, b| a.0.cmp(&b.0));
    found
}

/// The rr5 `nested` probe: a package whose manifest sits in a module
/// directory of the outer one, reached by plain `#[macro_use] mod`s, with
/// `inner` as its `mod.rs`.
fn nested(name: &str, inner: &str) -> Vec<(String, Value)> {
    let lib = "#[macro_use]\nmod fixtures;\n\npub const LIMIT: u32 = 10;\n\n#[cfg(test)]\nmod tests {\n    use super::*;\n\n    #[test]\n    fn nested_manifest() {\n        todo!();\n        assert_eq!(LIMIT, 20);\n    }\n}\n";
    operands_with(
        name,
        &[
            (
                "Cargo.toml",
                "[package]\nname = \"nested\"\nversion = \"0.1.0\"\nedition = \"2021\"\n",
            ),
            ("src/lib.rs", lib),
            ("src/fixtures/mod.rs", "#[macro_use]\nmod inner;\n"),
            (
                "src/fixtures/inner/Cargo.toml",
                "[package]\nname = \"inner\"\nversion = \"0.1.0\"\nedition = \"2021\"\n\n[lib]\npath = \"mod.rs\"\n",
            ),
            ("src/fixtures/inner/mod.rs", inner),
        ],
        &["Cargo.toml", "src/fixtures/inner/Cargo.toml"],
    )
}

#[test]
fn a_package_nested_in_a_module_tree_shadows_for_every_package() {
    // rr5/nested src/lib.rs:13: the outer crate compiles the inner
    // package's `mod.rs`, whose `todo!` declares `LIMIT`. Which package
    // it belongs to cannot be shown, so it shadows for all (G5-04).
    let shadowing = "#[allow(unused_macros)]\nmacro_rules! todo {\n    ($($t:tt)*) => {\n        const LIMIT: u32 = 20;\n    };\n}\n";
    assert_eq!(
        vec![("src/lib.rs tests::nested_manifest".to_owned(), unknown())],
        nested("trust-nested-shadow", shadowing)
    );
    // Nothing in it shadows `todo`: the name stays frozen, and the
    // constant is still read.
    assert_eq!(
        vec![(
            "src/lib.rs tests::nested_manifest".to_owned(),
            literal("10")
        )],
        nested("trust-nested-none", "pub fn nothing() {}\n")
    );
}

#[test]
fn a_file_of_a_package_nested_in_a_module_tree_has_no_test_facts() {
    // Its `crate` may be either package's: none of its cases is read.
    let inner = "pub const LIMIT: u32 = 10;\n#[cfg(test)]\nmod tests {\n    use super::*;\n    #[test]\n    fn inner_case() {\n        assert_eq!(LIMIT, 20);\n    }\n}\n";
    let found = nested("trust-nested-facts", inner);

    assert_eq!(
        vec!["src/lib.rs tests::nested_manifest".to_owned()],
        found.into_iter().map(|(case, _)| case).collect::<Vec<_>>()
    );
}

/// The first operand of the one case of a crate whose manifest is
/// `manifest`, `lib` written above a test comparing `LIMIT` (10) with 20
/// through `pretty_assertions`.
fn pretty(manifest: &str, lib: &str) -> Value {
    let source = format!("pub const LIMIT: u32 = 10;\n{lib}\n")
        + "#[cfg(test)]\nmod tests {\n    use super::*;\n    use pretty_assertions::{assert_eq, assert_ne};\n    #[test]\n    fn case() {\n        assert_eq!(LIMIT, 20);\n        assert_ne!(LIMIT, 20);\n    }\n}\n";
    let found = operands_with(
        "trust-pretty",
        &[("Cargo.toml", manifest), ("src/lib.rs", &source)],
        &["Cargo.toml"],
    );
    assert_eq!(1, found.len());
    found[0].1.clone()
}

const PACKAGE: &str = "[package]\nname = \"probe\"\n";

#[test]
fn pretty_assertions_are_read_only_from_the_real_crate() {
    let depends = |table: &str| format!("{PACKAGE}\n{table}\n");
    // The dependency of that name, as Cargo writes it.
    for table in [
        "[dev-dependencies]\npretty_assertions = \"1\"",
        "[dependencies]\npretty_assertions = { version = \"1\" }",
        "[dev-dependencies]\npretty_assertions = { version = \"1\", package = \"pretty_assertions\" }",
        "[dev-dependencies.pretty_assertions]\nversion = \"1\"",
        "[target.'cfg(unix)'.dev-dependencies]\npretty_assertions = \"1\"",
        "[dev-dependencies]\npretty_assertions.version = \"1\"",
        // Not inherited: only `workspace = true` is.
        "[dependencies]\npretty_assertions = { version = \"1\", optional = true }",
        // Another dependency's rename is its own.
        "[dev-dependencies]\npretty_assertions = \"1\"\nother.package = \"other-assertions\"",
    ] {
        assert_eq!(literal("10"), pretty(&depends(table), ""), "{table}");
    }
    // G5-07: another crate under the name, or no dependency of it at all.
    // Neither macro is then an assertion, so nothing is read.
    for table in [
        "[dev-dependencies]\npretty_assertions = { version = \"1\", package = \"other-assertions\" }",
        "[dev-dependencies.pretty_assertions]\npackage = \"other-assertions\"",
        "[dev-dependencies]\npretty_assertions.package = \"other-assertions\"",
        "[dev-dependencies]\nother_assertions = \"1\"",
        // Tables that hold no dependency.
        "[features]\npretty_assertions = []",
        "[package.metadata.dependencies]\npretty_assertions = \"1\"",
        "",
    ] {
        assert_eq!(Value::Null, pretty(&depends(table), ""), "{table}");
    }
    let real = depends("[dev-dependencies]\npretty_assertions = \"1\"\nother = \"1\"");
    // The name bound to another crate, or to a module of the package.
    for lib in [
        "#[cfg(test)]\nextern crate other as pretty_assertions;",
        "mod pretty_assertions {\n    pub use other::*;\n}",
        "use other as pretty_assertions;",
        "use other::pretty_assertions;",
        "use other::pretty_assertions::{self};",
    ] {
        assert_eq!(Value::Null, pretty(&real, lib), "{lib}");
    }
    // The crate itself, under its own name.
    for lib in [
        "extern crate pretty_assertions;",
        "use ::pretty_assertions;",
        "use pretty_assertions::{self};",
    ] {
        assert_eq!(literal("10"), pretty(&real, lib), "{lib}");
    }
}

#[test]
fn a_workspace_dependency_is_the_workspace_s() {
    let member = |root: &str| {
        let lib = "pub const LIMIT: u32 = 10;\n#[cfg(test)]\nmod tests {\n    use super::*;\n    use pretty_assertions::assert_eq;\n    #[test]\n    fn case() {\n        assert_eq!(LIMIT, 20);\n    }\n}\n";
        operands_with(
            "trust-workspace",
            &[
                ("Cargo.toml", root),
                (
                    "member/Cargo.toml",
                    "[package]\nname = \"member\"\n\n[dev-dependencies]\npretty_assertions = { workspace = true }\n",
                ),
                ("member/src/lib.rs", lib),
            ],
            &["Cargo.toml", "member/Cargo.toml"],
        )[0]
            .1
            .clone()
    };

    assert_eq!(
        literal("10"),
        member("[workspace]\nmembers = [\"member\"]\n\n[workspace.dependencies]\npretty_assertions = \"1\"\n")
    );
    assert_eq!(
        Value::Null,
        member("[workspace]\nmembers = [\"member\"]\n\n[workspace.dependencies]\npretty_assertions = { version = \"1\", package = \"other\" }\n")
    );
    assert_eq!(Value::Null, member("[workspace]\nmembers = [\"member\"]\n"));
}
