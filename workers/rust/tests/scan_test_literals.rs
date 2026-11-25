//! Scanning tests of the `literal` operands of a test's assertions: the
//! literal set and its normalised text, the locals bound once to a literal,
//! the writes that leave a local unknown, and the file's constants.

mod common;

use common::*;
use serde_json::{json, Value};

/// Scan `src/lib.rs` (and any other `files`) beside a manifest, returning
/// the first operand of every case's first assertion, by case name.
fn first_operands(name: &str, files: &[(&str, &str)]) -> Vec<(String, Value)> {
    let mut all = vec![("Cargo.toml", "[package]\nname = \"probe\"\n")];
    all.extend_from_slice(files);
    let contributions = scan_fixture_with(name, &all, &json!({"config_files": ["Cargo.toml"]}));
    let mut found: Vec<(String, Value)> = contributions
        .iter()
        .flat_map(|contribution| contribution["nodes"].as_array().unwrap().clone())
        .filter(|node| node["kind"] == "test_case")
        .map(|node| {
            (
                node["attributes"]["name"].as_str().unwrap().to_owned(),
                node["attributes"]["assertions"][0]["operands"][0].clone(),
            )
        })
        .collect();
    found.sort_by(|a, b| a.0.cmp(&b.0));
    found
}

/// A `mod tests` holding one case per `(name, body)`.
fn tests_module(cases: &[(&str, &str)]) -> String {
    let mut source = String::from("#[cfg(test)]\nmod tests {\n    use super::*;\n");
    for (name, body) in cases {
        source.push_str(&format!(
            "    #[test]\n    fn {name}() {{\n        {body}\n    }}\n"
        ));
    }
    source.push_str("}\n");
    source
}

/// The literal operand with this text.
fn literal(text: &str) -> Value {
    json!({"source": "literal", "text": text})
}

/// The operand whose source is unknown.
fn unknown() -> Value {
    json!({"source": "unknown"})
}

#[test]
fn literal_text_is_normalised() {
    let forms = [
        ("suffixed", "42u32", Some("42")),
        ("hex", "0x10", Some("16")),
        ("grouped", "1_000", Some("1000")),
        ("float", "1.5f64", Some("1.5")),
        ("negative", "-3", Some("-3")),
        ("negative_float", "-2.5", Some("-2.5")),
        ("string", "\"a\"", Some("\"a\"")),
        ("raw_string", "r\"a\"", Some("r\"a\"")),
        ("character", "'c'", Some("'c'")),
        ("byte", "b'c'", Some("b'c'")),
        ("bytes", "b\"ab\"", Some("b\"ab\"")),
        ("c_string", "c\"ab\"", Some("c\"ab\"")),
        ("boolean", "false", Some("false")),
        ("tuple", "(1, true)", Some("(1, true)")),
        ("single", "(1,)", Some("(1,)")),
        ("array", "[1, 2]", Some("[1, 2]")),
        ("sum", "1 + 1", None),
        ("borrowed", "&5", None),
        ("name", "x", None),
        ("negated_name", "-x", None),
        ("not", "!true", None),
        ("not_number", "!5", None),
        ("dereferenced", "*5", None),
        ("negated_string", "-\"a\"", None),
        ("mixed_tuple", "(1, x)", None),
        ("mixed_array", "[1, x]", None),
        ("parenthesised", "(1)", None),
    ];
    let cases: Vec<(String, String)> = forms
        .iter()
        .map(|(name, form, _)| ((*name).to_owned(), format!("assert_eq!({form}, y);")))
        .collect();
    let borrowed: Vec<(&str, &str)> = cases
        .iter()
        .map(|(name, body)| (name.as_str(), body.as_str()))
        .collect();
    let found = first_operands(
        "literals-normalised",
        &[("src/lib.rs", &tests_module(&borrowed))],
    );

    let mut expected: Vec<(String, Value)> = forms
        .iter()
        .map(|(name, _, text)| (format!("tests::{name}"), text.map_or_else(unknown, literal)))
        .collect();
    expected.sort_by(|a, b| a.0.cmp(&b.0));
    assert_eq!(expected, found);
}

