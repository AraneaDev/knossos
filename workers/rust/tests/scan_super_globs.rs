//! Names a child module reaches through `use super::*;` when the parent
//! has them only by a `use` of its own.

mod common;

use common::{scan_fixture, targets_of};

#[test]
fn a_call_to_a_function_the_parent_imports_privately_resolves_through_a_super_glob() {
    // `use super::*;` brings in what the parent can name, its private `use`
    // lines included: privacy lets a module's descendants see them.
    let contributions = scan_fixture(
        "call-through-super-glob-private-use",
        &[
            (
                "src/lib.rs",
                "mod visit;\npub fn run() -> String { visit::go() }\n",
            ),
            (
                "src/visit/mod.rs",
                "mod impls;\nmod labels;\nuse labels::label;\npub use impls::go;\n",
            ),
            (
                "src/visit/labels.rs",
                "pub(super) fn label() -> String { String::new() }\n",
            ),
            (
                "src/visit/impls.rs",
                "use super::*;\npub fn go() -> String { label() }\n",
            ),
        ],
    );

    let calls = targets_of(
        &contributions,
        "calls",
        "rust:function:crate::visit::impls::go",
    );
    assert!(
        calls.contains(&"rust:function:crate::visit::labels::label".to_owned()),
        "{calls:?}"
    );
}

#[test]
fn a_call_through_a_child_modules_re_export_resolves() {
    // `visit::go()` beside `mod visit;`: `visit` declares no `go`, it
    // re-exports the one its own child declares.
    let contributions = scan_fixture(
        "call-through-pub-use-of-child",
        &[
            (
                "src/lib.rs",
                "mod visit;\npub fn run() -> String { visit::go() }\n",
            ),
            ("src/visit/mod.rs", "mod impls;\npub use impls::go;\n"),
            (
                "src/visit/impls.rs",
                "pub fn go() -> String { String::new() }\n",
            ),
        ],
    );

    let calls = targets_of(&contributions, "calls", "rust:function:crate::run");
    assert!(
        calls.contains(&"rust:function:crate::visit::impls::go".to_owned()),
        "{calls:?}"
    );
}

#[test]
fn a_method_call_on_a_type_a_child_module_re_exports_resolves() {
    // `visit::Walk::new()` goes through the same re-export, one segment
    // further down.
    let contributions = scan_fixture(
        "assoc-through-pub-use-of-child",
        &[
            (
                "src/lib.rs",
                "mod visit;\npub fn run() { visit::Walk::new(); }\n",
            ),
            ("src/visit/mod.rs", "mod state;\npub use state::Walk;\n"),
            (
                "src/visit/state.rs",
                "pub struct Walk;\nimpl Walk {\n    pub fn new() -> Self { Walk }\n}\n",
            ),
        ],
    );

    let calls = targets_of(&contributions, "calls", "rust:function:crate::run");
    assert!(
        calls.contains(&"rust:method:crate::visit::state::Walk::new".to_owned()),
        "{calls:?}"
    );
}

#[test]
fn a_path_below_a_child_module_that_nothing_declares_is_still_no_call() {
    // The re-export names something no file declares: nothing is certain,
    // so no edge is made up.
    let contributions = scan_fixture(
        "pub-use-of-nothing",
        &[
            ("src/lib.rs", "mod visit;\npub fn run() { visit::go(); }\n"),
            ("src/visit/mod.rs", "mod impls;\npub use impls::go;\n"),
            ("src/visit/impls.rs", "pub struct Other;\n"),
        ],
    );

    assert_eq!(
        Vec::<String>::new(),
        targets_of(&contributions, "calls", "rust:function:crate::run")
    );
}
