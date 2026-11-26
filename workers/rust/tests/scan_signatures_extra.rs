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

#[test]
fn enums_and_unions_are_marked_apart_from_cases() {
    let nodes = scan(
        "pub enum Mode { A, B }\n\
         pub union Bits { a: u32, b: f32 }\n\
         pub struct Plain;\n",
    );

    assert_eq!(
        json!("enum"),
        nodes["crate::Mode"]["attributes"]["rust_item"]
    );
    assert_eq!(
        json!("union"),
        nodes["crate::Bits"]["attributes"]["rust_item"]
    );
    assert!(nodes["crate::Plain"]["attributes"]
        .get("rust_item")
        .is_none());
}

#[test]
fn a_trait_impl_method_names_the_trait_as_written() {
    let nodes = scan(
        "pub trait Repo<T> { fn get(&self) -> Option<T>; }\n\
         pub struct Store;\n\
         impl Store { pub fn own(&self) {} }\n\
         impl Repo<String> for Store { fn get(&self) -> Option<String> { None } }\n",
    );

    assert_eq!(
        json!("Repo<String>"),
        nodes["crate::Store::<impl Repo<String>>::get"]["attributes"]["impl_trait"]
    );
    assert!(nodes["crate::Store::own"]["attributes"]
        .get("impl_trait")
        .is_none());
}

#[test]
fn a_traits_associated_types_and_consts_are_listed() {
    let nodes = scan(
        "pub trait WithAssoc {\n    type Output;\n    type Item: Clone;\n    const LIMIT: usize;\n    fn produce(&self) -> Self::Output;\n}\n\
         pub trait Bare { fn f(&self); }\n",
    );
    let attributes = &nodes["crate::WithAssoc"]["attributes"];

    assert_eq!(
        json!(["Output", "Item: Clone"]),
        attributes["associated_types"]
    );
    assert_eq!(
        json!([{"name": "LIMIT", "type": "usize"}]),
        attributes["associated_consts"]
    );
    assert!(nodes["crate::Bare"]["attributes"]
        .get("associated_types")
        .is_none());
}

#[test]
fn a_trait_impls_associated_items_ride_on_its_methods() {
    let nodes = scan(
        "pub trait WithAssoc { type Output; const LIMIT: usize; const NAME: &'static str; fn produce(&self) -> Self::Output; fn limit(&self) -> usize; }\n\
         pub struct Producer;\n\
         impl WithAssoc for Producer {\n    type Output = u8;\n    const LIMIT: usize = 3;\n    const NAME: &'static str = concat!(\"a\", \"b\");\n    fn produce(&self) -> u8 { 0 }\n    fn limit(&self) -> usize { 3 }\n}\n\
         pub trait Plain { fn f(&self); }\n\
         impl Plain for Producer { fn f(&self) {} }\n",
    );
    let produce = &nodes["crate::Producer::<impl WithAssoc>::produce"]["attributes"];

    assert_eq!(
        json!([{"name": "Output", "type": "u8"}]),
        produce["impl_types"]
    );
    // A literal value is sent; any other expression is not.
    assert_eq!(
        json!([{"name": "LIMIT", "type": "usize", "value": "3"}, {"name": "NAME", "type": "&'static str"}]),
        produce["impl_consts"]
    );
    assert_eq!(
        produce["impl_types"],
        nodes["crate::Producer::<impl WithAssoc>::limit"]["attributes"]["impl_types"]
    );
    let plain = &nodes["crate::Producer::<impl Plain>::f"]["attributes"];
    assert!(plain.get("impl_types").is_none());
    assert!(plain.get("impl_consts").is_none());
}
