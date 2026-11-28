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
use super::handles::Handles;
use super::input::TestInput;
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
    (signature, block): (&syn::Signature, &syn::Block),
    mocks: &Handles,
) -> Reach {
    let mut body = Body {
        nested: Vec::new(),
        method_calls: Vec::new(),
        on_doubles: BTreeSet::new(),
        path_calls: BTreeSet::new(),
        mocks,
    };
    body.visit_signature(signature);
    body.visit_block(block);
    let mut reach = Reach::default();
    let mut named: BTreeSet<(usize, String)> = BTreeSet::new();
    // How many edges of each (line, name) a call on a double shares the
    // calls not on one have taken.
    let mut taken: BTreeMap<(usize, String), usize> = BTreeMap::new();
    for edge in input.facts.edges_from(function) {
        let line = edge.evidence.start_line;
        if edge.kind != "calls" || body.nested(line) || mocks.configures(line) {
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
        // A call on a double the walk typed as what it stands in for (a
        // faux or mry mock is a value of the real struct) runs the double.
        // An edge names only a line, so where other calls of that name
        // share it (`assert_eq!(mock.get(), real.get())`), as many edges
        // as there are such calls count (G5-10 M3).
        if kind != "method_of_return" && body.on_double(line, &name) {
            let taken = taken.entry((line, name.clone())).or_default();
            if *taken >= body.others(line, &name) {
                continue;
            }
            *taken += 1;
        }
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
struct Body<'m> {
    /// The lines of each item nested in the body, whose calls are its own.
    nested: Vec<(usize, usize)>,
    /// Each method call outside them, by the line of its method name.
    method_calls: Vec<(usize, String)>,
    /// Each method call rooted at a double, by the span (line and column)
    /// of its method name, with that name.
    on_doubles: BTreeSet<(usize, usize, String)>,
    /// Each call of a path, by the span of its last segment, with that
    /// segment.
    path_calls: BTreeSet<(usize, usize, String)>,
    /// The case's doubles: a call rooted at one, and the calls inside a
    /// configuration chain, are no production.
    mocks: &'m Handles,
}

impl Body<'_> {
    /// Whether a call on a double is made by `name` on `line`.
    fn on_double(&self, line: usize, name: &str) -> bool {
        self.on_doubles
            .iter()
            .any(|(at, _, called)| *at == line && called == name)
    }

    /// How many calls not on a double are made by `name` on `line`: method
    /// calls and path calls, each at its own span.
    fn others(&self, line: usize, name: &str) -> usize {
        let methods = self
            .method_calls
            .iter()
            .filter(|(at, called)| *at == line && called == name)
            .count();
        let paths = self
            .path_calls
            .iter()
            .filter(|(at, _, called)| *at == line && called == name)
            .count();
        methods + paths
    }

    /// Whether `line` lies inside an item nested in the body.
    fn nested(&self, line: usize) -> bool {
        self.nested
            .iter()
            .any(|(start, end)| (*start..=*end).contains(&line))
    }
}

impl Visit<'_> for Body<'_> {
    fn visit_item(&mut self, node: &syn::Item) {
        let span = node.span();
        self.nested.push((span.start().line, span.end().line));
    }

    fn visit_expr_method_call(&mut self, node: &syn::ExprMethodCall) {
        if self.mocks.root_double(&node.receiver).is_some() {
            let start = node.method.span().start();
            let line = start.line;
            self.on_doubles
                .insert((line, start.column, ident_name(&node.method)));
            if !self.mocks.configures(line) {
                for argument in &node.args {
                    self.visit_expr(argument);
                }
                self.visit_expr(&node.receiver);
            }
            return;
        }
        self.method_calls
            .push((node.method.span().start().line, ident_name(&node.method)));
        syn::visit::visit_expr_method_call(self, node);
    }

    fn visit_expr_call(&mut self, node: &syn::ExprCall) {
        if let syn::Expr::Path(func) = node.func.as_ref() {
            if let Some(last) = func.path.segments.last() {
                let start = last.ident.span().start();
                self.path_calls
                    .insert((start.line, start.column, ident_name(&last.ident)));
            }
        }
        syn::visit::visit_expr_call(self, node);
    }

    fn visit_macro(&mut self, node: &syn::Macro) {
        // The walk reads a macro's arguments as expressions when they parse
        // as such, and so are its calls read here.
        for argument in arguments(node).unwrap_or_default() {
            self.visit_expr(&argument);
        }
    }
}