#[test]
fn non_literal_operands_carry_no_text() {
    let lib = "pub fn make() -> u32 { 1 }\n".to_owned()
        + &tests_module(&[
            ("nan", "let v = f64::NAN; assert_eq!(v, v);"),
            ("calls", "assert_eq!(make(), make());"),
            ("fields", "let p = Point::default(); assert_eq!(p.x, p.x);"),
            ("paths", "assert_eq!(f64::NAN, f64::NAN);"),
        ]);
    let contributions = scan_fixture_with(
        "literals-no-text",
        &[
            ("Cargo.toml", "[package]\nname = \"probe\"\n"),
            ("src/lib.rs", &lib),
        ],
        &json!({"config_files": ["Cargo.toml"]}),
    );

    let operands: Vec<Value> = contributions
        .iter()
        .flat_map(|contribution| contribution["nodes"].as_array().unwrap().clone())
        .filter(|node| node["kind"] == "test_case")
        .flat_map(|node| {
            node["attributes"]["assertions"][0]["operands"]
                .as_array()
                .unwrap()
                .clone()
        })
        .collect();
    assert_eq!(8, operands.len());
    assert!(operands.iter().all(|operand| operand.get("text").is_none()));
    assert_eq!(json!({"source": "production"}), operands[2]);
}

#[test]
fn a_single_immutable_binding_is_literal() {
    let lib = tests_module(&[
        ("plain", "let expected = 5; assert_eq!(expected, 5);"),
        ("typed", "let expected: u32 = 5; assert_eq!(expected, 5);"),
    ]);
    let contributions = scan_fixture_with(
        "literals-binding",
        &[
            ("Cargo.toml", "[package]\nname = \"probe\"\n"),
            ("src/lib.rs", &lib),
        ],
        &json!({"config_files": ["Cargo.toml"]}),
    );

    let operands: Vec<Value> = contributions
        .iter()
        .flat_map(|contribution| contribution["nodes"].as_array().unwrap().clone())
        .filter(|node| node["kind"] == "test_case")
        .map(|node| node["attributes"]["assertions"][0]["operands"].clone())
        .collect();
    assert_eq!(vec![json!([literal("5"), literal("5")]); 2], operands);
}

#[test]
fn unseen_writes_make_a_local_unknown() {
    let writes = [
        ("mutable", "let mut x = 5;\n        x += 1;"),
        ("inner_use", "let x = 5;\n        {\n            use crate::limits::LIMIT as x;\n            assert_eq!(x, 5);\n        }"),
        ("inner_const", "let x = 5;\n        {\n            const x: u32 = crate::limits::LIMIT;\n            assert_eq!(x, 5);\n        }"),
        ("inner_static", "let x = 5;\n        {\n            static x: u32 = 5;\n            assert_eq!(x, 5);\n        }"),
        ("inner_struct", "let x = 5;\n        struct x;"),
        ("inner_fn", "let x = 5;\n        fn x() {}"),
        ("grouped_use", "let x = 5;\n        use crate::limits::{LIMIT, LIMIT as x};"),
        ("body_glob", "let x = 5;\n        use crate::limits::*;"),
        ("foreign_fn", "let x = 5;\n        extern \"C\" { fn x(); }"),
        ("foreign_static", "let x = 5;\n        extern \"C\" { static x: u32; }"),
        ("shadowed", "let x = 5;\n        let x = 5;"),
        ("nested_block", "let x = 5;\n        { let x = 6; }"),
        (
            "closure_parameter",
            "let x = 5;\n        let f = |x: u32| x;",
        ),
        ("match_arm", "let x = 5;\n        match 1 { x => {} }"),
        ("if_let", "let x = 5;\n        if let Some(x) = Some(1) {}"),
        (
            "while_let",
            "let x = 5;\n        while let Some(x) = None::<u32> {}",
        ),
        ("for_loop", "let x = 5;\n        for x in 0..1 {}"),
        (
            "function_parameter",
            "let x = 5;\n        fn inner(x: u32) {}",
        ),
        ("unsafe_block", "let x = 5;\n        unsafe { write(&x); }"),
        ("macro_handed", "let x = 5;\n        rebind!(x);"),
        ("macro_nested", "let x = 5;\n        let _ = rebind!({ x });"),
        (
            "unsafe_in_macro",
            "let x = 5;\n        let _ = wrap!(unsafe { touch() });",
        ),
        (
            "macro_in_assertion",
            "let x = 5;\n        assert_eq!(x, rebind!(x));",
        ),
        ("not_top_level", "{ let x = 5; }"),
        ("not_literal", "let x = five();"),
        ("reference_pattern", "let ref x = 5;"),
        ("destructured", "let (x, y) = (5, 6);"),
        ("let_else", "let x = 5 else { return; };"),
    ];
    let mut cases: Vec<(String, String)> = writes
        .iter()
        .map(|(name, body)| {
            let assertion = if body.contains("assert") {
                ""
            } else {
                "\n        assert_eq!(x, 5);"
            };
            ((*name).to_owned(), format!("{body}{assertion}"))
        })
        .collect();
    cases.push((
        "after".to_owned(),
        "assert_eq!(x, 5);\n        let x = 5;".to_owned(),
    ));
    cases.push((
        "same_line".to_owned(),
        "let x = 5; assert_eq!(x, 5);".to_owned(),
    ));
    let borrowed: Vec<(&str, &str)> = cases
        .iter()
        .map(|(name, body)| (name.as_str(), body.as_str()))
        .collect();
    let found = first_operands(
        "literals-writes",
        &[("src/lib.rs", &tests_module(&borrowed))],
    );

    for (name, operand) in &found {
        let expected = if name == "tests::same_line" {
            literal("5")
        } else {
            unknown()
        };
        assert_eq!((name, &expected), (name, operand));
    }
    assert_eq!(cases.len(), found.len());
}

