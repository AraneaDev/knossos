//! Scanning tests driving the worker against real files on disk:
//! `use` declarations, `imports` edges and re-exports.

mod common;

use common::*;

#[test]
fn use_declarations_become_import_edges_pointing_at_the_containing_module() {
    // A `use` names a struct, trait, or function, whose node kind is `class`,
    // `interface`, or `function` — never `module`. Targeting the symbol's own
    // path as a module therefore resolved against nothing, and made the
    // reconciler synthesise an external module twinning the very symbol the
    // project had already declared. The edge points at the module that holds
    // the symbol instead, which is a node the graph really has, and the symbol
    // keeps going into the alias map, the same split the Python worker makes
    // in `workers/python/bin/knossos_python/collector.py`.
    let source = r#"
use std::collections::HashMap;
use serde::{Serialize, Deserialize as De};
use crate::net::http;
use serde_json;

pub fn go() {}
"#;
    let contributions = scan_fixture("imports", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    let mut imports: Vec<&str> = edges
        .iter()
        .filter(|edge| edge["kind"] == "imports")
        .map(|edge| edge["target"].as_str().unwrap())
        .collect();
    imports.sort_unstable();

    // An exact list, not a containment check: it pins that the symbol's own
    // path is NOT emitted (a target built from the full path would read
    // `rust:module:std::collections::HashMap`), and that one `use` line
    // naming two symbols from one module emits an edge per symbol, which the
    // core collapses.
    assert_eq!(
        vec![
            "rust:module:crate::net",
            "rust:module:serde",
            "rust:module:serde",
            "rust:module:serde_json",
            "rust:module:std::collections",
        ],
        imports
    );
    for edge in edges.iter().filter(|edge| edge["kind"] == "imports") {
        assert_eq!(
            "rust:module:crate", edge["source"],
            "imports are owned by the module"
        );
    }
}

#[test]
fn an_imported_symbol_still_resolves_a_call_through_its_alias() {
    // The companion to the test above: retargeting the EDGE must leave the
    // alias map alone, so an imported name still resolves a `calls` target to
    // the symbol's own full path, not to the module the edge points at.
    let source = r#"
use crate::net::http::get;
use crate::net::client as remote;

pub fn go() {
    get();
    remote::fetch();
}
"#;
    let contributions = scan_fixture("import-aliases", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    let calls = |target: &str| {
        edges.iter().any(|edge| {
            edge["kind"] == "calls"
                && edge["source"] == "rust:function:crate::go"
                && edge["target"] == target
        })
    };

    assert!(calls("rust:function:crate::net::http::get"));
    assert!(calls("rust:function:crate::net::client::fetch"));
}

#[test]
fn a_use_inside_a_nested_mod_is_attributed_to_the_files_own_module() {
    let source = r#"
mod inner {
    use serde::Serialize;
}
"#;
    let contributions = scan_fixture("nested-mod-imports", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "imports"
            && edge["source"] == "rust:module:crate"
            && edge["target"] == "rust:module:serde"
    }));
}

