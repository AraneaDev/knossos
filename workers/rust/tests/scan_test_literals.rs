//! Scanning tests of the `literal` operands of a test's assertions: the
//! literal set and its normalised text, the locals bound once to a literal,
//! the writes that leave a local unknown, and what is read off production.
//! The file's constants are in `scan_test_constants.rs`.

mod common;

use common::*;
use serde_json::{json, Value};

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
fn assertion_macros_are_not_handed_the_names_they_compare() {
    let lib = tests_module(&[
        ("boolean", "let x = 5;\n        assert!(x == 5);"),
        ("pair", "let x = 5;\n        debug_assert_eq!(x, 5);"),
    ]);
    let found = first_operands("literals-assertion-macros", &[("src/lib.rs", &lib)]);

    assert_eq!(
        vec![
            ("tests::boolean".to_owned(), literal("5")),
            ("tests::pair".to_owned(), literal("5")),
        ],
        found
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
