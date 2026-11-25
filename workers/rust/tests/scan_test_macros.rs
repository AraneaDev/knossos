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
fn a_package_open_to_another_crates_macros_freezes_none() {
    let cases = |opening: &str| {
        let lib = format!("pub const LIMIT: u32 = 10;\nmod other;\n{opening}\n")
            // rr3 :137.
            + &case_module("extern_macro_use", "", "todo!();\n        assert_eq!(LIMIT, 20);")
            // rr4/globs :11: `core` may be another crate.
            + &case_module("renamed_core", "", "core::todo!();\n        assert_eq!(LIMIT, 20);");
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
        ("renamed_core::case".to_owned(), unknown()),
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
    assert_eq!(none, cases("extern crate extmac as core;"));
    assert_eq!(none, cases("extern crate extmac as alloc;"));
    assert_eq!(none, cases("extern crate extmac as std;"));
    // A plain or otherwise renamed `extern crate` (other attributes, a
    // `cfg_attr` without `macro_use`) brings in no other crate's macro under
    // a standard name, and neither does a glob of any module: a
    // glob-imported `dbg!` is ambiguous beside the standard one (E0659).
    let open_to_none = vec![
        ("extern_macro_use::case".to_owned(), literal("10")),
        ("renamed_core::case".to_owned(), literal("10")),
        ("tests::case".to_owned(), literal("10")),
    ];
    assert_eq!(
        open_to_none,
        cases("#[cfg(test)]\n#[cfg_attr(test, allow(unused))]\nextern crate extmac as ext;\n#[cfg(test)]\nuse extmac::*;\nuse ::extmac::*;\nuse extmac::{prelude::*};\nuse self::inner::*;\nuse std::fmt::*;\npub mod inner {\n    use crate::other::*;\n}")
    );
}

#[test]
fn a_glob_from_another_crate_keeps_the_literals() {
    // rr4/recall: a `use proptest::prelude::*`-style glob in one file leaves
    // the genuine findings of another; a `use std::panic;` leaf still
    // unfreezes `panic`, and only it.
    let lib = "mod props;\nmod panics;\npub const LIMIT: u32 = 10;\npub fn limit() -> u32 {\n    LIMIT\n}\n".to_owned()
        + &case_module("constant", "", "assert_eq!(LIMIT, 20);")
        + &case_module("local", "", "let expected = 5;\n        assert_eq!(expected, 6);")
        + &case_module(
            "after_a_panic",
            "",
            "if limit() == 0 {\n            panic!(\"never\");\n        }\n        assert_eq!(LIMIT, 20);",
        );
    let found = first_operands(
        "macros-recall",
        &[
            ("src/lib.rs", &lib),
            (
                "src/props.rs",
                "#[cfg(test)]\nmod tests {\n    use proptest::prelude::*;\n}\n",
            ),
            (
                "src/panics.rs",
                "#[cfg(test)]\nmod tests {\n    use std::panic;\n}\n",
            ),
        ],
    );

    assert_eq!(
        vec![
            ("after_a_panic::case".to_owned(), unknown()),
            ("constant::case".to_owned(), literal("10")),
            ("local::case".to_owned(), literal("5")),
        ],
        found
    );
}

#[test]
fn a_member_package_open_to_other_macros_leaves_its_neighbour_frozen() {
    let lib = "pub const LIMIT: u32 = 10;\n".to_owned()
        + &case_module("tests", "", "dbg!();\n        assert_eq!(LIMIT, 20);");
    let member = "use extmac::dbg;\npub const LIMIT: u32 = 10;\n".to_owned()
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
            // Not the member's own module tree, but its package's.
            ("member/tests/it.rs", &member),
        ],
        &["Cargo.toml", "member/Cargo.toml"],
    );

    assert_eq!(
        vec![
            ("member/src/lib.rs tests::case".to_owned(), unknown()),
            ("member/tests/it.rs tests::case".to_owned(), unknown()),
            ("src/lib.rs tests::case".to_owned(), literal("10")),
        ],
        found
    );
}

