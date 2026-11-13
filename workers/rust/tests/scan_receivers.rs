//! Scanning tests driving the worker against real files on disk:
//! receiver types through fields, pointers, collections and generics.

mod common;

use common::*;

#[test]
fn an_indexed_array_slice_or_vec_receives_as_its_element() {
    // `grid.tiles[x][y].is_selectable()` calls `Tile::is_selectable` through
    // a nested array field. Indexing lost the receiver's type, so a method
    // only ever reached through a collection read as unreferenced.
    let files = [
        ("src/lib.rs", "pub mod grid;\npub mod dfs;\n"),
        (
            "src/grid.rs",
            "pub struct Tile;\nimpl Tile {\n    pub fn nested(&self) {}\n    pub fn listed(&self) {}\n    pub fn sliced(&self) {}\n    pub fn boxed(&self) {}\n    pub fn walked(&self) {}\n    pub fn iterated(&self) {}\n    pub fn bound(&self) {}\n}\npub struct Grid {\n    pub tiles: [[Tile; 4]; 4],\n    pub rows: Vec<Box<Tile>>,\n}\n",
        ),
        (
            "src/dfs.rs",
            "use crate::grid::{Grid, Tile};\npub struct Walk<'a> {\n    grid: &'a Grid,\n}\nimpl Walk<'_> {\n    pub fn go(&self, all: &[Tile]) {\n        let (grid, n) = (self.grid, 0);\n        grid.tiles[n][n].nested();\n        self.grid.rows[0].listed();\n        all[0].sliced();\n        grid.rows[1].boxed();\n        for tile in all {\n            tile.walked();\n        }\n        for tile in grid.rows.iter() {\n            tile.iterated();\n        }\n        let row = grid.tiles[n];\n        row[n].bound();\n        grid.tiles.len();\n    }\n}\n",
        ),
    ];
    let contributions = scan_fixture("indexed-receiver", &files);
    let calls = method_calls(&contributions, "rust:method:crate::dfs::Walk::go");

    for method in [
        "nested", "listed", "sliced", "boxed", "walked", "iterated", "bound",
    ] {
        let target = format!("rust:method:crate::grid::Tile::{method}");
        assert!(
            calls.contains(&(target.clone(), true)),
            "{target} in {calls:?}"
        );
    }
    // The collection's own methods are not its element's.
    assert!(
        !calls.iter().any(|(target, _)| target.contains("::len")),
        "{calls:?}"
    );
}

#[test]
fn a_smart_pointer_field_or_parameter_receives_as_its_pointee() {
    // `Box<T>`, `Rc<T>` and `Arc<T>` dereference to `T`, so a method called
    // through one is `T`'s.
    let files = [
        ("src/lib.rs", "pub mod a;\npub mod b;\npub mod c;\n"),
        (
            "src/b.rs",
            "pub struct Store;\nimpl Store {\n    pub fn put(&self) {}\n    pub fn record(&self) {}\n    pub fn flush(&self) {}\n    pub fn own(&self) {}\n}\n",
        ),
        (
            "src/a.rs",
            "use std::rc::Rc;\nuse std::sync::Arc;\nuse crate::b::Store;\npub struct Holder {\n    pub(crate) boxed: Box<Store>,\n    pub(crate) shared: Rc<Store>,\n    pub(crate) synced: std::sync::Arc<Store>,\n    pub(crate) many: Vec<Store>,\n    pub(crate) other: Arc<Store>,\n}\n",
        ),
        (
            "src/c.rs",
            "use crate::a::Holder;\nuse crate::b::Store;\nimpl Holder {\n    pub fn save(&self, own: Box<Store>) {\n        self.boxed.put();\n        self.shared.record();\n        self.synced.flush();\n        own.own();\n        self.many.len();\n    }\n}\n",
        ),
    ];
    let contributions = scan_fixture("smart-pointer-receiver", &files);
    let calls = method_calls(&contributions, "rust:method:crate::a::Holder::save");

    for method in ["put", "record", "flush", "own"] {
        let target = format!("rust:method:crate::b::Store::{method}");
        assert!(
            calls.contains(&(target.clone(), true)),
            "{target} in {calls:?}"
        );
    }
    assert!(
        !calls
            .iter()
            .any(|(target, _)| target.contains("Store::len")),
        "{calls:?}"
    );
}

#[test]
fn a_method_the_smart_pointer_itself_has_is_not_the_pointees() {
    // `a.clone()` on an `Arc<Store>` clones the `Arc`; `a.go()` derefs.
    let source = r#"
pub struct Store;
impl Store {
    pub fn go(&self) {}
    pub fn clone(&self) -> Store { Store }
    pub fn as_ref(&self) -> &Store { self }
}
pub fn run(a: std::sync::Arc<Store>, b: Box<Store>) {
    a.clone();
    b.as_ref();
    a.go();
}
"#;
    let contributions = scan_fixture("pointer-own-methods", &[("src/lib.rs", source)]);
    let calls = method_calls(&contributions, "rust:function:crate::run");

    assert!(
        calls.contains(&("rust:method:crate::Store::go".to_owned(), true)),
        "{calls:?}"
    );
    assert!(
        !calls.iter().any(
            |(target, _)| target.ends_with("Store::clone") || target.ends_with("Store::as_ref")
        ),
        "{calls:?}"
    );
}

