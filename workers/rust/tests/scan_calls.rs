//! Scanning tests driving the worker against real files on disk:
//! path calls, aliases and the calls that resolve to nothing.

mod common;

use common::*;

#[test]
fn a_child_module_call_resolves_through_the_scan_wide_index() {
    // `sign::any_supported_type` was a documented false negative: the child
    // module's declaration lives in another file, which the per-file walk
    // could not see. The scan-wide index resolves it.
    let contributions = scan_fixture(
        "child-module-call",
        &[
            (
                "src/lib.rs",
                "pub mod sign;\n\npub fn go() {\n    sign::any_supported_type();\n}\n",
            ),
            ("src/sign.rs", "pub fn any_supported_type() {}\n"),
        ],
    );
    let lib = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/lib.rs")
        .unwrap();
    let edges = lib["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "calls"
            && edge["source"] == "rust:function:crate::go"
            && edge["target"] == "rust:function:crate::sign::any_supported_type"
    }));
}

#[test]
fn an_unresolved_qualified_call_head_is_dropped_rather_than_guessed() {
    // `String` is a prelude type: neither imported nor declared here. Building
    // the target from the enclosing container produced
    // `crate::net::http::String::from`, a `crate::`-rooted name no crate member
    // declares, which the reconciler materialised as a fabricated external
    // node. Valid Rust requires a bare multi-segment head to be imported or
    // locally declared, so dropping it loses no legitimate edge. `Engine::stop`
    // in the same file is exactly such a legitimate head, and must survive.
    let source = r#"
pub struct Engine;

impl Engine {
    pub fn start(&self) {
        String::from("x");
        serde_json::to_value(1);
        Engine::stop();
    }

    pub fn stop() {}
}
"#;
    let contributions = scan_fixture("unresolved-qualified-call", &[("src/net/http.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    let targets: Vec<&str> = edges
        .iter()
        .filter(|edge| edge["kind"] == "calls")
        .map(|edge| edge["target"].as_str().unwrap())
        .collect();

    assert_eq!(
        vec!["rust:method:crate::net::http::Engine::stop"],
        targets,
        "only a head this file declares survives"
    );
}

#[test]
fn repeated_edges_are_reported_once_per_site() {
    // The core collapses repeated edges to one per kind, source, target and
    // owner (docs/reference/scanner-protocol-v1.md, "Repeated edges"), so the
    // worker reports every site: each symbol imported from one module is an
    // `imports` fact, and a call repeated in two branches is two `calls` facts.
    let source = r#"
use crate::net::http::{get, post};
use crate::net::http::head;

pub fn caller(flag: bool) {
    if flag {
        crate::helper();
    } else {
        crate::helper();
    }
}
"#;
    let contributions = scan_fixture("per-site-edges", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    let rows: Vec<(&str, &str, u64)> = edges
        .iter()
        .filter(|edge| edge["kind"] == "imports" || edge["kind"] == "calls")
        .map(|edge| {
            (
                edge["kind"].as_str().unwrap(),
                edge["target"].as_str().unwrap(),
                edge["evidence"]["start_line"].as_u64().unwrap(),
            )
        })
        .collect();

    assert_eq!(
        vec![
            ("calls", "rust:function:crate::helper", 7),
            ("calls", "rust:function:crate::helper", 9),
            ("imports", "rust:module:crate::net::http", 2),
            ("imports", "rust:module:crate::net::http", 2),
            ("imports", "rust:module:crate::net::http", 3),
        ],
        rows,
        "every imported symbol and every call site is its own fact"
    );
}

#[test]
fn a_qself_rooted_call_emits_no_edge_for_its_bare_callee() {
    // `syn` renders `<Widget>::default()` as the bare path `default` with
    // `leading_colon` set, because the qualified self takes position 0. Read
    // literally that is an absolute path, and trusting it emitted a `calls`
    // edge to `rust:function:default`, which the reconciler materialised as an
    // external node whose canonical name is the single word `default`. A
    // single-segment callee is never trusted outright, so this resolves to
    // nothing this file declares and is dropped.
    let source = r#"
pub struct Widget;

pub fn build() {
    <Widget>::default();
}
"#;
    let contributions = scan_fixture("qself-bare-callee", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(
        !edges.iter().any(|edge| edge["kind"] == "calls"),
        "a bare qself callee names no declaration here: {edges:?}"
    );
}

#[test]
fn a_call_through_an_fn_receiver_emits_no_edge() {
    // `self()` calls the `Fn` receiver itself. The path is the single segment
    // `self`, which rebases to the enclosing module, so trusting it emitted a
    // `rust:function:` edge to a path that names a declared MODULE — an
    // `external_function` twin of a real node. A single-segment callee is
    // never trusted outright, and `crate` is declared as a module rather than
    // a function, so the deferred check drops it.
    let source = r#"
pub trait Handler {
    fn handle(self);
}

impl<F: Fn()> Handler for F {
    fn handle(self) {
        self();
    }
}
"#;
    let contributions = scan_fixture("fn-receiver-call", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(
        !edges.iter().any(|edge| edge["kind"] == "calls"),
        "calling the receiver names no declaration here: {edges:?}"
    );
}

#[test]
fn a_rebinding_of_unknown_type_forgets_the_receivers_type() {
    let source = r#"
pub struct Policy;
impl Policy { pub fn new() -> Self { Policy } pub fn evaluate(&self) {} }
pub fn go() {
    let p = Policy::new();
    let p = something();
    p.evaluate();
}
fn something() -> u8 { 0 }
"#;
    let contributions = scan_fixture("rebound-receiver", &[("src/lib.rs", source)]);

    assert!(!method_calls(&contributions, "rust:function:crate::go")
        .iter()
        .any(|(target, _)| target.ends_with("::evaluate")),);
}

#[test]
fn a_guarded_arm_binding_forgets_the_outer_receivers_type() {
    // The arm binds `p` again, to a value of unknown type, behind a guard:
    // the call inside the arm must not resolve through the parameter `p`.
    let source = r#"
pub struct Policy;
impl Policy { pub fn evaluate(&self) {} }
pub fn go(p: &Policy, other: Option<u8>) {
    match other {
        Some(p) if p > 0 => { p.evaluate(); }
        _ => {}
    }
}
"#;
    let contributions = scan_fixture("guarded-arm-receiver", &[("src/lib.rs", source)]);

    assert!(!method_calls(&contributions, "rust:function:crate::go")
        .iter()
        .any(|(target, _)| target.ends_with("::evaluate")),);
}

#[test]
fn an_ambiguous_alias_emits_no_calls_edge_rather_than_guessing() {
    // `Thing` is bound to two different full paths by two different `use`
    // lines, so `Aliases::resolve` is poisoned for it (see resolve.rs). A
    // call naming it must be dropped, not guessed against the container.
    let source = r#"
mod a {
    pub use crate::first::Thing;
}
mod b {
    pub use crate::second::Thing;
}

pub fn caller() {
    Thing::go();
}
"#;
    let contributions = scan_fixture("ambiguous-alias-call", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(!edges.iter().any(|edge| {
        edge["kind"] == "calls" && edge["target"].as_str().unwrap().contains("Thing")
    }));
}

#[test]
fn an_unambiguous_alias_still_resolves_a_calls_edge() {
    let source = r#"
use crate::first::Thing;

pub fn caller() {
    Thing::go();
}
"#;
    let contributions = scan_fixture("unambiguous-alias-call", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(edges.iter().any(|edge| {
        edge["kind"] == "calls"
            && edge["source"] == "rust:function:crate::caller"
            && edge["target"] == "rust:method:crate::first::Thing::go"
    }));
}

#[test]
fn a_call_through_a_local_closure_binding_emits_no_edge() {
    let source = "pub fn go() { let f = || {}; f(); }\n";
    let contributions = scan_fixture("closure-call", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(!edges.iter().any(|edge| edge["kind"] == "calls"));
}

#[test]
fn a_call_through_a_local_fn_pointer_binding_emits_no_edge() {
    let source = "fn helper() {}\npub fn go() { let f: fn() = helper; f(); }\n";
    let contributions = scan_fixture("fn-pointer-call", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(!edges.iter().any(|edge| edge["kind"] == "calls"));
}

#[test]
fn a_bare_call_to_a_function_declared_later_in_the_file_still_resolves() {
    let source = "pub fn caller() { helper(); }\n\nfn helper() {}\n";
    let contributions = scan_fixture("forward-declared-call", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(edges.iter().any(|edge| {
        edge["kind"] == "calls"
            && edge["source"] == "rust:function:crate::caller"
            && edge["target"] == "rust:function:crate::helper"
    }));
}

#[test]
fn calls_inside_a_nested_mod_resolve_against_their_own_module() {
    let source = r#"
fn helper() {}

mod inner {
    fn helper() {}
    fn caller() {
        helper();
    }
}
"#;
    let contributions = scan_fixture("nested-mod-call-container", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(edges.iter().any(|edge| {
        edge["kind"] == "calls"
            && edge["source"] == "rust:function:crate::inner::caller"
            && edge["target"] == "rust:function:crate::inner::helper"
    }));
    assert!(!edges.iter().any(|edge| {
        edge["kind"] == "calls"
            && edge["source"] == "rust:function:crate::inner::caller"
            && edge["target"] == "rust:function:crate::helper"
    }));
}

#[test]
fn a_pattern_binding_shadows_an_outer_receiver_for_its_scope_only() {
    // A name a nested pattern, a `match` arm or an `if let` binds is a new
    // binding: the outer `store`'s type does not apply to it, and applies
    // again once its scope ends. A struct pattern in an arm types its fields.
    let source = r#"
pub struct Store;
impl Store {
    pub fn put(&self) {}
    pub fn flush(&self) {}
    pub fn keep(&self) {}
}
pub struct Other;
impl Other {
    pub fn put(&self) {}
}
pub struct Wrapper { pub pair: (Other, Other), pub inner: Store }
pub fn run(store: Store, w: Wrapper, maybe: Option<Other>) {
    match maybe {
        Some(store) => store.put(),
        None => {}
    }
    if let Some(store) = maybe {
        store.put();
    }
    store.flush();
    match w {
        Wrapper { inner, .. } => inner.keep(),
    }
    let Wrapper { pair: (store, _), .. } = w;
    store.put();
}
"#;
    let contributions = scan_fixture("pattern-shadowing", &[("src/lib.rs", source)]);
    let calls = method_calls(&contributions, "rust:function:crate::run");

    assert!(
        calls.contains(&("rust:method:crate::Store::flush".to_owned(), true)),
        "{calls:?}"
    );
    assert!(
        calls.contains(&("rust:method:crate::Store::keep".to_owned(), true)),
        "{calls:?}"
    );
    assert!(
        !calls
            .iter()
            .any(|(target, _)| target == "rust:method:crate::Store::put"),
        "{calls:?}"
    );
}

#[test]
fn aliases_in_a_circle_end_the_walk() {
    // `type A = B; type B = A;` does not compile, and a file like it must
    // still scan: the receiver stays untyped rather than recursing forever.
    let source = "type A = B;\ntype B = A;\npub fn go(a: &A) { a.run(); }\n";
    let contributions = scan_fixture("alias-circle", &[("src/lib.rs", source)]);
    assert_eq!(contributions.len(), 1);
}

#[test]
fn a_receiver_typed_by_an_alias_another_file_declares_resolves() {
    // The alias is declared in one file and named in another, plain and
    // through a smart pointer: the declaration index carries what it names.
    let files = [
        ("src/lib.rs", "pub mod policy;\npub mod usage;\n"),
        (
            "src/policy.rs",
            "pub struct Policy;\nimpl Policy { pub fn evaluate(&self) {} pub fn check(&self) {} }\npub type Alias = Policy;\npub type Shared = std::sync::Arc<Policy>;\n",
        ),
        (
            "src/usage.rs",
            "use crate::policy::{Alias, Shared};\npub fn plain(p: &Alias) { p.evaluate(); }\npub fn shared(p: Shared) { p.check(); }\n",
        ),
    ];
    let contributions = scan_fixture("alias-across-files", &files);
    let targets = |from: &str| -> Vec<String> {
        method_calls(&contributions, from)
            .into_iter()
            .map(|(target, _)| target)
            .collect()
    };
    assert!(
        targets("rust:function:crate::usage::plain")
            .contains(&"rust:method:crate::policy::Policy::evaluate".to_owned()),
        "{:?}",
        targets("rust:function:crate::usage::plain")
    );
    assert!(
        targets("rust:function:crate::usage::shared")
            .contains(&"rust:method:crate::policy::Policy::check".to_owned()),
        "{:?}",
        targets("rust:function:crate::usage::shared")
    );
}

#[test]
fn a_field_declared_as_a_type_parameter_takes_the_receivers_type_argument() {
    // `ScenarioContext<'a, E>` declares `environment: &'a mut E` and leaves
    // `E` unbounded; `impl<E: Environment> ScenarioContext<'_, E>` and
    // `fn step<E: Environment>(c: &mut ScenarioContext<'_, E>)` bound it.
    // A call through the field is the trait's.
    let source = r#"
pub trait Environment {
    fn create(&mut self);
    fn execute(&mut self);
}
pub struct ScenarioContext<'a, E> {
    pub environment: &'a mut E,
}
impl<E: Environment> ScenarioContext<'_, E> {
    pub fn run(&mut self) {
        self.environment.create();
    }
}
pub fn step<E: Environment>(c: &mut ScenarioContext<'_, E>) {
    c.environment.execute();
}
"#;
    let contributions = scan_fixture("param-field-receivers", &[("src/lib.rs", source)]);

    assert_eq!(
        vec![("rust:method:crate::Environment::create".to_owned(), true)],
        method_calls(&contributions, "rust:method:crate::ScenarioContext::run")
    );
    assert_eq!(
        vec![("rust:method:crate::Environment::execute".to_owned(), true)],
        method_calls(&contributions, "rust:function:crate::step")
    );
}

#[test]
fn a_path_call_through_an_alias_of_a_generic_type_reaches_the_type() {
    let types = r#"
pub struct Preview<const LIMIT: usize> { pub text: String }
impl<const LIMIT: usize> Preview<LIMIT> {
    pub fn new(value: &str, _lossy: bool) -> Self { Preview { text: value.to_owned() } }
}
pub(crate) type TextPreview = Preview<512>;
"#;
    let budget = r#"
use super::types::TextPreview;
pub fn too_large() -> TextPreview {
    TextPreview::new("Response exceeds the frame limit", false)
}
"#;
    let contributions = scan_fixture(
        "generic-alias",
        &[
            ("src/lib.rs", "pub mod types;\npub mod budget;\n"),
            ("src/types.rs", types),
            ("src/budget.rs", budget),
        ],
    );
    let targets: Vec<String> =
        method_calls(&contributions, "rust:function:crate::budget::too_large")
            .into_iter()
            .map(|(target, _)| target)
            .collect();

    assert!(
        targets.contains(&"rust:method:crate::types::Preview::new".to_owned()),
        "{targets:?}"
    );
}
