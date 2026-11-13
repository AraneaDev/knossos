//! Scanning tests driving the worker against real files on disk:
//! method calls on receivers whose type the source states.

mod common;

use common::*;

#[test]
fn a_method_call_on_an_unknown_receiver_emits_no_edge() {
    // A receiver no signature, annotation or constructor names: the result of
    // a call, and a closure parameter. An edge here would be a guess.
    let source = "pub fn go() { make().run(); let f = |v| v.run(); f(1); }\n";
    let contributions = scan_fixture("unknown-receiver", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(
        !edges
            .iter()
            .any(|edge| edge["kind"] == "calls"
                && edge["target"].as_str().unwrap().ends_with("::run")),
        "{edges:?}"
    );
}

#[test]
fn a_method_call_on_a_receiver_of_known_type_is_a_speculative_edge() {
    // Rust names a receiver's type in the signature, the annotation or the
    // constructor, so `self.evaluate()`, `p.evaluate()` on `p: &Policy` and
    // `let q = Policy::new(); q.evaluate()` all say which method they call.
    // Leaving them out reported nearly every method as unreferenced. The
    // target is still a guess for a trait method (`p.clone()`), so the edge is
    // speculative: the core keeps it only when the method exists.
    let source = r#"
pub struct Policy;

impl Policy {
    pub fn new() -> Self { Policy }
    pub fn evaluate(&self) {}
    pub fn evaluate_all(&self) { self.evaluate(); }
}

pub fn by_parameter(p: &Policy) { p.evaluate(); p.clone(); }

pub fn by_binding() {
    let q = Policy::new();
    q.evaluate();
    let r: Policy = make();
    r.evaluate();
    let s = Policy {};
    s.evaluate();
}

fn make() -> Policy { Policy }
"#;
    let contributions = scan_fixture("known-receiver", &[("src/lib.rs", source)]);

    assert_eq!(
        method_calls(&contributions, "rust:method:crate::Policy::evaluate_all"),
        vec![("rust:method:crate::Policy::evaluate".to_owned(), true)]
    );
    assert_eq!(
        method_calls(&contributions, "rust:function:crate::by_parameter"),
        vec![
            ("rust:method:crate::Policy::clone".to_owned(), true),
            ("rust:method:crate::Policy::evaluate".to_owned(), true),
        ]
    );
    let by_binding = method_calls(&contributions, "rust:function:crate::by_binding");
    assert_eq!(
        by_binding
            .iter()
            .filter(|(target, _)| target == "rust:method:crate::Policy::evaluate")
            .count(),
        3,
        "three receivers, one edge each: {by_binding:?}"
    );
    assert!(by_binding.contains(&("rust:method:crate::Policy::evaluate".to_owned(), true)));
}

#[test]
fn a_call_on_a_returned_value_defers_to_the_declared_return_type() {
    // `s.mode().label()`: the receiver of `label` is whatever `State::mode`
    // returns, which may be declared in another file. The worker names the
    // call and states each method's return type; the core joins the two.
    let source = r#"
pub enum Mode { A }
impl Mode { pub fn label(&self) -> &'static str { "a" } }
pub struct State;
impl State {
    pub fn new() -> Self { State }
    pub fn mode(&self) -> Mode { Mode::A }
}
pub fn go(s: &State) -> usize { s.mode().label().len() + State::new().mode().label().len() }
"#;
    let contributions = scan_fixture("returned-receiver", &[("src/lib.rs", source)]);
    let calls = edges_of(&contributions, "calls");
    let returns = edges_of(&contributions, "returns");

    for target in [
        "rust:method_of_return:crate::State::mode::label",
        "rust:method_of_return:crate::State::new::mode",
    ] {
        assert!(
            calls.contains(&("rust:function:crate::go".to_owned(), target.to_owned())),
            "{target}: {calls:?}"
        );
    }
    assert!(
        returns.contains(&(
            "rust:method:crate::State::mode".to_owned(),
            "rust:class:crate::Mode".to_owned()
        )),
        "{returns:?}"
    );
    assert!(
        returns.contains(&(
            "rust:method:crate::State::new".to_owned(),
            "rust:class:crate::State".to_owned()
        )),
        "{returns:?}"
    );
}

#[test]
fn a_method_called_on_an_enum_variant_resolves_to_the_enum() {
    let source = r#"
pub enum Theme { Regular, HighContrast }
impl Theme { pub fn cycles_to(&self) -> Theme { Theme::HighContrast } }
pub fn go() { let _ = Theme::Regular.cycles_to(); }
"#;
    let contributions = scan_fixture("variant-receiver", &[("src/lib.rs", source)]);

    assert_eq!(
        method_calls(&contributions, "rust:function:crate::go"),
        vec![("rust:method:crate::Theme::cycles_to".to_owned(), true)]
    );
}

#[test]
fn a_method_called_through_struct_fields_resolves_to_the_field_type() {
    // `self.walk.facts.edge()` calls `Facts::edge` through two fields. Field
    // types were not tracked, so every method only ever reached that way (a
    // collaborator held in a struct) read as unreferenced.
    let source = r#"
pub struct Facts;
impl Facts { pub fn edge(&self) {} }
pub struct Walk { facts: Facts, count: usize }
pub struct Calls<'a> { walk: &'a mut Walk }
impl Calls<'_> {
    fn go(&mut self) { self.walk.facts.edge(); self.walk.count.count_ones(); }
}
"#;
    let contributions = scan_fixture("field-receiver", &[("src/lib.rs", source)]);

    assert!(method_calls(&contributions, "rust:method:crate::Calls::go")
        .contains(&("rust:method:crate::Facts::edge".to_owned(), true)));
}

