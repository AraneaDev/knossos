//! Scanning tests driving the worker against real files on disk:
//! names patterns, `let`s and `match` arms bind, and their types.

mod common;

use common::*;

#[test]
fn a_destructured_struct_binding_takes_the_field_type() {
    // `let Index { store, .. } = index;` binds `store` with the type its
    // field declares, in this file or another.
    let files = [
        ("src/lib.rs", "pub mod b;\npub mod index;\npub mod server;\n"),
        (
            "src/b.rs",
            "pub struct Store;\nimpl Store {\n    pub fn take(&self) {}\n}\n",
        ),
        (
            "src/index.rs",
            "use crate::b::Store;\npub struct Index {\n    pub store: Store,\n    pub count: usize,\n}\n",
        ),
        (
            "src/server.rs",
            "use crate::index::Index;\npub fn run(index: Index) {\n    let Index { store, .. } = index;\n    store.take();\n}\n",
        ),
    ];
    let contributions = scan_fixture("destructured-field-receiver", &files);

    assert!(
        method_calls(&contributions, "rust:function:crate::server::run")
            .contains(&("rust:method:crate::b::Store::take".to_owned(), true)),
        "{:?}",
        method_calls(&contributions, "rust:function:crate::server::run")
    );
}

#[test]
fn a_binding_takes_the_return_type_of_a_function_the_file_declares() {
    // `let trie = sample_trie();` in a test module: the helper declares
    // `-> PackedTrie`, but only `Type::f(..)` typed a binding, so a method
    // its tests alone call read as unreferenced instead of test-only.
    let source = r#"
pub struct Trie;
impl Trie {
    pub fn build() -> Self { Trie }
    pub fn child_count(&self) -> usize { 0 }
    pub fn root(&self) -> Node { Node }
    pub fn twin(&self) -> Self { Trie }
    pub fn mirrored(&self) {}
}
pub struct Node;
impl Node {
    pub fn depth(&self) {}
}
fn sample() -> Trie { Trie::build() }
fn boxed() -> Box<Trie> { Box::new(Trie) }
fn many() -> Vec<Trie> { Vec::new() }
pub fn run() {
    let trie = sample();
    trie.child_count();
    let node = trie.root();
    node.depth();
    let other = trie.twin();
    other.mirrored();
    let held = boxed();
    held.child_count();
    let all = many();
    all.len();
}
"#;
    let contributions = scan_fixture("let-from-return", &[("src/lib.rs", source)]);
    let calls = method_calls(&contributions, "rust:function:crate::run");

    for target in [
        "rust:method:crate::Trie::child_count",
        "rust:method:crate::Node::depth",
        "rust:method:crate::Trie::mirrored",
    ] {
        assert!(
            calls.iter().any(|(called, _)| called == target),
            "{target} in {calls:?}"
        );
    }
    // A collection's own methods are not its element's.
    assert!(
        !calls.iter().any(|(called, _)| called.contains("::len")),
        "{calls:?}"
    );
}

#[test]
fn a_let_chain_binds_each_pattern_before_the_conditions_after_it() {
    // In `if let Some(s) = o && s.go()`, the `s` the call names is the new
    // binding, an `Other`, not the `Store` parameter it shadows.
    let source = r#"
pub struct Store;
impl Store { pub fn go(&self) -> bool { true } }
pub struct Other;
pub fn in_if(s: Store, o: Option<Other>) { if let Some(s) = o && s.go() {} }
pub fn in_while(s: Store, mut v: Vec<Other>) { while let Some(s) = v.pop() && s.go() {} }
"#;
    let contributions = scan_fixture("let-chain-scope", &[("src/lib.rs", source)]);

    assert!(
        !all_call_targets(&contributions).contains(&"rust:method:crate::Store::go".to_owned()),
        "{:?}",
        all_call_targets(&contributions)
    );
}

#[test]
fn a_let_inside_a_block_does_not_outlive_the_block() {
    let source = r#"
pub struct Store;
impl Store { pub fn s_only(&self) {} }
pub struct Other;
impl Other { pub fn o_only(&self) {} pub fn s_only(&self) {} }
pub fn block(s: Store) { { let s: Other = Other; s.o_only(); } s.s_only(); }
pub fn in_loop(s: Store) { loop { let s: Other = Other; s.o_only(); break; } s.s_only(); }
pub fn in_unsafe(s: Store) { unsafe { let s: Other = Other; s.o_only(); } s.s_only(); }
"#;
    let contributions = scan_fixture("block-scope", &[("src/lib.rs", source)]);

    for function in ["block", "in_loop", "in_unsafe"] {
        let calls = method_calls(&contributions, &format!("rust:function:crate::{function}"));
        assert!(
            calls.contains(&("rust:method:crate::Store::s_only".to_owned(), true)),
            "{function}: {calls:?}"
        );
        assert!(
            calls.contains(&("rust:method:crate::Other::o_only".to_owned(), true)),
            "{function}: {calls:?}"
        );
        assert!(
            !calls
                .iter()
                .any(|(target, _)| target == "rust:method:crate::Other::s_only"),
            "{function}: {calls:?}"
        );
    }
}

