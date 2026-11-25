//! Scanning tests of the standard macro names a test invokes where an item
//! may stand: a name stays frozen, declaring nothing, only while nothing in
//! the crate may put another macro under it. A macro brought in from outside
//! the crate (`use`, `#[macro_use] extern crate`, a glob) never counts as
//! frozen (ruling R3, widened), and the answer depends on every file of the
//! crate, which the test file reads (re-review 3, I4).

mod common;

use common::*;
use serde_json::Value;

/// A case of `body` in its own `#[cfg(test)]` module `name`, with `uses`
/// written above it.
fn case_module(name: &str, uses: &str, body: &str) -> String {
    format!(
        "#[cfg(test)]\nmod {name} {{\n    use super::*;\n    {uses}\n    #[test]\n    fn case() {{\n        {body}\n    }}\n}}\n"
    )
}

/// Every case's first operand, as [`first_operands`] gives it, for a tree
/// whose manifests are `configs`.
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

#[test]
fn a_macro_a_use_brings_in_is_not_frozen() {
    let lib = "pub const LIMIT: u32 = 10;\nuse extmac::eprint;\nmod child;\n".to_owned()
        // rr3 :112, :123 and :148.
        + &case_module("use_external", "use extmac::dbg;", "dbg!();\n        assert_eq!(LIMIT, 20);")
        + &case_module("use_external_renamed", "use extmac::dbg as todo;", "todo!();\n        assert_eq!(LIMIT, 20);")
        + "#[cfg(test)]\nmod use_external_module_level {\n    use super::*;\n    use extmac::unreachable;\n    unreachable!();\n    #[test]\n    fn case() {\n        assert_eq!(LIMIT, 20);\n    }\n}\n"
        + &case_module("use_self", "use extmac::print::{self};", "print!();\n        assert_eq!(LIMIT, 20);")
        + &case_module("use_in_body", "", "use extmac::format;\n        format!();\n        assert_eq!(LIMIT, 20);")
        // Bound nowhere in the crate, or only through a standard glob.
        + &case_module("control", "use std::collections::*;", "println!();\n        assert_eq!(LIMIT, 10);");
    // The `use` sits in another file of the crate, an ancestor's, which
    // the child's glob reaches; the constant is the child's own.
    let child = "use super::*;\npub const LIMIT: u32 = 10;\n".to_owned()
        + &case_module("tests", "", "eprint!();\n        assert_eq!(LIMIT, 20);");
    let found = first_operands(
        "macros-use-external",
        &[("src/lib.rs", &lib), ("src/child.rs", &child)],
    );

    assert_eq!(
        vec![
            ("control::case".to_owned(), literal("10")),
            ("tests::case".to_owned(), unknown()),
            ("use_external::case".to_owned(), unknown()),
            ("use_external_module_level::case".to_owned(), unknown()),
            ("use_external_renamed::case".to_owned(), unknown()),
            ("use_in_body::case".to_owned(), unknown()),
            ("use_self::case".to_owned(), unknown()),
        ],
        found
    );
}

