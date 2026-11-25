//! The test facts of one Rust file: a `test_case` node for each test
//! function, with the production it runs and its assertions (Decisions 6, 7
//! and 8).
//!
//! Read after the walk, in one pass over the file's test items, from the
//! syntax and from the walk's own facts, never beside them: what this pass
//! collects is held apart ([`TestFacts`]) and merged only once it is done,
//! so a fault here costs the file its test facts and nothing else (the P16
//! analog, `RS_TEST_FACTS_FAILED`).

use std::collections::{BTreeMap, BTreeSet};

use serde_json::Value;
use syn::visit::Visit;

use crate::facts::{reference, Facts};
use crate::layout::Layout;
use crate::resolve::ident_name;
use crate::visit::{is_test_module_path, Declarations, FileScope, ModuleGates};

mod assertions;
mod cases;
mod literals;
mod locals;
mod macros;
mod operands;
mod output;
mod production;
#[cfg(test)]
mod tests;

use assertions::{assertion, AssertionShape};
use cases::{test_functions, TestFunction};
use locals::{FileConsts, LocalLiterals};
use macros::Frozen;
pub(crate) use macros::MacroShadows;
use operands::OperandReader;
use output::CaseFact;
pub(crate) use output::TestFacts;

/// The scope attributes a case takes from its function's node.
const SCOPE_ATTRIBUTES: [&str; 3] = ["test", "test_support", "cfg"];

/// Everything the pass reads one file through, all of it read-only.
pub(crate) struct TestInput<'a> {
    /// The file's project-relative path.
    pub(crate) relative: &'a str,
    /// The module the file is placed in.
    pub(crate) module: &'a str,
    /// The parsed file.
    pub(crate) file: &'a syn::File,
    /// The scan-wide declaration index, asked only quietly (see
    /// [`Declarations::declares_quietly`]).
    pub(crate) declarations: &'a Declarations,
    /// Where the project's crates are.
    pub(crate) layout: &'a Layout,
    /// The modules declared `#[cfg(test)] mod name;`.
    pub(crate) test_modules: &'a ModuleGates,
    /// The names the file's walk brought into scope.
    pub(crate) scope: &'a FileScope,
    /// Every file the scan read, with its hash (`None` when it could not be
    /// read): which of a module's possible files exists.
    pub(crate) read: &'a BTreeMap<String, Option<String>>,
    /// The walk's facts for the file.
    pub(crate) facts: &'a Facts,
}

/// Whether a file holds test code: a node the walk marked as a test, or a
/// place in a Cargo `tests/` directory (R5-11).
pub(crate) fn holds_test_code(facts: &Facts, relative: &str, layout: &Layout) -> bool {
    production::under_cargo_tests(relative, layout)
        || facts
            .nodes()
            .iter()
            .any(|node| node.attributes.get("test") == Some(&Value::Bool(true)))
}

/// [`collect`], with a panic inside it turned into an error message.
pub(crate) fn collect_guarded(input: &TestInput<'_>) -> Result<TestFacts, String> {
    collect_with(input, collect)
}

/// Run `collector` over `input`, catching a panic: the seam a test of the
/// failure path hands a collector that panics.
pub(crate) fn collect_with(
    input: &TestInput<'_>,
    collector: fn(&TestInput<'_>) -> TestFacts,
) -> Result<TestFacts, String> {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| collector(input))).map_err(|payload| {
        payload
            .downcast_ref::<&str>()
            .map(|text| (*text).to_owned())
            .or_else(|| payload.downcast_ref::<String>().cloned())
            .unwrap_or_else(|| "a panic".to_owned())
    })
}

/// Add what the pass collected to the file's facts, or, when it failed, a
/// warning in its place.
pub(crate) fn settle(outcome: Result<TestFacts, String>, facts: &mut Facts) {
    match outcome {
        Ok(collected) => collected.merge_into(facts),
        Err(message) => facts.diagnostic(
            "warning",
            "RS_TEST_FACTS_FAILED",
            &format!("The file's test cases could not be read, so none are reported: {message}"),
            1,
        ),
    }
}