#[test]
fn a_match_arm_or_if_let_binding_takes_the_type_the_scrutinee_holds() {
    // `let store = match parent { Some(p) => p.create_child()?, None =>
    // report::create()? };`: `p` is what the `Option<&ReportStore>` holds,
    // and the `match` has the type its arms agree on.
    let source = r#"
pub struct ReportStore;
pub fn create() -> Result<ReportStore, String> { Ok(ReportStore) }
impl ReportStore {
    pub fn create_child(&self) -> Result<ReportStore, String> { Ok(ReportStore) }
    pub fn intent_store(&self) {}
    pub fn native_report_path(&self) {}
    pub fn checked(&self) {}
    pub fn tail(&self) {}
}
pub struct Run { pub store: Option<std::sync::Arc<ReportStore>> }
pub fn run(parent: Option<&ReportStore>, held: &Run) -> Result<(), String> {
    let store = match parent {
        Some(p) => p.create_child()?,
        None => create()?,
    };
    store.native_report_path();
    store.intent_store();
    if let Some(inner) = &held.store {
        inner.checked();
    }
    let Some(last) = parent else { return Ok(()) };
    last.tail();
    Ok(())
}
"#;
    let contributions = scan_fixture("scrutinee-receivers", &[("src/lib.rs", source)]);
    let targets: Vec<String> = method_calls(&contributions, "rust:function:crate::run")
        .into_iter()
        .map(|(target, _)| target)
        .collect();

    for method in [
        "create_child",
        "native_report_path",
        "intent_store",
        "checked",
        "tail",
    ] {
        let target = format!("rust:method:crate::ReportStore::{method}");
        assert!(targets.contains(&target), "{target} in {targets:?}");
    }
}

#[test]
fn variant_patterns_and_adapter_closures_bind_their_declared_types() {
    // `Observation::Equals { actual } if !actual.valid()` binds the variant's
    // declared field; `values.iter().any(|v| v.valid())` hands the closure an
    // element of a `Vec<Value>`; `Some(v)` of a tuple variant its position.
    let source = r#"
pub struct Value;
impl Value {
    pub fn valid(&self) -> bool { true }
    pub fn equals(&self) -> bool { true }
    pub fn mapped(&self) -> bool { true }
    pub fn looped(&self) -> bool { true }
}
pub enum Observation {
    Equals { actual: Value },
    Many { values: Vec<Value> },
    One(Value),
}
pub fn check(observations: &[Observation], maybe: Option<Value>) -> bool {
    for observation in observations {
        match observation {
            Observation::Equals { actual } if !actual.valid() => return false,
            Observation::Many { values } => {
                if values.iter().filter(|v| v.valid()).any(|v| !v.equals()) {
                    return false;
                }
                for value in values.iter().rev() {
                    value.looped();
                }
            }
            Observation::One(single) => return single.valid(),
        }
    }
    maybe.is_some_and(|value| value.mapped())
}
"#;
    let contributions = scan_fixture("pattern-receivers", &[("src/lib.rs", source)]);
    let targets: Vec<String> = method_calls(&contributions, "rust:function:crate::check")
        .into_iter()
        .map(|(target, _)| target)
        .collect();

    for method in ["valid", "equals", "mapped", "looped"] {
        let target = format!("rust:method:crate::Value::{method}");
        assert!(targets.contains(&target), "{target} in {targets:?}");
    }
}

#[test]
fn a_rest_binding_in_a_slice_pattern_is_the_slice_not_an_element() {
    // In `[first, rest @ ..]`, `first` is one element and `rest` is the
    // rest of the slice: `rest.len()` is the slice's, not `Item::len`.
    let source = r#"
pub struct Item;
impl Item {
    pub fn len(&self) -> usize { 0 }
    pub fn name(&self) {}
}
pub fn split(items: &[Item]) {
    if let [first, rest @ ..] = items {
        first.name();
        let _ = rest.len();
    }
}
"#;
    let contributions = scan_fixture("slice-rest", &[("src/lib.rs", source)]);

    assert_eq!(
        vec![("rust:method:crate::Item::name".to_owned(), true)],
        method_calls(&contributions, "rust:function:crate::split")
    );
}

