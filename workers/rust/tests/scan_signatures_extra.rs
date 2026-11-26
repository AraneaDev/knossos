//! Scanning tests for what a mock of a trait or struct needs besides the
//! parameters: each method's receiver, its written visibility, whether it is
//! `async` or `unsafe`, and its type parameters.

mod common;

use common::*;
use serde_json::{json, Value};

/// The method and function nodes, and the trait and struct nodes, of one
/// scan of `source` as `src/lib.rs`, by canonical name.
fn scan(source: &str) -> std::collections::BTreeMap<String, Value> {
    // A root of its own per scan: tests run at once.
    static SCANS: std::sync::atomic::AtomicUsize = std::sync::atomic::AtomicUsize::new(0);
    let scan = SCANS.fetch_add(1, std::sync::atomic::Ordering::SeqCst);
    let contributions = scan_fixture(
        &format!("signatures-extra-{scan}"),
        &[("src/lib.rs", source)],
    );
    let mut nodes = nodes_of(&contributions, "method");
    nodes.extend(nodes_of(&contributions, "function"));
    nodes.extend(nodes_of(&contributions, "interface"));
    nodes.extend(nodes_of(&contributions, "class"));
    nodes
}

/// The attributes the mock synthesis reads, as sent, for one node.
fn extra(nodes: &std::collections::BTreeMap<String, Value>, canonical: &str) -> Value {
    let attributes = &nodes[canonical]["attributes"];
    let mut out = serde_json::Map::new();
    for key in ["visibility", "async", "unsafe", "type_params", "where"] {
        if let Some(value) = attributes.get(key) {
            out.insert(key.to_owned(), value.clone());
        }
    }
    let signature = &attributes["signature"];
    for key in ["receiver", "type_params", "where"] {
        if let Some(value) = signature.get(key) {
            out.insert(format!("signature.{key}"), value.clone());
        }
    }
    Value::Object(out)
}

#[test]
fn receivers_visibility_and_async_are_recorded() {
    let nodes = scan(
        "pub trait Repo {\n    fn find(&self, id: u32) -> u32;\n    fn save(&mut self, value: u32) -> bool;\n    fn take(self) -> u8;\n    fn take_mut(mut self) -> u8;\n    fn boxed(self: Box<Self>);\n    async fn go(&self) -> u8;\n    unsafe fn raw(&self);\n}\n\
         pub struct S;\n\
         impl S {\n    pub fn open(&self) {}\n    pub(crate) async fn crate_wide(&self) {}\n    pub(super) fn parent(&self) {}\n    pub(in crate::a) fn scoped(&self) {}\n    fn hidden(&self) {}\n    pub unsafe fn danger(&'_ self) {}\n}\n\
         pub async fn free() {}\n\
         fn private_free() {}\n",
    );

    assert_eq!(
        json!({"signature.receiver": "&self"}),
        extra(&nodes, "crate::Repo::find")
    );
    assert_eq!(
        json!({"signature.receiver": "&mut self"}),
        extra(&nodes, "crate::Repo::save")
    );
    assert_eq!(
        json!({"signature.receiver": "self"}),
        extra(&nodes, "crate::Repo::take")
    );
    assert_eq!(
        json!({"signature.receiver": "mut self"}),
        extra(&nodes, "crate::Repo::take_mut")
    );
    assert_eq!(
        json!({"signature.receiver": "self: Box<Self>"}),
        extra(&nodes, "crate::Repo::boxed")
    );
    // A trait item sends no visibility: it is as public as its trait.
    assert_eq!(
        json!({"async": true, "signature.receiver": "&self"}),
        extra(&nodes, "crate::Repo::go")
    );
    assert_eq!(
        json!({"unsafe": true, "signature.receiver": "&self"}),
        extra(&nodes, "crate::Repo::raw")
    );
    // Plain `pub` sends nothing, so absent means public in every language.
    assert_eq!(
        json!({"signature.receiver": "&self"}),
        extra(&nodes, "crate::S::open")
    );
    assert_eq!(
        json!({"visibility": "pub(crate)", "async": true, "signature.receiver": "&self"}),
        extra(&nodes, "crate::S::crate_wide")
    );
    assert_eq!(
        json!({"visibility": "pub(super)", "signature.receiver": "&self"}),
        extra(&nodes, "crate::S::parent")
    );
    assert_eq!(
        json!({"visibility": "pub(in crate::a)", "signature.receiver": "&self"}),
        extra(&nodes, "crate::S::scoped")
    );
    assert_eq!(
        json!({"visibility": "private", "signature.receiver": "&self"}),
        extra(&nodes, "crate::S::hidden")
    );
    assert_eq!(
        json!({"unsafe": true, "signature.receiver": "&'_ self"}),
        extra(&nodes, "crate::S::danger")
    );
    assert_eq!(json!({"async": true}), extra(&nodes, "crate::free"));
    assert_eq!(
        json!({"visibility": "private"}),
        extra(&nodes, "crate::private_free")
    );
    // The parameters are unchanged: the receiver is still not one a caller passes.
    assert_eq!(
        json!([{"name": "id", "type": "u32"}]),
        nodes["crate::Repo::find"]["attributes"]["signature"]["params"]
    );
}

#[test]
fn associated_functions_have_no_receiver() {
    let nodes = scan(
        "pub trait Make {\n    fn make() -> Self;\n    fn with(id: u32) -> Self;\n    fn id(&self) -> u32;\n}\n\
         pub struct S;\n\
         impl S {\n    pub fn new() -> Self { S }\n}\n\
         impl Make for S {\n    fn make() -> Self { S }\n    fn with(id: u32) -> Self { let _ = id; S }\n    fn id(&self) -> u32 { 0 }\n}\n",
    );

    assert_eq!(json!({}), extra(&nodes, "crate::Make::make"));
    assert_eq!(json!({}), extra(&nodes, "crate::Make::with"));
    assert_eq!(json!({}), extra(&nodes, "crate::S::new"));
    // A trait impl's method is as public as the trait: no visibility.
    assert_eq!(json!({}), extra(&nodes, "crate::S::<impl Make>::make"));
    assert_eq!(
        json!({"signature.receiver": "&self"}),
        extra(&nodes, "crate::S::<impl Make>::id")
    );
}

#[test]
fn type_parameters_and_where_clauses_are_recorded_as_written() {
    let nodes = scan(
        "pub trait Store<T: Clone, 'a, const N: usize> where T: Send {\n    fn get<U: Into<T>>(&self, key: U) -> T where U: Copy;\n    fn plain(&self) -> T;\n}\n\
         pub struct Holder<T>(T);\n",
    );

    assert_eq!(
        json!({"type_params": ["T: Clone", "'a", "const N: usize"], "where": "where T: Send"}),
        extra(&nodes, "crate::Store")
    );
    assert_eq!(
        json!({"signature.receiver": "&self", "signature.type_params": ["U: Into<T>"], "signature.where": "where U: Copy"}),
        extra(&nodes, "crate::Store::get")
    );
    assert_eq!(
        json!({"signature.receiver": "&self"}),
        extra(&nodes, "crate::Store::plain")
    );
    assert_eq!(
        json!({"type_params": ["T"]}),
        extra(&nodes, "crate::Holder")
    );
}