#[test]
fn a_bound_naming_another_type_parameter_does_not_loop() {
    // `T: U, U: T` is not valid Rust, but it parses, and a bound that names
    // a type parameter must not be followed back to where it started, with
    // or without arguments (`A: B<A>, B: A<B>`). The receiver stays untyped.
    let source = r#"
pub trait Driver {
    fn written(&mut self);
}
pub fn bare<T: U, U: T>(value: &T) {
    value.written();
}
pub fn argued<A: B<A>, B: A<B>>(value: &A) {
    value.written();
}
pub fn mixed<D: E + Driver, E: D>(value: &D) {
    value.written();
}
"#;
    let contributions = scan_fixture("cyclic-bounds", &[("src/lib.rs", source)]);

    assert!(method_calls(&contributions, "rust:function:crate::bare").is_empty());
    assert!(method_calls(&contributions, "rust:function:crate::argued").is_empty());
    assert_eq!(
        vec![("rust:method:crate::Driver::written".to_owned(), true)],
        method_calls(&contributions, "rust:function:crate::mixed")
    );
}

#[test]
fn a_parameter_of_enum_type_taken_by_value_is_typed() {
    let source = r#"
#[derive(Clone, Copy)]
pub enum ErrorCode { Stale, Large }
pub struct ErrorPolicy;
impl ErrorCode {
    pub fn policy(self) -> ErrorPolicy { ErrorPolicy }
}
pub struct Failure;
impl Failure {
    pub fn new(code: ErrorCode, message: String) -> Self {
        let _policy = code.policy();
        let _ = message;
        Failure
    }
}
"#;
    let contributions = scan_fixture("enum-param", &[("src/lib.rs", source)]);

    assert_eq!(
        vec!["rust:method:crate::ErrorCode::policy".to_owned()],
        method_targets(&contributions, "rust:method:crate::Failure::new")
    );
}

#[test]
fn an_option_field_taken_through_as_ref_and_unwrap_holds_its_type() {
    let source = r#"
pub struct CommandProvider;
impl CommandProvider {
    pub fn invoke(&mut self) -> u8 { 0 }
    pub fn validate_original_baseline(&self, _: u8) -> bool { true }
}
pub struct Owners { pub provider: Option<CommandProvider> }
pub fn run(owners: &mut Owners) -> bool {
    let receipt = owners.provider.as_mut().unwrap().invoke();
    owners
        .provider
        .as_ref()
        .unwrap()
        .validate_original_baseline(receipt)
}
"#;
    let contributions = scan_fixture("option-field", &[("src/lib.rs", source)]);

    assert_eq!(
        vec![
            "rust:method:crate::CommandProvider::invoke".to_owned(),
            "rust:method:crate::CommandProvider::validate_original_baseline".to_owned()
        ],
        method_targets(&contributions, "rust:function:crate::run")
    );
}

#[test]
fn a_closure_handed_to_an_option_predicate_takes_what_the_option_holds() {
    // `self.desktop.as_ref().is_none_or(|d| d.forward_active())`
    let source = r#"
pub struct DesktopHooks { forward: bool }
impl DesktopHooks {
    pub fn forward_active(&self) -> bool { self.forward }
    pub fn other(&self) -> bool { self.forward }
}
pub struct Driver { desktop: Option<DesktopHooks> }
impl Driver {
    pub fn active(&self) -> bool {
        self.desktop.as_ref().is_none_or(|d| d.forward_active())
            && self.desktop.as_ref().is_some_and(|d| d.other())
            && self.desktop.as_ref().map_or(true, |d| d.other())
    }
}
"#;
    let contributions = scan_fixture("option-predicate", &[("src/lib.rs", source)]);

    assert_eq!(
        vec![
            "rust:method:crate::DesktopHooks::forward_active".to_owned(),
            "rust:method:crate::DesktopHooks::other".to_owned(),
            "rust:method:crate::DesktopHooks::other".to_owned()
        ],
        method_targets(&contributions, "rust:method:crate::Driver::active")
    );
}

#[test]
fn a_result_combinator_before_the_question_mark_keeps_the_ok_type() {
    // `CursorRecord::evidence(..) -> Result<Self, ErrorCode>`, then
    // `.map_err(error)?`: the binding is a `CursorRecord`.
    let source = r#"
pub struct ErrorCode;
pub struct QueryError;
fn error(_: ErrorCode) -> QueryError { QueryError }
pub struct CursorRecord;
impl CursorRecord {
    pub fn evidence(_: u8) -> Result<Self, ErrorCode> { Ok(CursorRecord) }
    pub fn change_token(&self) -> u8 { 0 }
    pub fn revision(&self) -> u8 { 0 }
    pub fn found(&self) -> u8 { 0 }
}
pub fn run(found: Option<CursorRecord>) -> Result<u8, QueryError> {
    let cursor = CursorRecord::evidence(1).map_err(error)?;
    let other = found.ok_or(QueryError)?;
    let third = CursorRecord::evidence(2).ok().ok_or_else(|| QueryError)?;
    Ok(cursor.change_token() + other.revision() + third.found())
}
"#;
    let contributions = scan_fixture("result-chain", &[("src/lib.rs", source)]);
    let mut targets: Vec<String> = method_targets(&contributions, "rust:function:crate::run")
        .into_iter()
        .filter(|target| !target.ends_with("::evidence"))
        .collect();
    targets.dedup();

    assert_eq!(
        vec![
            "rust:method:crate::CursorRecord::change_token".to_owned(),
            "rust:method:crate::CursorRecord::found".to_owned(),
            "rust:method:crate::CursorRecord::revision".to_owned()
        ],
        targets
    );
}
