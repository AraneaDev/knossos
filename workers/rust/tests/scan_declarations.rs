//! Scanning tests driving the worker against real files on disk:
//! declarations, `impl` blocks and containment.

mod common;

use common::*;

#[test]
fn an_impl_for_a_type_in_another_file_keeps_its_edges() {
    // The Phase 3 cross-file index: the declaring file is in the same
    // request, so the `implements` and `contains` edges sourced from
    // `crate::model::Engine` survive instead of orphaning the methods. The
    // class node itself lives in model.rs's contribution.
    let contributions = scan_fixture(
        "cross-file-impl",
        &[
            ("src/lib.rs", "pub mod model;\n"),
            ("src/model.rs", "pub struct Engine;\n"),
            (
                "src/engine.rs",
                "use crate::model::Engine;\n\nimpl Engine {\n    pub fn start(&self) {}\n}\n",
            ),
        ],
    );
    let engine = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/engine.rs")
        .unwrap();
    let edges = engine["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "contains"
            && edge["source"] == "rust:class:crate::model::Engine"
            && edge["target"] == "rust:method:crate::model::Engine::start"
    }));
}

#[test]
fn declarations_become_nodes_with_containment_edges() {
    let source = r#"
pub struct Engine {
    pub name: String,
}

pub enum Mode {
    Fast,
}

pub trait Runner {
    fn run(&self);
}

impl Engine {
    pub fn start(&self) {}
}

pub fn boot() {}

pub mod inner {
    pub fn nested() {}
}
"#;
    let contributions = scan_fixture("declarations", &[("src/engine.rs", source)]);
    let nodes = contributions[0]["nodes"].as_array().unwrap();
    let kind_of = |name: &str| {
        nodes
            .iter()
            .find(|node| node["canonical_name"] == name)
            .unwrap_or_else(|| panic!("missing node {name}"))["kind"]
            .as_str()
            .unwrap()
            .to_owned()
    };

    assert_eq!("class", kind_of("crate::engine::Engine"));
    assert_eq!("class", kind_of("crate::engine::Mode"));
    assert_eq!("interface", kind_of("crate::engine::Runner"));
    assert_eq!("method", kind_of("crate::engine::Engine::start"));
    assert_eq!("method", kind_of("crate::engine::Runner::run"));
    assert_eq!("function", kind_of("crate::engine::boot"));
    assert_eq!("module", kind_of("crate::engine::inner"));
    assert_eq!("function", kind_of("crate::engine::inner::nested"));

    let edges = contributions[0]["edges"].as_array().unwrap();
    let contains = |source: &str, target: &str| {
        edges.iter().any(|edge| {
            edge["kind"] == "contains" && edge["source"] == source && edge["target"] == target
        })
    };
    assert!(contains(
        "rust:module:crate::engine",
        "rust:class:crate::engine::Engine"
    ));
    assert!(contains(
        "rust:module:crate::engine",
        "rust:module:crate::engine::inner"
    ));
    assert!(contains(
        "rust:module:crate::engine::inner",
        "rust:function:crate::engine::inner::nested"
    ));
    assert!(contains(
        "rust:class:crate::engine::Engine",
        "rust:method:crate::engine::Engine::start"
    ));
    assert!(contains(
        "rust:interface:crate::engine::Runner",
        "rust:method:crate::engine::Runner::run"
    ));
}

#[test]
fn a_bare_mod_declaration_does_not_duplicate_the_module_node() {
    // `pub mod foo;` in lib.rs and the module actually declared in `foo.rs` both
    // canonicalise to `crate::foo`. Declaring a node for both would give the
    // reconciler two nodes with the same stable id but different evidence
    // paths, which is not exempt from `reconciler.duplicate_symbol_evidence`
    // the way `package`/`external_*` kinds are — so lib.rs must contribute only
    // the `contains` edge, not a second node.
    let contributions = scan_fixture(
        "mod-declaration",
        &[
            ("src/lib.rs", "pub mod foo;\n"),
            ("src/foo.rs", "pub fn hello() {}\n"),
        ],
    );

    let lib = contributions
        .iter()
        .find(|contribution| contribution["owner_key"] == "knossos.rust:file:src/lib.rs")
        .expect("missing src/lib.rs contribution");
    let lib_nodes = lib["nodes"].as_array().unwrap();
    assert!(
        !lib_nodes
            .iter()
            .any(|node| node["canonical_name"] == "crate::foo"),
        "lib.rs must not declare a second node for a bare `mod foo;`"
    );
    let lib_edges = lib["edges"].as_array().unwrap();
    assert!(lib_edges.iter().any(|edge| {
        edge["kind"] == "contains"
            && edge["source"] == "rust:module:crate"
            && edge["target"] == "rust:module:crate::foo"
    }));

    let foo = contributions
        .iter()
        .find(|contribution| contribution["owner_key"] == "knossos.rust:file:src/foo.rs")
        .expect("missing src/foo.rs contribution");
    let foo_nodes = foo["nodes"].as_array().unwrap();
    assert!(
        foo_nodes
            .iter()
            .any(|node| node["canonical_name"] == "crate::foo" && node["kind"] == "module"),
        "foo.rs must declare the module node crate::foo itself"
    );
}