#[test]
fn an_unsafe_function_makes_its_locals_unknown() {
    let lib = "#[cfg(test)]\nmod tests {\n    #[test]\n    unsafe fn risky() {\n        let x = 5;\n        assert_eq!(x, 5);\n    }\n    #[test]\n    fn safe() {\n        let x = 5;\n        assert_eq!(x, 5);\n    }\n}\n";
    let found = first_operands("literals-unsafe-fn", &[("src/lib.rs", lib)]);

    assert_eq!(
        vec![
            ("tests::risky".to_owned(), unknown()),
            ("tests::safe".to_owned(), literal("5")),
        ],
        found
    );
}

#[test]
fn same_file_constants_are_literal() {
    let lib = "pub mod other;\nconst LIMIT: u32 = 10;\nstatic NAME: &str = \"a\";\nstatic mut N: u32 = 0;\nconst X: u32 = f();\nconst fn f() -> u32 { 1 }\nmod inner {\n    pub const DEEP: u32 = 4;\n}\nuse inner::DEEP;\n".to_owned()
        + &tests_module(&[
            ("limit", "assert_eq!(LIMIT, 10);"),
            ("name", "assert_eq!(NAME, \"a\");"),
            ("mutable_static", "assert_eq!(N, 0);"),
            ("computed", "assert_eq!(X, 1);"),
            ("function", "assert_eq!(f, f);"),
            ("other_file", "assert_eq!(FAR, 3);"),
            ("imported", "assert_eq!(DEEP, 4);"),
            ("undeclared", "assert_eq!(NOWHERE, 4);"),
            ("shadowed", "let check = |LIMIT: u32| LIMIT;\n        assert_eq!(LIMIT, 10);"),
            ("own_module", "const LOCAL: u32 = 2;\n        assert_eq!(LOCAL, 2);"),
            ("block_use", "use crate::other::FAR as LIMIT;\n        assert_eq!(LIMIT, 3);"),
            ("block_const", "const LIMIT: u32 = crate::other::FAR;\n        assert_eq!(LIMIT, 3);"),
            ("body_macro", "define_limit!(LIMIT);\n        assert_eq!(LIMIT, 3);"),
            ("body_glob", "use crate::other::*;\n        assert_eq!(LIMIT, 10);"),
        ])
        + "use crate::other::FAR;\n";
    let found = first_operands(
        "literals-constants",
        &[
            ("src/lib.rs", &lib),
            ("src/other.rs", "pub const FAR: u32 = 3;\n"),
        ],
    );

    assert_eq!(
        vec![
            ("tests::block_const".to_owned(), unknown()),
            ("tests::block_use".to_owned(), unknown()),
            ("tests::body_glob".to_owned(), unknown()),
            ("tests::body_macro".to_owned(), unknown()),
            ("tests::computed".to_owned(), unknown()),
            ("tests::function".to_owned(), unknown()),
            ("tests::imported".to_owned(), literal("4")),
            ("tests::limit".to_owned(), literal("10")),
            ("tests::mutable_static".to_owned(), unknown()),
            ("tests::name".to_owned(), literal("\"a\"")),
            ("tests::other_file".to_owned(), unknown()),
            ("tests::own_module".to_owned(), unknown()),
            ("tests::shadowed".to_owned(), unknown()),
            ("tests::undeclared".to_owned(), unknown()),
        ],
        found
    );
}

