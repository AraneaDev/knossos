//! Scanning tests driving the worker against real files on disk:
//! traits, trait impls and the standard library's prelude traits.

mod common;

use common::*;

#[test]
fn a_qualified_path_call_resolves_to_the_traits_declared_method() {
    let source = r#"
pub trait Named {
    fn name() -> &'static str;
}

pub struct Engine;

impl Named for Engine {
    fn name() -> &'static str {
        "engine"
    }
}

pub fn describe() {
    <Engine as Named>::name();
}
"#;
    let contributions = scan_fixture("qualified-path-call", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(edges.iter().any(|edge| {
        edge["kind"] == "calls"
            && edge["source"] == "rust:function:crate::describe"
            && edge["target"] == "rust:method:crate::Named::name"
    }));
}

#[test]
fn a_call_inside_a_trait_default_body_resolves() {
    let source = r#"
pub fn helper() {}

pub trait Greeter {
    fn greet(&self) {
        helper();
    }
}
"#;
    let contributions = scan_fixture("trait-default-body-call", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(edges.iter().any(|edge| {
        edge["kind"] == "calls"
            && edge["source"] == "rust:method:crate::Greeter::greet"
            && edge["target"] == "rust:function:crate::helper"
    }));
}

#[test]
fn a_blanket_trait_impl_over_a_type_parameter_is_a_node_that_implements_the_trait() {
    // `impl<'a, M: Matcher> Matcher for &'a M` names no type to attach its
    // methods to, so the whole block was dropped: the trait read as having
    // fewer implementations, and adding a required method missed this file.
    let source = r#"
pub trait Matcher { fn find(&self) -> bool; }
pub struct Plain;
impl Matcher for Plain { fn find(&self) -> bool { true } }
impl<'a, M: Matcher> Matcher for &'a M {
    fn find(&self) -> bool { (**self).find() }
}
impl<M: Matcher> Matcher for (M, Plain) {
    fn find(&self) -> bool { self.0.find() }
}
"#;
    let contributions = scan_fixture("blanket-trait-impl", &[("src/lib.rs", source)]);
    let names: Vec<String> = contributions
        .iter()
        .flat_map(|contribution| contribution["nodes"].as_array().unwrap().clone())
        .map(|node| node["canonical_name"].as_str().unwrap().to_owned())
        .collect();
    let implements = edges_of(&contributions, "implements");

    for block in [
        "crate::<impl Matcher for &M>",
        "crate::<impl Matcher for (M, Plain)>",
    ] {
        assert!(
            names.contains(&block.to_owned()),
            "{block} missing from {names:?}"
        );
        assert!(
            names.contains(&format!("{block}::find")),
            "{block}::find missing from {names:?}"
        );
        assert!(
            implements.contains(&(
                format!("rust:class:{block}"),
                "rust:interface:crate::Matcher".to_owned()
            )),
            "{implements:?}"
        );
    }
}

#[test]
fn a_trait_impl_for_a_foreign_type_is_a_node_of_the_module_declaring_it() {
    // `impl<T: GitSource> GitSource for Arc<T>` attached its methods to
    // `std::sync::Arc`, a name the project does not own, with nothing tying
    // them to the trait they implement, so every forwarding method read as
    // dead. The impl block is the project's, and it implements the trait.
    let source = r#"
use std::sync::Arc;
pub trait GitSource { fn status(&self); }
pub struct Repo;
impl GitSource for Repo { fn status(&self) {} }
impl<T: GitSource> GitSource for Arc<T> {
    fn status(&self) { (**self).status() }
}
"#;
    let contributions = scan_fixture("foreign-trait-impl", &[("src/lib.rs", source)]);
    let names: Vec<String> = contributions
        .iter()
        .flat_map(|contribution| contribution["nodes"].as_array().unwrap().clone())
        .map(|node| node["canonical_name"].as_str().unwrap().to_owned())
        .collect();

    assert!(
        !names.iter().any(|name| name.starts_with("std::")),
        "{names:?}"
    );
    assert!(
        names.contains(&"crate::<impl GitSource for Arc>::status".to_owned()),
        "{names:?}"
    );
    assert!(edges_of(&contributions, "implements").contains(&(
        "rust:class:crate::<impl GitSource for Arc>".to_owned(),
        "rust:interface:crate::GitSource".to_owned()
    )));
}

#[test]
fn a_prelude_trait_that_nothing_declares_is_the_standard_library_trait() {
    // `impl From<u8> for Str` names the prelude's `From`, not a `From` the
    // module would declare: the fallback that places an unknown name in the
    // enclosing module made a certain `implements` edge to `crate::util::From`.
    let files = [
        ("src/lib.rs", "pub mod util;\n"),
        (
            "src/util.rs",
            "pub struct Str;\npub trait Display {}\nimpl From<u8> for Str {\n    fn from(_: u8) -> Self {\n        Str\n    }\n}\nimpl Clone for Str {\n    fn clone(&self) -> Self {\n        Str\n    }\n}\nimpl Display for Str {}\n",
        ),
    ];
    let contributions = scan_fixture("prelude-trait-impl", &files);
    let implements: Vec<String> = contributions
        .iter()
        .flat_map(|contribution| contribution["edges"].as_array().unwrap().clone())
        .filter(|edge| edge["kind"] == "implements")
        .map(|edge| edge["target"].as_str().unwrap().to_owned())
        .collect();

    assert!(
        implements.contains(&"rust:interface:std::convert::From".to_owned()),
        "{implements:?}"
    );
    assert!(
        implements.contains(&"rust:interface:std::clone::Clone".to_owned()),
        "{implements:?}"
    );
    assert!(
        implements.contains(&"rust:interface:crate::util::Display".to_owned()),
        "{implements:?}"
    );
    assert!(
        !implements
            .iter()
            .any(|target| target.ends_with("util::From") || target.ends_with("util::Clone")),
        "{implements:?}"
    );
}

#[test]
fn only_prelude_traits_are_taken_from_the_standard_library_by_bare_name() {
    // `Clone` is in the prelude, so even a crate-root `trait Clone` is not
    // in scope in `sub` without an import. `Display` is not in the prelude.
    let files = [
        ("src/lib.rs", "pub trait Clone {}\npub mod sub;\n"),
        (
            "src/sub.rs",
            "pub struct S;\nimpl Clone for S {\n    fn clone(&self) -> Self {\n        S\n    }\n}\nimpl Display for S {}\n",
        ),
    ];
    let contributions = scan_fixture("prelude-only", &files);
    let implements: Vec<String> = contributions
        .iter()
        .flat_map(|contribution| contribution["edges"].as_array().unwrap().clone())
        .filter(|edge| edge["kind"] == "implements")
        .map(|edge| edge["target"].as_str().unwrap().to_owned())
        .collect();

    assert!(
        implements.contains(&"rust:interface:std::clone::Clone".to_owned()),
        "{implements:?}"
    );
    assert!(
        !implements.contains(&"rust:interface:std::fmt::Display".to_owned()),
        "{implements:?}"
    );
}

#[test]
fn a_trait_impl_for_a_standard_type_named_bare_is_not_a_type_of_the_module() {
    // `impl PartialEq<u8> for String` and `impl Shout for str` implement a
    // trait for types the crate does not own, named without an import: the
    // methods belong to the impl block, never to a made-up `crate::String`,
    // and `self.as_str()` is no call into the crate. Neither is a receiver
    // typed as a function the module happens to name like a primitive.
    let source = r#"
pub trait Shout { fn shout(&self); }
impl PartialEq<u8> for String {
    fn eq(&self, _: &u8) -> bool { self.as_str(); true }
}
impl Shout for str {
    fn shout(&self) { self.to_owned(); }
}
pub fn char(s: &str) -> &str { s }
pub fn trim(s: &str) -> &str { s.trim_start_matches(|c: char| c.is_whitespace()) }
pub fn loud(s: &str) { str::shout(s); }
"#;
    let contributions = scan_fixture("trait-impl-standard-type", &[("src/lib.rs", source)]);
    let nodes: Vec<String> = contributions
        .iter()
        .flat_map(|contribution| contribution["nodes"].as_array().unwrap().clone())
        .map(|node| node["canonical_name"].as_str().unwrap().to_owned())
        .collect();
    let targets = all_call_targets(&contributions);

    assert!(
        !nodes
            .iter()
            .any(|node| node.starts_with("crate::String") || node.starts_with("crate::str::")),
        "{nodes:?}"
    );
    assert!(
        !targets
            .iter()
            .any(|target| target.contains("String::as_str")
                || target.contains("str::to_owned")
                || target.contains("char::is_whitespace")
                || target == "rust:function:crate::str::shout"
                || target == "rust:method:crate::str::shout"),
        "{targets:?}"
    );
}

#[test]
fn a_trait_method_and_an_inherent_method_of_one_name_are_two_nodes() {
    // `impl Driver for Session` beside `impl Session` declares two items
    // named `receive`; both used to be `Session::receive`, so the reconciler
    // kept one and dropped the other's evidence. The trait impl's method now
    // names its impl, and `Session::receive` stays the inherent one.
    let session = r#"
pub struct Session;
impl Session {
    pub fn receive(&mut self) -> u8 { 1 }
}
"#;
    let driver = r#"
use crate::session::Session;
pub trait Driver { fn receive(&mut self) -> u8; }
impl Driver for Session {
    fn receive(&mut self) -> u8 { Session::receive(self) }
}
impl From<u8> for Session { fn from(_: u8) -> Self { Session } }
impl From<u16> for Session { fn from(_: u16) -> Self { Session } }
"#;
    let contributions = scan_fixture(
        "trait-and-inherent-method",
        &[
            ("src/lib.rs", "pub mod driver;\npub mod session;\n"),
            ("src/session.rs", session),
            ("src/driver.rs", driver),
        ],
    );
    let names = canonical_names(&contributions);
    for name in [
        "crate::session::Session::receive",
        "crate::session::Session::<impl Driver>::receive",
        "crate::session::Session::<impl From<u8>>::from",
        "crate::session::Session::<impl From<u16>>::from",
    ] {
        assert_eq!(
            1,
            names.iter().filter(|known| *known == name).count(),
            "{name} in {names:?}"
        );
    }
    assert!(targets_of(
        &contributions,
        "calls",
        "rust:method:crate::session::Session::<impl Driver>::receive"
    )
    .contains(&"rust:method:crate::session::Session::receive".to_owned()));
    assert!(targets_of(
        &contributions,
        "contains",
        "rust:class:crate::session::Session"
    )
    .contains(&"rust:method:crate::session::Session::<impl Driver>::receive".to_owned()));
}

#[test]
fn a_same_file_call_to_a_trait_impl_method_by_its_type_is_kept() {
    // `Widget::default()` names the method `impl Default for Widget` declares
    // in this file, which is now `Widget::<impl Default>::default`: the call
    // is confirmed against that declaration and keeps the path it wrote.
    let source = r#"
pub struct Widget;
impl Default for Widget { fn default() -> Self { Widget } }
pub fn make() -> Widget { Widget::default() }
"#;
    let contributions = scan_fixture("trait-impl-same-file-call", &[("src/lib.rs", source)]);
    assert!(canonical_names(&contributions)
        .contains(&"crate::Widget::<impl Default>::default".to_owned()));
    assert!(
        targets_of(&contributions, "calls", "rust:function:crate::make")
            .contains(&"rust:method:crate::Widget::default".to_owned()),
        "{:?}",
        targets_of(&contributions, "calls", "rust:function:crate::make")
    );
}

#[test]
fn a_trait_impl_block_of_its_own_is_marked_as_one() {
    // `unsafe impl NativeValue for u32 {}` is used wherever `T: NativeValue`
    // is met, which no edge records.
    let source = "pub unsafe trait NativeValue: Copy {}\nunsafe impl NativeValue for u32 {}\npub fn read<T: NativeValue>(v: T) -> T { v }\n";
    let contributions = scan_fixture("marker-trait-impl", &[("src/lib.rs", source)]);
    let block = node_in(
        &contributions,
        "src/lib.rs",
        "crate::<impl NativeValue for u32>",
    )
    .expect("the block is a node");
    assert_eq!(Some(true), block["attributes"]["trait_impl"].as_bool());
}

#[test]
fn a_method_called_on_a_dyn_or_impl_trait_value_is_the_traits() {
    let source = r#"
pub trait Driver { fn receive(&mut self) -> u8; }
pub fn drive(driver: &mut dyn Driver) -> u8 { driver.receive() }
pub fn boxed(driver: Box<dyn Driver>) -> Box<dyn Driver> { driver }
pub fn later(mut driver: impl Driver) -> u8 { driver.receive() }
"#;
    let contributions = scan_fixture("dyn-trait-receiver", &[("src/lib.rs", source)]);
    for from in ["rust:function:crate::drive", "rust:function:crate::later"] {
        let calls = targets_of(&contributions, "calls", from);
        assert!(
            calls.contains(&"rust:method:crate::Driver::receive".to_owned()),
            "{from}: {calls:?}"
        );
    }
}

#[test]
fn a_method_called_on_a_generic_bounded_by_a_trait_is_the_traits() {
    // `fn run_duplex<D: DuplexDriver>(driver: &mut D)` calls the trait's
    // methods, which every implementation fulfils; a `where` clause bounds
    // the same way. Unbounded, or bounded only by a trait nothing vouches
    // for, the receiver stays untyped.
    let source = r#"
pub trait DuplexDriver {
    fn pending_write(&self) -> &[u8];
    fn written(&mut self, n: usize);
}
pub fn run_duplex<D: Send + DuplexDriver>(driver: &mut D) {
    let _ = driver.pending_write();
}
pub fn finish<D>(driver: &mut D) where D: DuplexDriver + ?Sized {
    driver.written(1);
}
pub fn plain<T: Clone>(value: &T) {
    value.pending_write();
}
"#;
    let contributions = scan_fixture("bounded-receivers", &[("src/lib.rs", source)]);

    assert_eq!(
        vec![(
            "rust:method:crate::DuplexDriver::pending_write".to_owned(),
            true
        )],
        method_calls(&contributions, "rust:function:crate::run_duplex")
    );
    assert_eq!(
        vec![("rust:method:crate::DuplexDriver::written".to_owned(), true)],
        method_calls(&contributions, "rust:function:crate::finish")
    );
    assert!(method_calls(&contributions, "rust:function:crate::plain").is_empty());
}
