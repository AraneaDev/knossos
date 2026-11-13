//! Scanning tests driving the worker against real files on disk:
//! references to types and functions outside calls, macros included.

mod common;

use serde_json::Value;

use common::*;

#[test]
fn a_call_under_a_path_keyword_references_no_type() {
    // `crate::helper()` sits under the crate root, a module: no `crate` type
    // exists to reference, and neither does a `self` or `super` one.
    let source = r#"
pub fn helper() {}

pub mod inner {
    pub fn go() {
        crate::helper();
        self::local();
        super::helper();
    }

    pub fn local() {}
}
"#;
    let contributions = scan_fixture("path-keyword-owner", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();
    let keyword_types: Vec<&str> = edges
        .iter()
        .filter(|edge| edge["kind"] == "references")
        .map(|edge| edge["target"].as_str().unwrap())
        .filter(|target| {
            ["crate", "self", "super"].iter().any(|keyword| {
                target.ends_with(&format!(":{keyword}"))
                    || target.ends_with(&format!("::{keyword}"))
            })
        })
        .collect();

    assert_eq!(Vec::<&str>::new(), keyword_types);
}

#[test]
fn a_shorthand_receiver_references_its_own_type_at_the_self_token() {
    let source = r#"
pub struct Policy;
impl Policy {
    pub fn evaluate(
        &self,
    ) {}
}
"#;
    let contributions = scan_fixture("shorthand-receiver", &[("src/lib.rs", source)]);
    let edges = contributions[0]["edges"].as_array().unwrap();

    assert!(
        edges.iter().any(|edge| edge["kind"] == "references"
            && edge["source"] == "rust:method:crate::Policy::evaluate"
            && edge["target"] == "rust:class:crate::Policy"
            && edge["evidence"]["start_line"] == 5),
        "{edges:?}"
    );
}

#[test]
fn an_expr_struct_instantiation_emits_a_calls_edge() {
    let facts = scan_fixture(
        "exprstruct",
        &[(
            "src/lib.rs",
            "
        struct Widget {}
        fn factory() {
            Widget {};
        }
    ",
        )],
    );
    let edges = facts[0]["edges"].as_array().unwrap();
    let edge = edges.iter().find(|e| {
        e["kind"] == "calls"
            && e["source"] == "rust:function:crate::factory"
            && e["target"] == "rust:class:crate::Widget"
    });
    assert!(
        edge.is_some(),
        "missing calls edge to Widget. Edges: {:?}",
        edges
    );
}

#[test]
fn calls_inside_macro_arguments_are_edges() {
    // `format!`, `assert_eq!` and `println!` bodies are token streams to syn,
    // so a helper only ever called inside one read as uncalled.
    let source = r#"
fn helper() -> u8 { 1 }
fn other() -> u8 { 2 }
pub fn go() {
    let s = format!("{}-{}", helper(), 3);
    assert_eq!(other(), 2);
    println!("{s}");
}
"#;
    let contributions = scan_fixture("macro-calls", &[("src/lib.rs", source)]);
    let calls = edges_of(&contributions, "calls");

    assert!(
        calls.contains(&(
            "rust:function:crate::go".to_owned(),
            "rust:function:crate::helper".to_owned()
        )),
        "{calls:?}"
    );
    assert!(
        calls.contains(&(
            "rust:function:crate::go".to_owned(),
            "rust:function:crate::other".to_owned()
        )),
        "{calls:?}"
    );
}

#[test]
fn types_named_in_fields_signatures_and_patterns_are_references() {
    // A struct or enum used only as a type, a field, or a match arm has no
    // call to be reached by, so without these edges it read as unreferenced.
    let source = r#"
pub enum HookState { Live, Absent }
pub struct Rule;
pub struct Policy { rules: Vec<Rule> }
pub trait Gate {}
pub fn check(s: HookState) -> Option<Rule> {
    match s {
        HookState::Live => None,
        HookState::Absent => None,
    }
}
pub fn boxed(_g: &dyn Gate) {}
"#;
    let contributions = scan_fixture("type-references", &[("src/lib.rs", source)]);
    let references = edges_of(&contributions, "references");

    for (source, target) in [
        ("rust:class:crate::Policy", "rust:class:crate::Rule"),
        ("rust:function:crate::check", "rust:class:crate::HookState"),
        ("rust:function:crate::check", "rust:class:crate::Rule"),
        ("rust:function:crate::boxed", "rust:interface:crate::Gate"),
    ] {
        assert!(
            references.contains(&(source.to_owned(), target.to_owned())),
            "{source} -> {target} missing from {references:?}"
        );
    }
    // Every such edge is speculative: `Vec` and `Option` name nothing here,
    // and the core drops what does not resolve.
    let all_speculative = contributions
        .iter()
        .flat_map(|contribution| contribution["edges"].as_array().unwrap().clone())
        .filter(|edge| edge["kind"] == "references")
        .all(|edge| edge["attributes"]["speculative"] == Value::Bool(true));
    assert!(all_speculative);
}

#[test]
fn functions_named_by_serde_attributes_are_references() {
    let source = r#"
#[derive(serde::Deserialize)]
pub struct Config {
    #[serde(default = "default_action", skip_serializing_if = "is_false")]
    pub flag: bool,
}
fn default_action() -> bool { true }
fn is_false(value: &bool) -> bool { !*value }
"#;
    let contributions = scan_fixture("serde-references", &[("src/lib.rs", source)]);
    let references = edges_of(&contributions, "references");

    for target in [
        "rust:function:crate::default_action",
        "rust:function:crate::is_false",
    ] {
        assert!(
            references.contains(&("rust:class:crate::Config".to_owned(), target.to_owned())),
            "{target} missing from {references:?}"
        );
    }
}

#[test]
fn calls_in_a_const_initializer_are_edges_from_the_module() {
    let source = "const fn seed() -> u8 { 1 }\npub const ALL: [u8; 1] = [seed()];\n";
    let contributions = scan_fixture("const-calls", &[("src/lib.rs", source)]);

    assert!(edges_of(&contributions, "calls").contains(&(
        "rust:module:crate".to_owned(),
        "rust:function:crate::seed".to_owned()
    )));
}

#[test]
fn a_function_passed_by_name_is_a_reference_when_this_file_declares_it() {
    // `.map(helper)` and `.or_else(fallback)` hand a function over without
    // calling it. A bare name is usually a local binding, so it counts only
    // when this file declares a function by that name.
    let source = r#"
fn helper(x: u8) -> u8 { x }
pub fn go(values: Vec<u8>) -> usize {
    let local = 1;
    values.into_iter().map(helper).filter(|v| *v > local).count()
}
"#;
    let contributions = scan_fixture("function-by-name", &[("src/lib.rs", source)]);
    let references = edges_of(&contributions, "references");

    assert!(
        references.contains(&(
            "rust:function:crate::go".to_owned(),
            "rust:function:crate::helper".to_owned()
        )),
        "{references:?}"
    );
    assert!(
        !references
            .iter()
            .any(|(_, target)| target.ends_with("::local")),
        "{references:?}"
    );
}

#[test]
fn a_bare_prelude_name_nothing_here_declares_is_not_referenced_as_a_local_item() {
    // `Option`, `str`, `Some` written bare were guessed as items of the
    // module writing them: two speculative edges per mention to names that
    // never resolve, enough to push a large file's answer past its size
    // limit. A type this file declares under such a name still counts.
    let files = [
        ("src/lib.rs", "pub mod plain;\npub mod shadow;\n"),
        (
            "src/plain.rs",
            "pub struct Thing;\npub fn f(x: Option<&str>) -> Result<bool, String> {\n    let _t: Thing = Thing;\n    Ok(Some(x).is_some())\n}\n",
        ),
        (
            "src/shadow.rs",
            "pub struct Option;\npub fn g(_o: Option) {}\n",
        ),
    ];
    let contributions = scan_fixture("prelude-names", &files);
    let references = edges_of(&contributions, "references");

    for name in ["Option", "str", "Result", "String", "Some", "Ok", "bool"] {
        assert!(
            !references
                .iter()
                .any(|(source, target)| source.contains("plain::f")
                    && target.ends_with(&format!("plain::{name}"))),
            "{name}: {references:?}"
        );
    }
    assert!(
        references.contains(&(
            "rust:function:crate::plain::f".to_owned(),
            "rust:class:crate::plain::Thing".to_owned()
        )),
        "{references:?}"
    );
    assert!(
        references.contains(&(
            "rust:function:crate::shadow::g".to_owned(),
            "rust:class:crate::shadow::Option".to_owned()
        )),
        "{references:?}"
    );
}

#[test]
fn a_function_exported_to_a_foreign_caller_is_runtime_invoked() {
    // `#[no_mangle] pub extern "C" fn` and `#[wasm_bindgen]` functions are
    // called by the host embedding the library, never from Rust.
    let source = r#"
#[no_mangle]
pub extern "C" fn alloc(len: usize) -> usize { len }
#[wasm_bindgen]
pub fn greet() {}
#[export_name = "run"]
pub fn run_exported() {}
pub fn ordinary() {}
"#;
    let contributions = scan_fixture("ffi-exports", &[("src/lib.rs", source)]);
    let invoked: Vec<String> = contributions
        .iter()
        .flat_map(|contribution| contribution["nodes"].as_array().unwrap().clone())
        .filter(|node| node["attributes"]["runtime_invoked"] == Value::Bool(true))
        .map(|node| node["canonical_name"].as_str().unwrap().to_owned())
        .collect();

    assert_eq!(
        invoked,
        vec!["crate::alloc", "crate::greet", "crate::run_exported"]
    );
}

#[test]
fn calls_inside_a_json_like_macro_are_edges() {
    // `json!({ "attrs": a.iter().map(attr).collect() })` is no list of
    // expressions, so the whole body was dropped along with every call in it.
    let source = r#"
fn attr(x: &u8) -> u8 { *x }
fn span() -> u8 { 1 }
fn nested() -> u8 { 2 }
pub fn go(values: Vec<u8>) {
    let _ = serde_json::json!({
        "attrs": values.iter().map(attr).collect::<Vec<_>>(),
        "span": span(),
        "inner": { "deep": [nested()] },
    });
}
"#;
    let contributions = scan_fixture("json-macro", &[("src/lib.rs", source)]);
    let calls = edges_of(&contributions, "calls");
    let references = edges_of(&contributions, "references");

    assert!(
        calls.contains(&(
            "rust:function:crate::go".to_owned(),
            "rust:function:crate::span".to_owned()
        )),
        "{calls:?}"
    );
    assert!(
        calls.contains(&(
            "rust:function:crate::go".to_owned(),
            "rust:function:crate::nested".to_owned()
        )),
        "{calls:?}"
    );
    assert!(
        references.contains(&(
            "rust:function:crate::go".to_owned(),
            "rust:function:crate::attr".to_owned()
        )),
        "{references:?}"
    );
}

#[test]
fn a_standard_type_named_bare_is_never_a_type_of_the_module() {
    // `Vec<u8>`, `char` and `Option<Store>` name no type this crate declares:
    // a call on one must not target `crate::Vec::len` and the like.
    let source = r#"
pub struct Store;
pub struct Holder { items: Option<Store>, list: Vec<Store> }
impl Holder {
    pub fn run(&mut self, v: Vec<u8>, c: char) {
        v.len();
        c.is_alphabetic();
        let w = Vec::new();
        w.len();
        let k = |c: char| c.is_ascii_digit();
        self.items.take();
        self.list.len();
        String::new().len();
    }
}
"#;
    let contributions = scan_fixture("bare-standard-types", &[("src/lib.rs", source)]);
    let targets = all_call_targets(&contributions);

    assert!(
        !targets
            .iter()
            .any(|target| ["::Vec::", "::Option::", "::char::", "::String::"]
                .iter()
                .any(|made_up| target.contains(made_up))),
        "{targets:?}"
    );
}

#[test]
fn a_unit_struct_value_named_bare_references_its_type() {
    let source = "pub struct Unit;\npub struct Wrap(u8);\nconst MAX: u8 = 1;\npub fn go() -> Vec<Wrap> { let _ = Unit; vec![MAX].into_iter().map(Wrap).collect() }\n";
    let contributions = scan_fixture("unit-struct-value", &[("src/lib.rs", source)]);
    let references = targets_of(&contributions, "references", "rust:function:crate::go");
    for target in ["rust:class:crate::Unit", "rust:class:crate::Wrap"] {
        assert!(
            references.contains(&target.to_owned()),
            "{target} in {references:?}"
        );
    }
    assert!(
        !references.iter().any(|target| target.ends_with("::MAX")),
        "{references:?}"
    );
}

#[test]
fn calls_a_macro_body_or_a_macro_guard_makes_are_edges() {
    // `valid_text(..)` and `$crate::types::parse_hex(..)` only appear in a
    // `macro_rules!` body, and `successful(..)` in a `matches!` guard, so
    // all three read as unreferenced.
    let source = r#"
fn valid_text(_name: &str, value: &str) -> bool { !value.is_empty() }
macro_rules! text_type {
    ($name:ident) => {
        pub struct $name;
        impl $name {
            pub fn check(value: &str) -> bool { valid_text(stringify!($name), value) }
            pub fn hex(value: &str) -> u8 { $crate::types::parse_hex(value) }
        }
    };
}
text_type!(Title);
fn successful(x: u8) -> bool { x > 0 }
fn tripled(x: u8) -> u8 { x * 3 }
pub fn any_ok(values: &[u8]) -> bool {
    values.iter().any(|r| matches!(r, 1 | 2 if successful(*r)))
}
pub fn many() -> Vec<u8> { vec![tripled(1); 4] }
"#;
    let contributions = scan_fixture(
        "macro-body-calls",
        &[
            ("src/lib.rs", "pub mod types;\npub mod text;\n"),
            ("src/text.rs", source),
            ("src/types.rs", "pub fn parse_hex(_: &str) -> u8 { 0 }\n"),
        ],
    );
    let edges: Vec<(String, String, String)> = contributions
        .iter()
        .flat_map(|contribution| contribution["edges"].as_array().unwrap().clone())
        .filter(|edge| edge["kind"] == "calls")
        .map(|edge| {
            (
                edge["source"].as_str().unwrap().to_owned(),
                edge["target"].as_str().unwrap().to_owned(),
                edge["confidence"].as_str().unwrap().to_owned(),
            )
        })
        .collect();
    for (source, target, confidence) in [
        (
            "rust:module:crate::text",
            "rust:function:crate::text::valid_text",
            "possible",
        ),
        (
            "rust:module:crate::text",
            "rust:function:crate::types::parse_hex",
            "possible",
        ),
        (
            "rust:function:crate::text::any_ok",
            "rust:function:crate::text::successful",
            "probable",
        ),
        (
            "rust:function:crate::text::many",
            "rust:function:crate::text::tripled",
            "possible",
        ),
    ] {
        assert!(
            edges.contains(&(source.to_owned(), target.to_owned(), confidence.to_owned())),
            "{source} -> {target} ({confidence}) in {edges:?}"
        );
    }
    // The declaration, the metavariable and `stringify!` are no calls.
    assert!(!edges
        .iter()
        .any(|(_, target, _)| target.ends_with("::check")
            || target.ends_with("::stringify")
            || target.ends_with("::name")));
}

#[test]
fn a_type_named_only_as_a_generic_argument_is_referenced() {
    // `size_of::<PipeFdInfo>()`, `query::<VnodeFdInfo>(..)`,
    // `Option::<StrictLogReference>::deserialize(..)` and `x.parse::<Id>()`
    // name their types nowhere else.
    let source = r#"
pub struct PipeFdInfo;
pub struct VnodeFdInfo;
pub struct StrictLogReference;
pub struct Id;
fn query<T>(_: u8) -> Option<T> { None }
pub fn sizes() -> usize { std::mem::size_of::<PipeFdInfo>() }
pub fn read() { let _ = query::<VnodeFdInfo>(1); }
pub fn de() { let _ = Option::<StrictLogReference>::default(); }
pub fn parse(s: &str) { let _ = s.parse::<Id>(); }
"#;
    let contributions = scan_fixture("generic-arguments", &[("src/lib.rs", source)]);
    for (from, to) in [
        ("rust:function:crate::sizes", "rust:class:crate::PipeFdInfo"),
        ("rust:function:crate::read", "rust:class:crate::VnodeFdInfo"),
        (
            "rust:function:crate::de",
            "rust:class:crate::StrictLogReference",
        ),
        ("rust:function:crate::parse", "rust:class:crate::Id"),
    ] {
        let references = targets_of(&contributions, "references", from);
        assert!(
            references.contains(&to.to_owned()),
            "{from} -> {to} in {references:?}"
        );
    }
}