#[test]
fn a_crate_open_to_another_crates_macros_freezes_none() {
    let cases = |opening: &str| {
        let lib = format!("pub const LIMIT: u32 = 10;\nmod other;\n{opening}\n")
            // rr3 :137.
            + &case_module("extern_macro_use", "", "todo!();\n        assert_eq!(LIMIT, 20);");
        // A constant of the test's own file, so only the macro decides.
        let other = "pub const LIMIT: u32 = 10;\n".to_owned()
            + &case_module("tests", "", "println!();\n        assert_eq!(LIMIT, 20);");
        first_operands(
            "macros-open-crate",
            &[("src/lib.rs", &lib), ("src/other.rs", &other)],
        )
    };
    let none = vec![
        ("extern_macro_use::case".to_owned(), unknown()),
        ("tests::case".to_owned(), unknown()),
    ];

    assert_eq!(
        none,
        cases("#[cfg(test)]\n#[macro_use]\nextern crate extmac as _ext;")
    );
    assert_eq!(
        none,
        cases("#[cfg_attr(test, macro_use)]\nextern crate extmac;")
    );
    assert_eq!(none, cases("#[cfg(test)]\nuse extmac::*;"));
    assert_eq!(none, cases("#[cfg(test)]\nuse ::extmac::*;"));
    assert_eq!(none, cases("#[cfg(test)]\nuse extmac::{prelude::*};"));
    // A plain `extern crate` (other attributes, a `cfg_attr` without
    // `macro_use`), and a glob of the crate's own or a standard
    // module, bring in no other crate's macro.
    let open_to_none = vec![
        ("extern_macro_use::case".to_owned(), literal("10")),
        ("tests::case".to_owned(), literal("10")),
    ];
    assert_eq!(
        open_to_none,
        cases("#[cfg(test)]\n#[cfg_attr(test, allow(unused))]\nextern crate extmac;\n#[cfg(test)]\nuse self::inner::*;\nuse std::fmt::*;\nuse core::cell::*;\nuse alloc_free::Thing;\npub mod inner {\n    use crate::other::*;\n}")
    );
}

#[test]
fn a_member_crate_open_to_other_macros_leaves_its_neighbour_frozen() {
    let lib = "pub const LIMIT: u32 = 10;\n".to_owned()
        + &case_module("tests", "", "dbg!();\n        assert_eq!(LIMIT, 20);");
    let member = "use extmac::*;\nuse extmac::dbg;\npub const LIMIT: u32 = 10;\n".to_owned()
        + &case_module("tests", "", "dbg!();\n        assert_eq!(LIMIT, 20);");
    let found = operands_with(
        "macros-member-crate",
        &[
            (
                "Cargo.toml",
                "[package]\nname = \"probe\"\n\n[workspace]\nmembers = [\"member\"]\n",
            ),
            ("src/lib.rs", &lib),
            ("member/Cargo.toml", "[package]\nname = \"member\"\n"),
            ("member/src/lib.rs", &member),
        ],
        &["Cargo.toml", "member/Cargo.toml"],
    );

    assert_eq!(
        vec![
            ("member/src/lib.rs tests::case".to_owned(), unknown()),
            ("src/lib.rs tests::case".to_owned(), literal("10")),
        ],
        found
    );
}

#[test]
fn a_test_file_reads_every_file_of_its_crate() {
    // Any file of the crate may start to shadow `dbg!` (re-review 3, I4), so
    // the test file reads `macros.rs`, which it neither declares in a module
    // above it nor asks about; a file without tests asks nothing.
    let lib = "#[macro_use]\nmod macros;\nmod plain;\npub const LIMIT: u32 = 10;\n".to_owned()
        + &case_module("tests", "", "dbg!();\n        assert_eq!(LIMIT, 20);");
    let contributions = scan_fixture_with(
        "macros-reads",
        &[
            ("Cargo.toml", "[package]\nname = \"probe\"\n"),
            ("src/lib.rs", &lib),
            ("src/macros.rs", "pub fn nothing() {}\n"),
            ("src/plain.rs", "pub fn plain() {}\n"),
        ],
        &serde_json::json!({ "config_files": ["Cargo.toml"] }),
    );
    let reads = |file: &str| -> Vec<String> {
        let contribution = contributions
            .iter()
            .find(|contribution| contribution["owner_key"] == format!("knossos.rust:file:{file}"))
            .unwrap();
        contribution["reads"]
            .as_object()
            .unwrap()
            .keys()
            .cloned()
            .collect()
    };

    assert!(reads("src/lib.rs").contains(&"src/macros.rs".to_owned()));
    assert!(reads("src/lib.rs").contains(&"src/plain.rs".to_owned()));
    assert!(!reads("src/plain.rs").contains(&"src/macros.rs".to_owned()));
}