#[test]
fn a_constant_in_the_case_module_wins_over_a_glob() {
    let lib = "const LIMIT: u32 = 10;\n#[cfg(test)]\nmod tests {\n    use super::*;\n    const LIMIT: u32 = 20;\n    #[test]\n    fn own() {\n        assert_eq!(LIMIT, 20);\n    }\n}\n";
    let found = first_operands("literals-own-module", &[("src/lib.rs", lib)]);

    assert_eq!(vec![("tests::own".to_owned(), literal("20"))], found);
}

#[test]
fn assertion_macros_are_not_handed_the_names_they_compare() {
    let lib = tests_module(&[
        ("boolean", "let x = 5;\n        assert!(x == 5);"),
        ("matcher", "let x = 5;\n        assert_matches!(x, 5);"),
    ]);
    let found = first_operands("literals-assertion-macros", &[("src/lib.rs", &lib)]);

    assert_eq!(
        vec![
            ("tests::boolean".to_owned(), literal("5")),
            ("tests::matcher".to_owned(), literal("5")),
        ],
        found
    );
}

#[test]
fn a_function_of_the_case_module_hides_a_glob_constant() {
    let lib = "const LIMIT: u32 = 10;\n#[cfg(test)]\nmod tests {\n    use super::*;\n    #[allow(non_snake_case)]\n    fn LIMIT() -> u32 { 10 }\n    #[test]\n    fn hidden() {\n        assert_eq!(LIMIT, 10);\n    }\n}\n";
    let found = first_operands("literals-hidden", &[("src/lib.rs", lib)]);

    assert_eq!(vec![("tests::hidden".to_owned(), unknown())], found);
}

/// `depth` inline modules `m0`…, each glob-importing the next, the last
/// declaring `C`, with a case in `m0` comparing `C`.
fn glob_chain(depth: usize) -> String {
    let mut source = String::new();
    for level in 0..depth {
        source.push_str(&format!(
            "mod m{level} {{\n    use super::m{}::*;\n",
            level + 1
        ));
        if level == 0 {
            source.push_str("    #[test]\n    fn chained() {\n        assert_eq!(C, 1);\n    }\n");
        }
        source.push_str("}\n");
    }
    source.push_str(&format!("mod m{depth} {{\n    pub const C: u32 = 1;\n}}\n"));
    source
}

#[test]
fn a_constant_is_followed_through_at_most_eight_imports() {
    let eight = first_operands("literals-chain-eight", &[("src/lib.rs", &glob_chain(8))]);
    let nine = first_operands("literals-chain-nine", &[("src/lib.rs", &glob_chain(9))]);

    assert_eq!(vec![("m0::chained".to_owned(), literal("1"))], eight);
    assert_eq!(vec![("m0::chained".to_owned(), unknown())], nine);
}

#[test]
fn import_cycles_end_unknown() {
    let globs = "mod a {\n    pub use super::b::*;\n    #[test]\n    fn cycled() {\n        assert_eq!(C, 1);\n    }\n}\nmod b {\n    pub use super::a::*;\n}\n";
    let aliases = "mod a {\n    pub use super::b::C;\n}\nmod b {\n    pub use super::a::C;\n}\n#[cfg(test)]\nmod tests {\n    use super::a::C;\n    #[test]\n    fn aliased() {\n        assert_eq!(C, 1);\n    }\n}\n";

    assert_eq!(
        vec![("a::cycled".to_owned(), unknown())],
        first_operands("literals-glob-cycle", &[("src/lib.rs", globs)])
    );
    assert_eq!(
        vec![("tests::aliased".to_owned(), unknown())],
        first_operands("literals-alias-cycle", &[("src/lib.rs", aliases)])
    );
}