#[test]
fn an_impl_for_an_undeclared_type_emits_methods_but_no_contains_edge() {
    // `Elsewhere` is never declared in this file (it might live in another
    // module entirely), so the type's canonical path was never emitted as a
    // node here. `GraphReconciler::resolveEdges` throws when an edge's source
    // is not in the node map, so `Facts::finish` must drop this edge rather
    // than let it reach the core. The method node itself is still legitimate
    // evidence and stays.
    let source = r#"
impl Elsewhere {
    pub fn go(&self) {}
}
"#;
    let contributions = scan_fixture("impl-undeclared-type", &[("src/lib.rs", source)]);
    let nodes = contributions[0]["nodes"].as_array().unwrap();
    assert!(nodes.iter().any(|node| {
        node["canonical_name"] == "crate::Elsewhere::go" && node["kind"] == "method"
    }));

    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(
        !edges.iter().any(|edge| {
            edge["kind"] == "contains" && edge["target"] == "rust:method:crate::Elsewhere::go"
        }),
        "no contains edge should survive with an undeclared source"
    );
}

#[test]
fn an_impl_for_a_declared_type_still_emits_its_contains_edge() {
    let source = r#"
pub struct Engine;

impl Engine {
    pub fn start(&self) {}
}
"#;
    let contributions = scan_fixture("impl-declared-type", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "contains"
            && edge["source"] == "rust:class:crate::Engine"
            && edge["target"] == "rust:method:crate::Engine::start"
    }));
}

#[test]
fn an_implements_edge_sourced_from_an_undeclared_type_is_dropped() {
    // Same drop mechanism `Facts::finish` already applies to `contains` edges
    // (see `an_impl_for_an_undeclared_type_emits_methods_but_no_contains_edge`),
    // exercised here for `implements`: `Elsewhere` is never declared in this
    // file, so an edge sourced from it cannot survive.
    let source = r#"
pub trait Named {
    fn name(&self) -> String;
}

impl Named for Elsewhere {
    fn name(&self) -> String { String::new() }
}
"#;
    let contributions = scan_fixture("implements-undeclared-source", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(
        !edges.iter().any(|edge| edge["kind"] == "implements"),
        "an implements edge whose source type was never declared here must be dropped"
    );
}

#[test]
fn an_impl_for_a_qualified_type_resolves_the_correct_struct() {
    // Two `Engine`s exist in this file: one at the crate root, one nested in
    // `mod other`. `impl Named for other::Engine` names the nested one
    // specifically. `type_path` used to take only the self type's last path
    // segment, which collapses `other::Engine` to `crate::Engine` — a node
    // that genuinely exists here, so the Task 3 "edge source never declared"
    // filter cannot catch the misattribution. The implements edge must source
    // from the nested struct, never the unrelated top-level one.
    let source = r#"
pub trait Named {
    fn name(&self) -> String;
}

pub struct Engine;

mod other {
    pub struct Engine;
}

impl Named for other::Engine {
    fn name(&self) -> String { String::new() }
}
"#;
    let contributions = scan_fixture("impl-qualified-self-type", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "implements"
            && edge["source"] == "rust:class:crate::other::Engine"
            && edge["target"] == "rust:interface:crate::Named"
    }));
    assert!(
        !edges.iter().any(|edge| {
            edge["kind"] == "implements" && edge["source"] == "rust:class:crate::Engine"
        }),
        "the implements edge must not be misattributed to the unrelated top-level Engine"
    );
}