/// The test facts of one file.
pub(crate) fn collect(input: &TestInput<'_>) -> TestFacts {
    // A `cfg` on the declaration that loads the file gates all of it.
    let gated = input
        .test_modules
        .gate_of_file(input.relative, input.module)
        .is_some()
        || is_test_module_path(input.module, input.test_modules);
    let frozen = Frozen::of(&input.file.items, input.module, input.declarations);
    let consts = FileConsts::of(&input.file.items, input.module, gated, frozen);
    let cases = test_functions(&input.file.items, input.module)
        .iter()
        .map(|case| case_fact(input, &consts, case))
        .collect();

    TestFacts { cases }
}

/// The `test_case` node of one test function.
fn case_fact(input: &TestInput<'_>, consts: &FileConsts<'_>, case: &TestFunction<'_>) -> CaseFact {
    let function = case.function;
    let path = format!("{}::{}", case.container, ident_name(&function.sig.ident));
    let function_ref = reference("function", &path);
    let reach = production::of_case(input, &function_ref, &function.sig, &function.block);
    let locals = LocalLiterals::of(
        &function.sig,
        &function.block,
        consts,
        &case.container,
        input.scope,
    );
    let reader = OperandReader {
        locals: &locals,
        parameters: parameters(&function.sig),
        production: &reach,
    };
    let mut found = Found::default();
    found.visit_block(&function.block);
    let assertions: Vec<Value> = found
        .assertions
        .iter()
        .map(|shape| wire(shape, &reader))
        .collect();

    let mut attributes: BTreeMap<String, Value> = input
        .facts
        .nodes()
        .iter()
        .find(|node| node.local_id == function_ref)
        .map(|node| {
            node.attributes
                .iter()
                .filter(|(key, _)| SCOPE_ATTRIBUTES.contains(&key.as_str()))
                .map(|(key, value)| (key.clone(), value.clone()))
                .collect()
        })
        .unwrap_or_default();
    attributes.insert("name".to_owned(), Value::String(case.name.clone()));
    attributes.insert("production_calls".to_owned(), Value::from(reach.calls));
    attributes.insert(
        "subjects".to_owned(),
        reach.subjects.iter().cloned().map(Value::String).collect(),
    );
    if case.should_panic {
        attributes.insert("should_panic".to_owned(), Value::Bool(true));
    }
    attributes.insert("assertions".to_owned(), Value::Array(assertions));
    let start = function.sig.fn_token.span;

    CaseFact {
        canonical: format!("{}#test:{}", input.relative, start.start().line),
        display: case.name.clone(),
        start,
        end: function.block.brace_token.span.close(),
        attributes,
        module: case.container.clone(),
    }
}

/// The names a case's signature binds: its parameters.
fn parameters(signature: &syn::Signature) -> BTreeSet<String> {
    signature
        .inputs
        .iter()
        .filter_map(|input| match input {
            syn::FnArg::Typed(typed) => match typed.pat.as_ref() {
                syn::Pat::Ident(ident) => Some(ident_name(&ident.ident)),
                _ => None,
            },
            syn::FnArg::Receiver(_) => None,
        })
        .collect()
}

/// The wire form of one assertion.
fn wire(shape: &AssertionShape<'_>, reader: &OperandReader<'_>) -> Value {
    let line = assertion_line(shape.mac);
    let mut assertion = serde_json::Map::new();
    assertion.insert("api".to_owned(), Value::String(shape.api.clone()));
    assertion.insert("line".to_owned(), Value::from(line));
    if shape.negated {
        assertion.insert("negated".to_owned(), Value::Bool(true));
    }
    assertion.insert(
        "operands".to_owned(),
        shape
            .operands
            .iter()
            .map(|operand| reader.wire(operand))
            .collect(),
    );
    Value::Object(assertion)
}

/// The line an assertion is made on: its macro path's.
fn assertion_line(mac: &syn::Macro) -> usize {
    use syn::spanned::Spanned;
    mac.path.span().start().line
}

/// The assertions of one body, in source order, closures and nested
/// functions included.
#[derive(Default)]
struct Found<'m> {
    /// Each assertion found.
    assertions: Vec<AssertionShape<'m>>,
}

impl<'m> Visit<'m> for Found<'m> {
    fn visit_macro(&mut self, node: &'m syn::Macro) {
        if let Some(shape) = assertion(node) {
            self.assertions.push(shape);
        }
    }
}