#[test]
fn what_is_read_off_production_is_production() {
    let fields = |count: usize| format!("make(){}", ".next".repeat(count));
    let operands = [
        ("field", "make().next".to_owned(), "production"),
        ("tried", "make()?".to_owned(), "production"),
        ("awaited", "make().await".to_owned(), "production"),
        ("borrowed", "&make()".to_owned(), "production"),
        ("method", "make().count()".to_owned(), "production"),
        ("twelve", fields(12), "production"),
        ("thirteen", fields(13), "unknown"),
        ("untyped", "thing.go()".to_owned(), "unknown"),
        ("test_helper", "helper()".to_owned(), "unknown"),
        ("off_unknown", "thing.next".to_owned(), "unknown"),
    ];
    let bodies: Vec<(String, String)> = operands
        .iter()
        .map(|(name, operand, _)| ((*name).to_owned(), format!("assert_eq!({operand}, y);")))
        .collect();
    let borrowed: Vec<(&str, &str)> = bodies
        .iter()
        .map(|(name, body)| (name.as_str(), body.as_str()))
        .collect();
    let mut module = tests_module(&borrowed);
    module.truncate(module.len() - 2);
    module.push_str("    fn helper() -> u32 { 1 }\n}\n");
    let lib =
        "pub struct Counter { pub next: u32 }\npub fn make() -> Counter { Counter { next: 1 } }\n"
            .to_owned()
            + &module;
    let found = first_operands("literals-derived", &[("src/lib.rs", &lib)]);

    let mut expected: Vec<(String, Value)> = operands
        .iter()
        .map(|(name, _, source)| (format!("tests::{name}"), json!({ "source": source })))
        .collect();
    expected.sort_by(|a, b| a.0.cmp(&b.0));
    assert_eq!(expected, found);
}

#[test]
fn a_constant_declared_twice_holds_no_one_literal() {
    let lib = "#[cfg(not(test))]\nconst BATCH: u32 = 1;\n#[cfg(test)]\nconst BATCH: u32 = crate::other::FAR;\n#[cfg(test)]\nconst RETRIES: u32 = 1;\n#[cfg(not(test))]\nconst RETRIES: u32 = 5;\nconst ONCE: u32 = 7;\n".to_owned()
        + &tests_module(&[
            ("batch", "assert_eq!(BATCH, 3);"),
            ("retries", "assert_eq!(RETRIES, 1);"),
            ("once", "assert_eq!(ONCE, 7);"),
        ]);
    let found = first_operands("literals-repeated", &[("src/lib.rs", &lib)]);

    assert_eq!(
        vec![
            ("tests::batch".to_owned(), unknown()),
            ("tests::once".to_owned(), literal("7")),
            ("tests::retries".to_owned(), unknown()),
        ],
        found
    );
}

#[test]
fn an_item_position_macro_hides_every_constant_of_its_scope() {
    // `macro_rules!` items are unhygienic: `define_twenty!()` declares
    // `LIMIT` where it is invoked, handed the name or not.
    let lib = "const LIMIT: u32 = 10;\nconst OTHER: u32 = 4;\nmacro_rules! define_twenty {\n    () => {\n        const LIMIT: u32 = 20;\n    };\n}\n#[cfg(test)]\nmod tests {\n    use super::*;\n    define_limit!(LIMIT);\n    #[test]\n    fn handed() {\n        assert_eq!(LIMIT, 10);\n    }\n    #[test]\n    fn beside() {\n        assert_eq!(OTHER, 4);\n    }\n}\n#[cfg(test)]\nmod module_macro {\n    use super::*;\n    define_twenty!();\n    #[test]\n    fn unhanded() {\n        assert_eq!(LIMIT, 20);\n    }\n}\n#[cfg(test)]\nmod body_macro {\n    use super::*;\n    macro_rules! read_other {\n        () => { OTHER };\n    }\n    #[test]\n    fn unhanded() {\n        define_twenty!();\n        assert_eq!(LIMIT, 20);\n    }\n    #[test]\n    fn local() {\n        let x = 5;\n        define_twenty!();\n        assert_eq!(x, 5);\n    }\n    #[test]\n    fn itemless() {\n        let x = 5;\n        println!(\"{}\", 1);\n        dbg!(2);\n        assert_eq!(x, 5);\n    }\n    #[test]\n    fn untouched() {\n        assert_eq!(OTHER, 4);\n    }\n}\n";
    let found = first_operands("literals-item-macros", &[("src/lib.rs", lib)]);

    assert_eq!(
        vec![
            ("body_macro::itemless".to_owned(), literal("5")),
            ("body_macro::local".to_owned(), unknown()),
            ("body_macro::unhanded".to_owned(), unknown()),
            ("body_macro::untouched".to_owned(), literal("4")),
            ("module_macro::unhanded".to_owned(), unknown()),
            ("tests::beside".to_owned(), unknown()),
            ("tests::handed".to_owned(), unknown()),
        ],
        found
    );
}