#[test]
fn a_bare_same_file_impl_still_attaches_methods_to_its_own_type() {
    // Regression lock for the `type_path` -> `path_target` refactor: an
    // unqualified, unaliased self type must resolve exactly as before.
    let source = r#"
pub struct Engine;

impl Engine {
    pub fn start(&self) {}
}
"#;
    let contributions = scan_fixture("impl-bare-self-type", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "contains"
            && edge["source"] == "rust:class:crate::Engine"
            && edge["target"] == "rust:method:crate::Engine::start"
    }));
}

#[test]
fn an_impl_for_an_aliased_type_resolves_through_the_alias_map() {
    let source = r#"
use crate::net::Engine;

impl Engine {
    pub fn start(&self) {}
}

pub mod net {
    pub struct Engine;
}
"#;
    let contributions = scan_fixture("impl-aliased-self-type", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "contains"
            && edge["source"] == "rust:class:crate::net::Engine"
            && edge["target"] == "rust:method:crate::net::Engine::start"
    }));
}

#[test]
fn self_in_impl_block_resolves_to_the_impl_type() {
    let facts = scan_fixture(
        "selfimpl",
        &[(
            "src/lib.rs",
            "
        struct Widget {}
        impl Widget {
            fn factory() {
                Self {};
            }
        }
    ",
        )],
    );
    let edges = facts[0]["edges"].as_array().unwrap();
    let edge = edges.iter().find(|e| {
        e["kind"] == "calls"
            && e["source"] == "rust:method:crate::Widget::factory"
            && e["target"] == "rust:class:crate::Widget"
    });
    assert!(
        edge.is_some(),
        "missing calls edge from factory to Widget using Self. Edges: {:?}",
        edges
    );
}

#[test]
fn a_method_an_impl_in_a_child_file_adds_to_the_parents_type_is_reached_by_its_type() {
    // `impl BrokerState` in `broker/owned.rs`, under `use super::BrokerState;`,
    // adds `accepted_owned` to the parent's type; the index placed it on a
    // `broker::owned::BrokerState`, so `BrokerState::accepted_owned()` in
    // `broker.rs` found nothing.
    let broker = r#"
mod owned;
pub struct BrokerState;
impl BrokerState {
    fn fresh() -> Self { BrokerState }
}
pub fn go() {
    let _ = BrokerState::fresh();
    BrokerState::accepted_owned();
}
"#;
    let owned =
        "use super::BrokerState;\nimpl BrokerState {\n    pub(super) fn accepted_owned() {}\n}\n";
    let contributions = scan_fixture(
        "split-inherent-impl",
        &[
            ("src/lib.rs", "pub mod broker;\n"),
            ("src/broker.rs", broker),
            ("src/broker/owned.rs", owned),
        ],
    );
    let calls = targets_of(&contributions, "calls", "rust:function:crate::broker::go");
    assert!(
        calls.contains(&"rust:method:crate::broker::BrokerState::accepted_owned".to_owned()),
        "{calls:?}"
    );
    assert!(node_in(
        &contributions,
        "src/broker/owned.rs",
        "crate::broker::BrokerState::accepted_owned"
    )
    .is_some());
}

#[test]
fn a_type_a_macro_declares_is_typed_by_the_impl_block_the_file_writes_for_it() {
    // `named_enum!(ErrorCode { .. })` declares the enum where the scan
    // cannot see it, but `impl ErrorCode` beside it places it in this
    // module, so a parameter of that type is typed by value.
    let source = r#"
macro_rules! named_enum {
    ($name:ident { $($variant:ident),* }) => {
        #[derive(Clone, Copy)]
        pub enum $name { $($variant),* }
    };
}
named_enum!(ErrorCode { Stale, Large });
pub struct ErrorPolicy;
impl ErrorCode {
    pub fn policy(self) -> ErrorPolicy { ErrorPolicy }
}
pub struct Failure;
impl Failure {
    pub fn new(code: ErrorCode) -> Self {
        let _policy = code.policy();
        Failure
    }
}
pub fn unknown(code: Unknown) {
    code.policy();
}
"#;
    let contributions = scan_fixture("macro-declared-type", &[("src/lib.rs", source)]);

    assert_eq!(
        vec!["rust:method:crate::ErrorCode::policy".to_owned()],
        method_targets(&contributions, "rust:method:crate::Failure::new")
    );
    // A name nothing here declares or implements stays untyped.
    assert!(method_targets(&contributions, "rust:function:crate::unknown").is_empty());
}