#[test]
fn a_method_called_on_an_untyped_receiver_is_listed_on_the_module() {
    let contributions = scan_fixture(
        "untyped-calls",
        &[(
            "src/lib.rs",
            "pub struct Mode;\nimpl Mode {\n    pub fn label(&self) {}\n    pub fn typed(&self) {}\n    pub fn listed(&self) {}\n}\npub fn run(mode: &Mode, all: &[Mode]) {\n    mode.typed();\n    all.iter().for_each(|m| m.listed());\n    let show = |m| m.label();\n    show(mode);\n}\n",
        )],
    );
    let module = contributions[0]["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .find(|node| node["kind"] == "module")
        .unwrap();
    let names = module["attributes"]["unresolved_member_calls"]
        .as_array()
        .unwrap();

    // A closure bound to a name says nothing about its parameter's type;
    // `mode` states its own, and one handed to `for_each` over a slice of
    // `Mode` takes a `Mode`.
    assert!(names.contains(&serde_json::json!("label")));
    assert!(!names.contains(&serde_json::json!("typed")));
    assert!(!names.contains(&serde_json::json!("listed")));
}

#[test]
fn a_method_called_through_a_field_of_a_struct_declared_in_another_file_resolves() {
    // `Walk` declared in one file, its `impl` in another, its field's type in
    // a third: `self.store.put()` names `Store::put` through a field the
    // walking file never sees declared. The scan-wide index carries every
    // struct's field types, so the receiver is typed all the same.
    let files = [
        ("src/lib.rs", "pub mod a;\npub mod b;\npub mod c;\npub mod d;\n"),
        (
            "src/b.rs",
            "pub struct Store;\nimpl Store {\n    pub fn put(&self) {}\n}\npub struct Ledger;\nimpl Ledger {\n    pub fn record(&self) {}\n}\n",
        ),
        (
            "src/d.rs",
            "use crate::b::Ledger;\npub struct Inner {\n    pub(crate) ledger: Ledger,\n}\n",
        ),
        (
            "src/a.rs",
            "use crate::b::Store;\nuse crate::d::Inner;\npub struct Holder<'a> {\n    pub(crate) store: &'a mut Store,\n    pub(crate) inner: Inner,\n}\n",
        ),
        (
            "src/c.rs",
            "use crate::a::Holder;\nimpl Holder<'_> {\n    pub fn save(&mut self) {\n        self.store.put();\n        self.inner.ledger.record();\n    }\n}\n",
        ),
    ];
    let contributions = scan_fixture("cross-file-field-receiver", &files);

    assert_eq!(
        vec![
            ("rust:method:crate::b::Ledger::record".to_owned(), true),
            ("rust:method:crate::b::Store::put".to_owned(), true),
        ],
        method_calls(&contributions, "rust:method:crate::a::Holder::save")
    );
    // The answer rests on `Inner`'s declaration, which only the field type
    // led to, so the walking file read the file declaring it.
    let walking = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/c.rs")
        .unwrap();
    assert!(
        walking["reads"].get("src/d.rs").is_some(),
        "{}",
        walking["reads"]
    );
}

#[test]
fn a_method_call_on_a_value_whose_type_the_code_states_resolves() {
    // `Policy::new().evaluate()` and `make().check()` call a method on a
    // temporary the constructor or the function's return type names; a
    // receiver typed through a `type` alias names the aliased type; and a
    // `match` arm or a `thread::scope` closure calls on behalf of the
    // function it is written in.
    let source = r#"
pub struct Policy;

impl Policy {
    pub fn new() -> Self { Policy }
    pub fn evaluate(&self) {}
    pub fn check(&self) {}
    pub fn dispatch(&self, n: u8) {
        match n {
            0 => self.evaluate(),
            _ => self.check(),
        }
    }
}

pub type Alias = Policy;

fn make() -> Policy { Policy }
fn helper() {}

pub struct Engine;
impl Engine { pub fn run(&self) {} }
pub trait Driver { type Out; fn drive(&self, out: &Self::Out); }
pub struct Car;
impl Driver for Car {
    type Out = Engine;
    fn drive(&self, out: &Self::Out) { out.run(); }
}

pub fn by_temporary() { Policy::new().evaluate(); make().check(); }
pub fn by_alias(p: &Alias) { p.evaluate(); }
pub fn by_scope() { std::thread::scope(|s| { s.spawn(|| helper()); }); }
"#;
    let contributions = scan_fixture("stated-receivers", &[("src/lib.rs", source)]);
    let returns: Vec<(String, String)> = contributions[0]["edges"]
        .as_array()
        .unwrap()
        .iter()
        .filter(|edge| edge["kind"] == "returns")
        .map(|edge| {
            (
                edge["source"].as_str().unwrap().to_owned(),
                edge["target"].as_str().unwrap().to_owned(),
            )
        })
        .collect();
    assert!(
        returns.contains(&(
            "rust:function:crate::make".to_owned(),
            "rust:class:crate::Policy".to_owned()
        )),
        "a free function records its return type: {returns:?}"
    );
    let targets = |from: &str| -> Vec<String> {
        method_calls(&contributions, from)
            .into_iter()
            .map(|(target, _)| target)
            .collect()
    };

    let mut missing = Vec::new();
    for (from, to) in [
        // The worker names the call; the core resolves it through the
        // declared return type, which a free function now records too.
        (
            "rust:function:crate::by_temporary",
            "rust:method_of_return:crate::Policy::new::evaluate",
        ),
        (
            "rust:function:crate::by_temporary",
            "rust:method_of_return:crate::make::check",
        ),
        (
            "rust:function:crate::by_alias",
            "rust:method:crate::Policy::evaluate",
        ),
        (
            "rust:method:crate::Policy::dispatch",
            "rust:method:crate::Policy::evaluate",
        ),
        (
            "rust:method:crate::Policy::dispatch",
            "rust:method:crate::Policy::check",
        ),
        (
            "rust:function:crate::by_scope",
            "rust:function:crate::helper",
        ),
        (
            "rust:method:crate::Car::<impl Driver>::drive",
            "rust:method:crate::Engine::run",
        ),
    ] {
        if !targets(from).contains(&to.to_owned()) {
            missing.push(format!("{from} -> {to} (has {:?})", targets(from)));
        }
    }
    assert!(missing.is_empty(), "{missing:#?}");
}

#[test]
fn a_binding_of_a_call_declared_in_another_file_takes_its_return_type() {
    // `let store = report::open_existing(&root)?;` in one file, the function
    // in another behind a `pub use`, returning `anyhow::Result<ReportStore>`.
    // The binding had no type, so every `ReportStore` method only such
    // bindings call read as dead code. `?`, `.unwrap()` and `.expect(..)`
    // open the `Result`; a `&` borrow or parentheses change nothing.
    let files = [
        ("src/lib.rs", "pub mod report;\npub mod native;\n"),
        (
            "src/report/mod.rs",
            "mod store;\npub use store::{open_existing, maybe_open, ReportStore};\n",
        ),
        (
            "src/report/store.rs",
            "use anyhow::Result;\npub struct ReportStore;\npub fn open_existing(_: &str) -> Result<ReportStore> { Ok(ReportStore) }\npub fn maybe_open() -> Option<ReportStore> { None }\nimpl ReportStore {\n    pub fn load_selected_report(&self) -> Result<()> { Ok(()) }\n    pub fn intent_store(&self) {}\n    pub fn journal(&self) {}\n    pub fn other(&self) -> Result<Self> { Ok(ReportStore) }\n    pub fn last(&self) {}\n}\n",
        ),
        (
            "src/native/storage.rs",
            "use crate::report;\npub fn load(root: &str) -> anyhow::Result<()> {\n    let store = report::open_existing(root)?;\n    store.load_selected_report()?;\n    let unwrapped = &report::open_existing(root).unwrap();\n    unwrapped.intent_store();\n    let expected = (report::maybe_open().expect(\"open\"));\n    expected.journal();\n    report::open_existing(root)?.other()?.last();\n    Ok(())\n}\n",
        ),
        ("src/native/mod.rs", "pub mod storage;\n"),
    ];
    let contributions = scan_fixture("cross-file-returned-binding", &files);
    let targets: Vec<String> =
        method_calls(&contributions, "rust:function:crate::native::storage::load")
            .into_iter()
            .map(|(target, _)| target)
            .collect();

    for method in [
        "load_selected_report",
        "intent_store",
        "journal",
        "other",
        "last",
    ] {
        let target = format!("rust:method:crate::report::store::ReportStore::{method}");
        assert!(targets.contains(&target), "{target} in {targets:?}");
    }
}