#[test]
fn impl_blocks_and_supertraits_become_inheritance_edges() {
    let source = r#"
use std::fmt::Display;

pub trait Named: Display {
    fn name(&self) -> String;
}

pub struct Engine;

impl Named for Engine {
    fn name(&self) -> String { String::new() }
}
"#;
    let contributions = scan_fixture("inheritance", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    let has = |kind: &str, source: &str, target: &str| {
        edges.iter().any(|edge| {
            edge["kind"] == kind && edge["source"] == source && edge["target"] == target
        })
    };

    assert!(has(
        "implements",
        "rust:class:crate::Engine",
        "rust:interface:crate::Named"
    ));
    assert!(has(
        "extends",
        "rust:interface:crate::Named",
        "rust:interface:std::fmt::Display"
    ));
}

#[test]
fn a_self_leaf_imports_the_prefix_module_itself() {
    // `use crate::foo::{self, bar};` binds `foo` to the module `crate::foo`,
    // not to a symbol inside it, so that leaf's edge points at `crate::foo`
    // while `bar`'s points at the module holding it — the same `crate::foo`.
    // Each leaf is its own fact; the core collapses the two.
    let source = r#"
use crate::foo::{self, bar};

pub fn go() {}
"#;
    let contributions = scan_fixture("use-self-leaf", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    let imports: Vec<&str> = edges
        .iter()
        .filter(|edge| edge["kind"] == "imports")
        .map(|edge| edge["target"].as_str().unwrap())
        .collect();

    assert_eq!(
        vec!["rust:module:crate::foo", "rust:module:crate::foo"],
        imports
    );
}

#[test]
fn a_use_self_import_is_rebased_against_the_current_module() {
    let source = r#"
use self::inner::Thing;

pub mod inner {
    pub struct Thing;
}
"#;
    let contributions = scan_fixture("use-self-rebased", &[("src/net.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "imports"
            && edge["source"] == "rust:module:crate::net"
            && edge["target"] == "rust:module:crate::net::inner"
    }));
}

#[test]
fn a_use_super_import_is_rebased_against_the_parent_module() {
    let source = "use super::Parent;\n";
    let contributions = scan_fixture("use-super-rebased", &[("src/net/http.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "imports"
            && edge["source"] == "rust:module:crate::net::http"
            && edge["target"] == "rust:module:crate::net"
    }));
}

#[test]
fn self_and_super_use_leaves_inside_a_nested_mod_rebase_against_the_nested_module() {
    // The edge SOURCE is always the file's own module (`crate::net`), per
    // `collect_uses`'s design note, regardless of nesting. But what a
    // `self::`/`super::` path itself MEANS is a different question: Rust
    // resolves those against the module the `use` line lexically appears in
    // — here, the nested `inner` module, not the file's own module. A
    // refactor that flattened rebasing to `self.module` would look plausible
    // and would break this silently without a dedicated test.
    let source = r#"
mod inner {
    use self::deep::Thing;
    use super::Outer;

    pub mod deep {
        pub struct Thing;
    }
}

pub struct Outer;
"#;
    let contributions = scan_fixture("nested-mod-self-super-rebase", &[("src/net.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    let imports = |target: &str| {
        edges.iter().any(|edge| {
            edge["kind"] == "imports"
                && edge["source"] == "rust:module:crate::net"
                && edge["target"] == target
        })
    };

    assert!(imports("rust:module:crate::net::inner::deep"));
    assert!(imports("rust:module:crate::net"));
}

#[test]
fn calls_resolve_through_the_import_map() {
    let source = r#"
use crate::net::http;

pub struct Engine;

impl Engine {
    pub fn start(&self) {
        http::get();
        helper();
        Engine::stop();
    }

    pub fn stop() {}
}

pub fn helper() {}
"#;
    let contributions = scan_fixture("calls", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    let calls: Vec<(&str, &str)> = edges
        .iter()
        .filter(|edge| edge["kind"] == "calls")
        .map(|edge| {
            (
                edge["source"].as_str().unwrap(),
                edge["target"].as_str().unwrap(),
            )
        })
        .collect();

    assert!(calls.contains(&(
        "rust:method:crate::Engine::start",
        "rust:function:crate::net::http::get"
    )));
    assert!(calls.contains(&(
        "rust:method:crate::Engine::start",
        "rust:function:crate::helper"
    )));
    assert!(calls.contains(&(
        "rust:method:crate::Engine::start",
        "rust:method:crate::Engine::stop"
    )));
}

#[test]
fn a_use_through_a_child_module_is_anchored_at_that_module() {
    // In a crate root, `use policy::Policy;` names the `mod policy` declared
    // beside it. Rendered as written, `policy::Policy::builtin()` became an
    // external method on a crate nobody depends on.
    let files = [
        (
            "src/main.rs",
            "mod policy;\nuse policy::Policy;\nfn main() { Policy::builtin(); }\n",
        ),
        (
            "src/policy.rs",
            "pub struct Policy;\nimpl Policy { pub fn builtin() {} }\n",
        ),
    ];
    let contributions = scan_fixture("child-module-use", &files);

    assert!(edges_of(&contributions, "calls").contains(&(
        "rust:function:crate::main".to_owned(),
        "rust:method:crate::policy::Policy::builtin".to_owned()
    )));
    assert!(edges_of(&contributions, "imports").contains(&(
        "rust:module:crate".to_owned(),
        "rust:module:crate::policy".to_owned()
    )));
}

#[test]
fn a_type_named_through_a_facade_crates_extern_crate_re_export_is_the_defining_type() {
    // `pub extern crate grep_searcher as searcher;` is how a facade crate
    // exposes its parts. A parameter typed `&mut grep::searcher::Searcher`
    // named nothing, so a method call on it left no edge.
    let files = [
        ("Cargo.toml", "[workspace]\nmembers = [\"crates/*\"]\n"),
        ("crates/searcher/Cargo.toml", "[package]\nname = \"grep-searcher\"\n"),
        (
            "crates/searcher/src/lib.rs",
            "pub struct Searcher;\nimpl Searcher {\n    pub fn search_path(&mut self) {}\n}\n",
        ),
        ("crates/grep/Cargo.toml", "[package]\nname = \"grep\"\n"),
        (
            "crates/grep/src/lib.rs",
            "pub extern crate grep_searcher as searcher;\nextern crate self as facade;\n",
        ),
        ("crates/core/Cargo.toml", "[package]\nname = \"core\"\n"),
        (
            "crates/core/src/main.rs",
            "fn run(searcher: &mut grep::searcher::Searcher) {\n    searcher.search_path();\n}\nfn fresh() {\n    let _ = Local::new();\n    let _ = std::mem::size_of::<u8>();\n}\nstruct Local;\nimpl Local {\n    fn new() -> Self {\n        Local\n    }\n}\nfn main() {}\n",
        ),
    ];
    let contributions = scan_fixture_with(
        "facade-extern-crate",
        &files,
        &serde_json::json!({
            "config_files": [
                "Cargo.toml",
                "crates/core/Cargo.toml",
                "crates/grep/Cargo.toml",
                "crates/searcher/Cargo.toml",
            ],
        }),
    );
    let calls = edges_of(&contributions, "calls");
    let imports = edges_of(&contributions, "imports");

    assert!(
        calls.contains(&(
            "rust:function:core::run".to_owned(),
            "rust:method:grep_searcher::Searcher::search_path".to_owned()
        )),
        "{calls:?}"
    );
    // Written inline, without a `use` line, the path still makes the file
    // depend on the crate it reaches, as `use grep::searcher::Searcher;`
    // would. A type of the file's own module imports nothing.
    assert!(
        imports.contains(&(
            "rust:module:core".to_owned(),
            "rust:module:grep_searcher".to_owned()
        )),
        "{imports:?}"
    );
    assert!(
        !imports
            .iter()
            .any(|(source, target)| source == "rust:module:core" && target == "rust:module:core"),
        "{imports:?}"
    );
}

#[test]
fn a_path_through_a_pub_use_re_export_names_the_defining_item() {
    // `crate::visit::collect()` and `use crate::visit::Store;` reach items
    // `visit` re-exports from its child `cfg`. The graph declares them under
    // `crate::visit::cfg`, so an edge to the re-exported path named nothing.
    let files = [
        ("src/lib.rs", "pub mod index;\npub mod visit;\n"),
        (
            "src/visit/mod.rs",
            "mod cfg;\npub use cfg::{collect, Store};\npub(crate) use self::cfg::helper as assist;\n",
        ),
        (
            "src/visit/cfg.rs",
            "pub fn collect() {}\npub fn helper() {}\npub struct Store;\nimpl Store {\n    pub fn new() -> Self {\n        Store\n    }\n    pub fn add(&self) {}\n}\n",
        ),
        (
            "src/index.rs",
            "use crate::visit::Store;\npub fn go() {\n    crate::visit::collect();\n    crate::visit::assist();\n    let store = Store::new();\n    store.add();\n}\n",
        ),
    ];
    let contributions = scan_fixture("pub-use-re-export", &files);
    let calls = method_calls(&contributions, "rust:function:crate::index::go");

    for target in [
        "rust:function:crate::visit::cfg::collect",
        "rust:function:crate::visit::cfg::helper",
        "rust:method:crate::visit::cfg::Store::new",
        "rust:method:crate::visit::cfg::Store::add",
    ] {
        assert!(
            calls.iter().any(|(called, _)| called == target),
            "{target} missing from {calls:?}"
        );
    }
    // The import still names the module the source wrote.
    let index = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/index.rs")
        .unwrap();
    assert!(index["edges"]
        .as_array()
        .unwrap()
        .iter()
        .any(|edge| { edge["kind"] == "imports" && edge["target"] == "rust:module:crate::visit" }));
}

#[test]
fn a_re_export_of_a_child_item_under_its_own_name_does_not_grow_paths() {
    // `pub use parse::parse;` beside `pub mod parse;` binds `crate::x::parse`
    // in the value namespace only: `crate::x::parse::helper` goes through the
    // module, and must not be rewritten through the function's re-export,
    // over and over.
    let files = [
        ("src/lib.rs", "pub mod user;\npub mod x;\n"),
        ("src/x/mod.rs", "pub mod parse;\npub use parse::parse;\n"),
        ("src/x/parse.rs", "pub fn parse() {}\npub fn helper() {}\n"),
        (
            "src/user.rs",
            "pub fn go() {\n    crate::x::parse();\n    crate::x::parse::helper();\n}\n",
        ),
    ];
    let contributions = scan_fixture("re-export-same-name", &files);
    let calls = method_calls(&contributions, "rust:function:crate::user::go");
    let targets: Vec<&str> = calls.iter().map(|(target, _)| target.as_str()).collect();

    assert!(
        targets.contains(&"rust:function:crate::x::parse::parse"),
        "{targets:?}"
    );
    assert!(
        targets.contains(&"rust:function:crate::x::parse::helper"),
        "{targets:?}"
    );
    assert!(
        !targets
            .iter()
            .any(|target| target.contains("parse::parse::")),
        "{targets:?}"
    );
}

#[test]
fn a_chain_of_re_exports_through_a_same_named_module_reaches_the_item() {
    // `crate::parse` re-exports `parse::parse`, which `parse` re-exports from
    // its private `imp`: the function is `crate::parse::imp::parse`.
    let files = [
        (
            "src/lib.rs",
            "pub mod parse {\n    mod imp {\n        pub fn parse() {}\n    }\n    pub use imp::parse;\n}\npub use parse::parse;\npub mod user;\n",
        ),
        ("src/user.rs", "pub fn a() {\n    crate::parse();\n}\n"),
    ];
    let contributions = scan_fixture("re-export-multi-hop", &files);

    assert!(
        method_calls(&contributions, "rust:function:crate::user::a")
            .iter()
            .any(|(target, _)| target == "rust:function:crate::parse::imp::parse"),
        "{:?}",
        method_calls(&contributions, "rust:function:crate::user::a")
    );
}

#[test]
fn a_path_through_a_child_module_named_like_an_import_goes_through_the_module() {
    // `parse::helper()` beside `mod parse; pub use parse::parse;` names the
    // module's `helper`, not a `helper` under the function the import binds.
    let files = [
        ("src/lib.rs", "pub mod x;\n"),
        (
            "src/x.rs",
            "pub mod parse;\npub use parse::parse;\npub fn local() {\n    parse::helper();\n}\n",
        ),
        ("src/x/parse.rs", "pub fn parse() {}\npub fn helper() {}\n"),
    ];
    let contributions = scan_fixture("alias-head-child-module", &files);
    let calls = method_calls(&contributions, "rust:function:crate::x::local");

    assert!(
        calls
            .iter()
            .any(|(target, _)| target == "rust:function:crate::x::parse::helper"),
        "{calls:?}"
    );
    assert!(
        !calls
            .iter()
            .any(|(target, _)| target.contains("parse::parse::")),
        "{calls:?}"
    );
}

#[test]
fn a_call_through_a_pub_use_reaches_the_type_that_module_re_exports() {
    // Two `Decoder`s: the root's `frame` and `v2`'s own `frame`, each
    // re-exported by its parent. `v2::Decoder::new()` is `v2::frame`'s.
    let contributions = scan_fixture(
        "reexported-twins",
        &[
            (
                "src/lib.rs",
                "mod frame;\npub mod v2;\npub mod pump;\npub use frame::Decoder;\n",
            ),
            ("src/frame.rs", "pub struct Decoder;\nimpl Decoder { pub fn new() -> Self { Decoder } }\n"),
            ("src/v2/mod.rs", "mod frame;\npub use frame::{Decoder};\n"),
            ("src/v2/frame.rs", "pub struct Decoder;\nimpl Decoder { pub fn new() -> Self { Decoder } }\n"),
            (
                "src/pump.rs",
                "use crate::v2;\npub fn start() { let _ = v2::Decoder::new(); }\npub fn root() { let _ = crate::Decoder::new(); }\n",
            ),
        ],
    );
    let news = |source: &str| -> Vec<String> {
        method_calls(&contributions, source)
            .into_iter()
            .map(|(target, _)| target)
            .filter(|target| target.ends_with("::new"))
            .collect()
    };

    assert_eq!(
        vec!["rust:method:crate::v2::frame::Decoder::new".to_owned()],
        news("rust:function:crate::pump::start")
    );
    assert_eq!(
        vec!["rust:method:crate::frame::Decoder::new".to_owned()],
        news("rust:function:crate::pump::root")
    );
}