#[test]
fn a_cfg_alternative_import_holds_no_literal() {
    let lib = "pub mod limits;\n#[cfg(not(test))]\nconst CAP: u32 = 1;\n#[cfg(test)]\nuse crate::limits::LIMIT as CAP;\nconst PLAIN: u32 = 3;\n#[cfg(test)]\nmod tests {\n    use super::*;\n    #[test]\n    fn cfg_alt_use() {\n        assert_eq!(CAP, 20);\n    }\n    #[test]\n    fn plain() {\n        assert_eq!(PLAIN, 3);\n    }\n}\npub mod alt2 {\n    #[cfg(not(test))]\n    const SIZE: u32 = 1;\n    #[cfg(test)]\n    use crate::limits::LIMIT as SIZE;\n    #[cfg(not(test))]\n    const DEPTH: u32 = 1;\n    #[cfg(test)]\n    use crate::limits::*;\n    #[cfg(test)]\n    use LIMIT as DEPTH;\n    #[cfg(test)]\n    mod tests {\n        use super::*;\n        #[test]\n        fn cfg_alt_use_in_module() {\n            assert_eq!(SIZE, 20);\n        }\n        #[test]\n        fn cfg_alt_glob_rename() {\n            assert_eq!(DEPTH, 20);\n        }\n    }\n}\n";
    let found = first_operands(
        "literals-cfg-use",
        &[
            ("src/lib.rs", lib),
            ("src/limits.rs", "pub const LIMIT: u32 = 20;\n"),
        ],
    );

    assert_eq!(
        vec![
            ("alt2::tests::cfg_alt_glob_rename".to_owned(), unknown()),
            ("alt2::tests::cfg_alt_use_in_module".to_owned(), unknown()),
            ("tests::cfg_alt_use".to_owned(), unknown()),
            ("tests::plain".to_owned(), literal("3")),
        ],
        found
    );
}

#[test]
fn an_import_beside_an_ungated_constant_of_its_name_holds_no_literal() {
    // Rust refuses the pair in a test build; the scan reads it as written.
    let lib = "pub mod limits;\nconst CAP: u32 = 1;\n#[cfg(test)]\nuse crate::limits::LIMIT as CAP;\n#[cfg(test)]\nmod tests {\n    use super::*;\n    #[test]\n    fn capped() {\n        assert_eq!(CAP, 20);\n    }\n}\n";
    let found = first_operands(
        "literals-ungated-import",
        &[
            ("src/lib.rs", lib),
            ("src/limits.rs", "pub const LIMIT: u32 = 20;\n"),
        ],
    );

    assert_eq!(vec![("tests::capped".to_owned(), unknown())], found);
}

#[test]
fn an_ungated_import_beside_no_constant_is_followed() {
    // `PLAIN` is imported, not declared: the import is followed, as before.
    let lib = "mod inner {\n    pub const PLAIN: u32 = 3;\n    #[cfg(test)]\n    pub static GATED: u32 = 4;\n}\nuse inner::PLAIN;\nuse inner::GATED;\n#[cfg(test)]\nmod tests {\n    use super::*;\n    #[test]\n    fn imported() {\n        assert_eq!(PLAIN, 3);\n    }\n    #[test]\n    fn gated_static() {\n        assert_eq!(GATED, 4);\n    }\n}\n";
    let found = first_operands("literals-import", &[("src/lib.rs", lib)]);

    assert_eq!(
        vec![
            ("tests::gated_static".to_owned(), unknown()),
            ("tests::imported".to_owned(), literal("3")),
        ],
        found
    );
}

#[test]
fn two_globs_that_disagree_leave_the_name_unknown() {
    let lib = "const LIMIT: u32 = 10;\nmod inner {\n    pub(crate) const LIMIT: u32 = 99;\n}\n#[cfg(test)]\nmod tests {\n    use super::*;\n    use super::inner::*;\n    #[test]\n    fn ambiguous() {\n        assert_eq!(LIMIT, 99);\n    }\n}\n";
    let found = first_operands("literals-two-globs", &[("src/lib.rs", lib)]);

    assert_eq!(vec![("tests::ambiguous".to_owned(), unknown())], found);
}
