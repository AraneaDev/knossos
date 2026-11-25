//! How much production code one test runs, and where it lands
//! (Decision 7's `production_calls` and `subjects`).
//!
//! In the case's body, closures included and nested items not, a call
//! counts when either:
//!
//! - the walk emitted a `calls` edge for it from the case's function to a
//!   project declaration outside test code (a `method_of_return` edge counts
//!   by the call whose result it is made on);
//! - it is a method call on a receiver the walk could not type
//!   (`thing.go()`, `"1".parse()`), which no edge names.
//!
//! A path call to a non-project path (`String::from`, `Vec::new`) and a
//! macro (`vec![1]`) count for nothing: the walk names no project
//! declaration for them. A generated mock's constructor (`MockClock::new()`)
//! names no declaration either, so it never counts (R5-8). `subjects` are
//! the files the counted edges land in. The count leans towards production,
//! so the warnings it feeds stay silent where in doubt.

use std::collections::{BTreeMap, BTreeSet};

use syn::spanned::Spanned;
use syn::visit::Visit;

use super::assertions::arguments;
use super::TestInput;
use crate::layout::Layout;
use crate::resolve::ident_name;
use crate::visit::is_test_module_path;

/// What one case reaches in production.
#[derive(Debug, Default)]
pub(super) struct Reach {
    /// How many calls into production the case makes.
    pub(super) calls: usize,
    /// The project files those calls land in, sorted.
    pub(super) subjects: BTreeSet<String>,
    /// The name each counted edge's call is made by (its target's last
    /// segment), by the line the walk records it at: what tells an
    /// operand that is such a call.
    pub(super) sites: BTreeMap<usize, BTreeSet<String>>,
}

impl Reach {
    /// Whether the call of `name` on `line` is one that runs production.
    pub(super) fn is_production(&self, line: usize, name: &str) -> bool {
        self.sites
            .get(&line)
            .is_some_and(|names| names.contains(name))
    }
}

/// The production one case, the function `function` (a `rust:function:`
/// reference) with this signature and body, reaches.
pub(super) fn of_case(
    input: &TestInput<'_>,
    function: &str,
    signature: &syn::Signature,
    block: &syn::Block,
) -> Reach {
    let mut body = Body::default();
    body.visit_signature(signature);
    body.visit_block(block);
    let mut reach = Reach::default();
    let mut named: BTreeSet<(usize, String)> = BTreeSet::new();
    for edge in input.facts.edges_from(function) {
        let line = edge.evidence.start_line;
        if edge.kind != "calls" || body.nested(line) {
            continue;
        }
        let Some((kind, canonical)) = edge
            .target
            .strip_prefix("rust:")
            .and_then(|rest| rest.split_once(':'))
        else {
            continue;
        };
        let name = canonical
            .rsplit("::")
            .next()
            .unwrap_or(canonical)
            .to_owned();
        named.insert((line, name.clone()));
        let landed = if kind == "method_of_return" {
            canonical.rsplit_once("::").map(|(callee, _)| callee)
        } else {
            Some(canonical)
        };
        let Some(Some(file)) = landed.map(|target| production_file(input, target)) else {
            continue;
        };
        reach.calls += 1;
        reach.subjects.extend(file);
        reach.sites.entry(line).or_default().insert(name);
    }
    reach.calls += body
        .method_calls
        .iter()
        .filter(|site| !named.contains(site))
        .count();

    reach
}

/// Where `canonical` is declared when it is a project declaration outside
/// test code: `Some(Some(file))`, or `Some(None)` when its file is not
/// known; `None` when it is not production.
fn production_file(input: &TestInput<'_>, canonical: &str) -> Option<Option<String>> {
    if is_test_module_path(canonical, input.test_modules) {
        return None;
    }
    let own = input
        .facts
        .nodes()
        .iter()
        .find(|node| node.canonical_name == canonical);
    let file = match own {
        Some(node) if node.attributes.get("test") == Some(&serde_json::Value::Bool(true)) => {
            return None
        }
        Some(_) => Some(input.relative.to_owned()),
        None if input.declarations.declares_quietly(canonical) => declaring_file(input, canonical),
        None => return None,
    };
    if file
        .as_deref()
        .is_some_and(|file| under_cargo_tests(file, input.layout))
    {
        return None;
    }

    Some(file)
}

/// The file that declares `canonical`: the first one the scan read among
/// the files of the modules it can sit in, deepest first.
fn declaring_file(input: &TestInput<'_>, canonical: &str) -> Option<String> {
    let mut module = canonical;
    while let Some((parent, _)) = module.rsplit_once("::") {
        module = parent;
        if let Some(file) = input
            .layout
            .module_files(module)
            .into_iter()
            .find(|file| matches!(input.read.get(file), Some(Some(_))))
        {
            return Some(file);
        }
    }

    None
}

/// Whether a project file sits in a Cargo `tests/` directory, the project
/// root's or a workspace member's: an integration test, or a helper of one.
pub(crate) fn under_cargo_tests(relative: &str, layout: &Layout) -> bool {
    relative.starts_with("tests/")
        || layout
            .packages
            .iter()
            .any(|(directory, _)| relative.starts_with(&format!("{directory}tests/")))
}

/// What one body holds that [`of_case`] reads off its syntax.
#[derive(Default)]
struct Body {
    /// The lines of each item nested in the body, whose calls are its own.
    nested: Vec<(usize, usize)>,
    /// Each method call outside them, by the line of its method name.
    method_calls: Vec<(usize, String)>,
}

impl Body {
    /// Whether `line` lies inside an item nested in the body.
    fn nested(&self, line: usize) -> bool {
        self.nested
            .iter()
            .any(|(start, end)| (*start..=*end).contains(&line))
    }
}

impl Visit<'_> for Body {
    fn visit_item(&mut self, node: &syn::Item) {
        let span = node.span();
        self.nested.push((span.start().line, span.end().line));
    }

    fn visit_expr_method_call(&mut self, node: &syn::ExprMethodCall) {
        self.method_calls
            .push((node.method.span().start().line, ident_name(&node.method)));
        syn::visit::visit_expr_method_call(self, node);
    }

    fn visit_macro(&mut self, node: &syn::Macro) {
        // The walk reads a macro's arguments as expressions when they parse
        // as such, and so are its calls read here.
        for argument in arguments(node).unwrap_or_default() {
            self.visit_expr(&argument);
        }
    }
}