#[test]
fn a_file_another_file_compiles_shadows_for_every_package() {
    let todo = "#[allow(unused_macros)]\nmacro_rules! todo {\n    () => {\n        const LIMIT: u32 = 20;\n    };\n}\n";
    let shared = "#[allow(unused_macros)]\nmacro_rules! dbg {\n    () => {\n        const LIMIT: u32 = 20;\n    };\n}\n";
    // rr4/keying2 src/lib.rs:14: a `#[path]` file outside `src/`.
    let keying2 =
        "#[macro_use]\n#[path = \"../macros/todo.rs\"]\nmod outside;\npub const LIMIT: u32 = 10;\n"
            .to_owned()
            + &case_module("tests", "", "todo!();\n        assert_eq!(LIMIT, 20);");
    // rr4/keying tests/it.rs:14 and :20: an integration test loading a
    // library file, and one outside both, by `#[path]`.
    let it = "#[macro_use]\n#[path = \"../src/shared.rs\"]\nmod shared;\n#[macro_use]\n#[path = \"../macros/todo.rs\"]\nmod outside;\nconst LIMIT: u32 = 10;\n#[test]\nfn shared_with_the_library() {\n    dbg!();\n    assert_eq!(LIMIT, 20);\n}\n#[test]\nfn placed_outside_src_and_tests() {\n    todo!();\n    assert_eq!(LIMIT, 20);\n}\n";
    let keying_lib =
        "#[macro_use]\n#[allow(unused_macros)]\nmod shared;\npub const LIMIT: u32 = 10;\n";
    // A package of its own still sees what a `#[path]` file brings in,
    // since that file's package cannot be shown.
    let member = "pub const LIMIT: u32 = 10;\n".to_owned()
        + &case_module("tests", "", "todo!();\n        assert_eq!(LIMIT, 20);");
    let files = [
        (
            "Cargo.toml",
            "[package]\nname = \"probe\"\n\n[workspace]\nmembers = [\"member\"]\n",
        ),
        ("member/Cargo.toml", "[package]\nname = \"member\"\n"),
        ("member/src/lib.rs", member.as_str()),
        ("macros/todo.rs", todo),
    ];
    let configs = ["Cargo.toml", "member/Cargo.toml"];

    let mut second = files.to_vec();
    second.push(("src/lib.rs", &keying2));
    assert_eq!(
        vec![
            ("member/src/lib.rs tests::case".to_owned(), unknown()),
            ("src/lib.rs tests::case".to_owned(), unknown()),
        ],
        operands_with("macros-keying2", &second, &configs)
    );
    let mut first = files.to_vec();
    first.extend([
        ("src/lib.rs", keying_lib),
        ("src/shared.rs", shared),
        ("tests/it.rs", it),
    ]);
    assert_eq!(
        vec![
            ("member/src/lib.rs tests::case".to_owned(), unknown()),
            (
                "tests/it.rs placed_outside_src_and_tests".to_owned(),
                unknown()
            ),
            ("tests/it.rs shared_with_the_library".to_owned(), unknown()),
        ],
        operands_with("macros-keying", &first, &configs)
    );
    // Without the `#[path]` file, each package's name is frozen again.
    let mut neither = files[..3].to_vec();
    neither.push(("src/lib.rs", "pub const LIMIT: u32 = 10;\n"));
    assert_eq!(
        vec![("member/src/lib.rs tests::case".to_owned(), literal("10"))],
        operands_with("macros-keying-none", &neither, &configs)
    );
}

#[test]
fn a_file_no_manifest_owns_shadows_for_every_package() {
    let member = "pub const LIMIT: u32 = 10;\n".to_owned()
        + &case_module("tests", "", "todo!();\n        assert_eq!(LIMIT, 20);");
    let found = operands_with(
        "macros-unowned",
        &[
            ("Cargo.toml", "[workspace]\nmembers = [\"member\"]\n"),
            ("member/Cargo.toml", "[package]\nname = \"member\"\n"),
            ("member/src/lib.rs", &member),
            (
                "scripts/helper.rs",
                "macro_rules! todo {\n    () => {};\n}\n",
            ),
        ],
        &["Cargo.toml", "member/Cargo.toml"],
    );

    assert_eq!(
        vec![("member/src/lib.rs tests::case".to_owned(), unknown())],
        found
    );
}

/// The keys of the `reads` of `file`'s contribution.
fn reads_of(contributions: &[Value], file: &str) -> Vec<String> {
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
}

#[test]
fn a_frozen_answer_reads_every_file_of_its_package() {
    // Any file of the package may start to shadow `dbg!` (re-review 3, I4),
    // so a frozen answer reads `macros.rs`, which the test file neither
    // declares in a module above it nor asks about, and the module a `mod`
    // declaration names whose file is missing (`src/plain/gone.rs`). An answer that is not frozen
    // reads nothing for it: when it goes stale, the name only stays unknown.
    // A file without tests asks nothing.
    let files = |shadowing: &str| {
        let lib = "#[macro_use]\nmod macros;\nmod plain;\npub const LIMIT: u32 = 10;\n".to_owned()
            + &case_module("tests", "", "dbg!();\n        assert_eq!(LIMIT, 20);");
        scan_fixture_with(
            "macros-reads",
            &[
                ("Cargo.toml", "[package]\nname = \"probe\"\n"),
                ("src/lib.rs", &lib),
                ("src/macros.rs", shadowing),
                ("src/plain.rs", "mod deep;\nmod gone;\npub fn plain() {}\n"),
                ("src/plain/deep.rs", "pub fn deep() {}\n"),
            ],
            &serde_json::json!({ "config_files": ["Cargo.toml"] }),
        )
    };
    let frozen = files("pub fn nothing() {}\n");
    // Both macros the test invokes, shadowed from another file.
    let shadowed = files("pub use extmac::{assert_eq, dbg};\n");

    let deep = [
        "src/plain/deep.rs".to_owned(),
        "src/plain/gone.rs".to_owned(),
    ];
    assert!(reads_of(&frozen, "src/lib.rs").contains(&"src/macros.rs".to_owned()));
    assert!(deep
        .iter()
        .all(|file| reads_of(&frozen, "src/lib.rs").contains(file)));
    assert!(!reads_of(&frozen, "src/plain.rs").contains(&"src/macros.rs".to_owned()));
    assert!(!deep
        .iter()
        .any(|file| reads_of(&shadowed, "src/lib.rs").contains(file)));
}