#[test]
fn a_path_declared_twice_with_different_return_types_types_no_binding() {
    // Two `#[cfg]` variants of one function return different types, so a
    // binding of a call to it holds neither for certain, whichever comes
    // last, and whether or not the other states a return type at all. The
    // same function declared once still types the binding.
    let source = r#"
mod other;
pub struct A;
impl A { pub fn hit(&self) {} }
pub struct B;
impl B { pub fn hit(&self) {} }
#[cfg(unix)]
pub fn open() -> A { A }
#[cfg(not(unix))]
pub fn open() -> B { B }
#[cfg(unix)]
pub fn bare() -> A { A }
#[cfg(not(unix))]
pub fn bare() {}
pub fn single() -> B { B }
pub fn run() {
    let v = open();
    v.hit();
}
pub fn run_bare() {
    let v = bare();
    v.hit();
}
pub fn run_single() {
    let v = single();
    v.hit();
}
"#;
    let other = r#"
pub fn elsewhere() {
    let v = crate::open();
    v.hit();
}
"#;
    let contributions = scan_fixture(
        "ambiguous-returns",
        &[("src/lib.rs", source), ("src/other.rs", other)],
    );

    let hits = |source: &str| -> Vec<(String, bool)> {
        method_calls(&contributions, source)
            .into_iter()
            .filter(|(target, _)| target.starts_with("rust:method:"))
            .collect()
    };
    assert!(hits("rust:function:crate::run").is_empty());
    assert!(hits("rust:function:crate::run_bare").is_empty());
    assert!(hits("rust:function:crate::other::elsewhere").is_empty());
    assert_eq!(
        vec![("rust:method:crate::B::hit".to_owned(), true)],
        hits("rust:function:crate::run_single")
    );
}

#[test]
fn a_match_arm_handing_back_what_ok_or_some_holds_has_that_type() {
    // `let client = match Client::start() { Ok(c) => c, Err(e) => return };`
    let source = r#"
pub struct Client;
impl Client {
    pub fn start() -> Result<Self, String> { Ok(Client) }
    pub fn export_recovery(&self) {}
}
pub fn run() {
    let client = match Client::start() {
        Ok(c) => c,
        Err(_) => return,
    };
    client.export_recovery();
}
"#;
    let contributions = scan_fixture("ok-arm-receiver", &[("src/lib.rs", source)]);
    let targets: Vec<String> = method_calls(&contributions, "rust:function:crate::run")
        .into_iter()
        .map(|(target, _)| target)
        .collect();

    assert!(
        targets.contains(&"rust:method:crate::Client::export_recovery".to_owned()),
        "{targets:?}"
    );
}

#[test]
fn if_let_some_of_a_find_over_a_vec_field_binds_the_element() {
    let source = r#"
pub struct Entry { pub key: u8 }
impl Entry {
    pub fn start_data(&self) -> u8 { self.key }
}
pub struct Registry { entries: Vec<Entry> }
impl Registry {
    pub fn admit(&self, key: u8) -> Option<u8> {
        if let Some(entry) = self.entries.iter().find(|e| e.key == key) {
            return Some(entry.start_data());
        }
        while let Some(last) = self.entries.last() {
            return Some(last.start_data());
        }
        None
    }
}
"#;
    let contributions = scan_fixture("vec-find", &[("src/lib.rs", source)]);

    assert_eq!(
        vec![
            "rust:method:crate::Entry::start_data".to_owned(),
            "rust:method:crate::Entry::start_data".to_owned()
        ],
        method_targets(&contributions, "rust:method:crate::Registry::admit")
    );
}

#[test]
fn a_closure_of_and_then_after_a_map_to_a_variant_takes_the_enum() {
    // Every arm is `from_value(..).map(ToolCall::Variant)`, a `Result` of
    // `ToolCall`, so `.and_then(|call| ..)` hands the closure a `ToolCall`.
    let source = r#"
pub struct StartArgs;
pub struct ListArgs;
pub struct ArgumentError;
pub enum ToolCall {
    RunStart(StartArgs),
    RunList(ListArgs),
}
impl ToolCall {
    pub fn validate(&self) -> Result<(), ArgumentError> { Ok(()) }
}
fn parse<T>(_: &str) -> Result<T, ()> { Err(()) }
pub fn tool_call(name: &str) -> Option<Result<ToolCall, ArgumentError>> {
    let result = match name {
        "run_start" => parse(name).map(ToolCall::RunStart),
        "run_list" => parse(name).map(ToolCall::RunList),
        _ => return None,
    }
    .map_err(|_| ArgumentError)
    .and_then(|call| {
        call.validate()?;
        Ok(call)
    });
    Some(result)
}
"#;
    let contributions = scan_fixture("variant-map", &[("src/lib.rs", source)]);

    assert_eq!(
        vec!["rust:method:crate::ToolCall::validate".to_owned()],
        method_targets(&contributions, "rust:function:crate::tool_call")
    );
}
