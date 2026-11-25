//! Unit tests of the test-facts pass that need its private seams.

use std::collections::BTreeMap;

use super::{collect, collect_with, settle, TestFacts, TestInput};
use crate::facts::Facts;
use crate::layout::Layout;
use crate::visit::{walk, Declarations, ModuleGates};

/// A collector that fails the way a bug in the pass would.
fn panicking(_: &TestInput<'_>) -> TestFacts {
    panic!("seam")
}

/// Walk `source` as `src/lib.rs`, then settle the test-facts pass
/// `collector` runs over it, returning the finished contribution.
fn scanned(
    source: &str,
    collector: fn(&TestInput<'_>) -> TestFacts,
) -> crate::protocol::Contribution {
    let parsed: syn::File = syn::parse_str(source).expect("parses");
    let declarations = Declarations::new();
    let gates = ModuleGates::new();
    let layout = Layout::default();
    let read = BTreeMap::new();
    let mut facts = Facts::new("src/lib.rs");
    let span = proc_macro2::Span::call_site();
    facts.node("module", "crate", "crate", span, span);
    let (_, scope) = walk(
        &mut facts,
        "crate",
        &parsed,
        &[],
        &declarations,
        &gates,
        &layout,
    );
    let outcome = collect_with(
        &TestInput {
            relative: "src/lib.rs",
            module: "crate",
            file: &parsed,
            declarations: &declarations,
            layout: &layout,
            test_modules: &gates,
            scope: &scope,
            read: &read,
            facts: &facts,
        },
        collector,
    );
    settle(outcome, &mut facts);
    facts.finish()
}

/// The file's facts outlive a pass that panics, which leaves a warning in
/// place of its test facts (the P16 analog).
#[test]
fn a_panic_in_test_facts_keeps_the_file_facts() {
    let source = "pub fn add() {}\n#[cfg(test)]\nmod tests {\n    #[test]\n    fn adds() { assert_eq!(super::add(), ()); }\n}\n";

    let failed = scanned(source, panicking);
    let ids: Vec<&str> = failed
        .nodes
        .iter()
        .map(|node| node.local_id.as_str())
        .collect();
    assert_eq!(
        vec![
            "rust:module:crate",
            "rust:function:crate::add",
            "rust:module:crate::tests",
            "rust:function:crate::tests::adds",
        ],
        ids
    );
    assert_eq!(1, failed.diagnostics.len());
    assert_eq!("RS_TEST_FACTS_FAILED", failed.diagnostics[0].code);
    assert_eq!("warning", failed.diagnostics[0].severity);
    assert!(failed.diagnostics[0].message.ends_with(": seam"));

    let passed = scanned(source, collect);
    assert!(passed.diagnostics.is_empty());
    assert!(passed
        .nodes
        .iter()
        .any(|node| node.local_id == "rust:test_case:src/lib.rs#test:5"));
}

/// What the walked file itself brings in unfreezes a standard name even
/// when the scan-wide index knows nothing of it: a `use` binding it, and a
/// crate opened to another crate's macros.
#[test]
fn the_walked_files_own_imports_unfreeze_a_name_without_the_index() {
    let first_operand = |source: &str| {
        let contribution = serde_json::to_value(scanned(source, collect)).unwrap();
        contribution["nodes"]
            .as_array()
            .unwrap()
            .iter()
            .find(|node| node["kind"] == "test_case")
            .unwrap()["attributes"]["assertions"][0]["operands"][0]["source"]
            .clone()
    };
    let case =
        "const LIMIT: u32 = 10;\n#[test]\nfn t() {\n    dbg!();\n    assert_eq!(LIMIT, 20);\n}\n";

    assert_eq!("literal", first_operand(case));
    assert_eq!(
        "unknown",
        first_operand(&format!("use extmac::dbg;\n{case}"))
    );
    assert_eq!(
        "unknown",
        first_operand(&format!("#[macro_use]\nextern crate extmac;\n{case}"))
    );
}

/// A panic whose payload is not a string still names a cause.
#[test]
fn a_panic_with_any_payload_is_reported() {
    fn formatted(_: &TestInput<'_>) -> TestFacts {
        let reason = String::from("formatted");
        panic!("{reason}")
    }
    fn opaque(_: &TestInput<'_>) -> TestFacts {
        std::panic::panic_any(7_u8)
    }

    let message = |collector| {
        scanned("#[test]\nfn t() {}\n", collector).diagnostics[0]
            .message
            .clone()
    };
    assert!(message(formatted).ends_with(": formatted"));
    assert!(message(opaque).ends_with(": a panic"));
}

#[test]
fn a_generated_mock_found_through_the_index_is_a_read() {
    // The walk itself often reads the generating file (through the `use`
    // that names the mock), so the lookup is pinned here, where nothing
    // else asks about the path.
    let mut declarations = Declarations::new();
    let mock = crate::visit::GeneratedMock {
        target: Some("crate::clock::Clock".to_owned()),
        target_kind: "interface",
        pattern: crate::visit::AUTOMOCK,
        test_gated: false,
        contexts_only: false,
    };
    declarations.add_generated_mocks(&BTreeMap::from([(
        "crate::clock::MockClock".to_owned(),
        mock,
    )]));
    let own = BTreeMap::new();
    let scope = crate::visit::FileScope {
        crate_module: "crate".to_owned(),
        ..Default::default()
    };
    let gates = ModuleGates::new();
    let layout = Layout::default();
    let resolver = super::generated::MockResolver {
        own: &own,
        scope: &scope,
        declarations: &declarations,
        test_modules: &gates,
        layout: &layout,
    };
    let path: syn::Path = syn::parse_str("crate::clock::MockClock").expect("parses");
    let other: syn::Path = syn::parse_str("crate::clock::Helper").expect("parses");

    assert!(resolver.resolve("crate::service", &path).is_some());
    assert!(resolver.resolve("crate::service", &other).is_none());
    // Only the mock-named path was asked about (R5-4).
    let lookups = declarations.take_lookups();
    assert!(lookups.contains("crate::clock::MockClock"), "{lookups:?}");
    assert!(!lookups.iter().any(|lookup| lookup.contains("Helper")));
}
