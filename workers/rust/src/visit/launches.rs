//! The files the processes a function starts run: `Command::new("python3")
//! .arg("tools/run.py")`, `.args(["js/worker.js"])` after an interpreter, or
//! `Command::new("./target/release/tool")`. Each is a speculative `launches`
//! edge naming the file by path, which the core resolves to that file's
//! module and drops when the project holds no such file.

use syn::spanned::Spanned;

use super::state::Calls;
use crate::facts::reference;

/// Programs that run the script named after them, by basename: a command
/// starting with one launches that script.
const INTERPRETERS: [&str; 12] = [
    "php", "node", "nodejs", "bun", "deno", "python", "python3", "ruby", "perl", "bash", "sh",
    "zsh",
];

/// A file name a script is run by, so a path without a directory still names
/// a file.
const SCRIPTS: [&str; 9] = [
    ".py", ".js", ".mjs", ".cjs", ".ts", ".php", ".rb", ".pl", ".sh",
];

impl Calls<'_, '_> {
    /// `Command::new("./target/release/tool")`: a program named by a path
    /// runs that file. A bare name is a command looked up on `PATH`.
    pub(super) fn command_program(&mut self, node: &syn::ExprCall) {
        let Some(program) = command_new(node) else {
            return;
        };
        if is_interpreter(&program) || !program.contains('/') {
            return;
        }
        if let Some(path) = argv_path(&program) {
            self.launch(&path, node.span());
        }
    }

    /// `.arg(...)` or `.args([...])` on a command an interpreter runs: the
    /// first argument that reads as a path is the script. Each call in the
    /// chain looks at its own arguments and at those before it, so the script
    /// is named once.
    pub(super) fn command_argument(&mut self, node: &syn::ExprMethodCall) {
        if node.method != "arg" && node.method != "args" {
            return;
        }
        let mut earlier = Vec::new();
        let mut current = node.receiver.as_ref();
        let program = loop {
            match current {
                syn::Expr::MethodCall(call) if call.method == "arg" || call.method == "args" => {
                    earlier.extend(literals(call));
                    current = &call.receiver;
                }
                syn::Expr::Call(call) => break command_new(call),
                _ => break None,
            }
        };
        if !program.as_deref().is_some_and(is_interpreter) {
            return;
        }
        if earlier.iter().any(|literal| argv_path(literal).is_some()) {
            return;
        }
        if let Some(path) = literals(node).iter().find_map(|literal| argv_path(literal)) {
            self.launch(&path, node.span());
        }
    }

    /// The speculative `launches` edge from this function to the file at
    /// `path`.
    fn launch(&mut self, path: &str, span: proc_macro2::Span) {
        let source = self.enclosing.clone();
        self.walk.facts.speculative_edge(
            "launches",
            &source,
            &reference("launched_file", path),
            span,
        );
    }
}

/// The program a `Command::new("...")` call names, when it is a literal.
fn command_new(call: &syn::ExprCall) -> Option<String> {
    let syn::Expr::Path(path) = call.func.as_ref() else {
        return None;
    };
    let segments: Vec<String> = path
        .path
        .segments
        .iter()
        .map(|segment| segment.ident.to_string())
        .collect();
    if !segments.ends_with(&["Command".to_owned(), "new".to_owned()]) {
        return None;
    }
    string_literal(call.args.first()?)
}

/// The string literals an `.arg("x")` or `.args(["x", "y"])` call passes.
fn literals(call: &syn::ExprMethodCall) -> Vec<String> {
    let Some(argument) = call.args.first() else {
        return Vec::new();
    };
    match argument {
        syn::Expr::Array(array) => array.elems.iter().filter_map(string_literal).collect(),
        syn::Expr::Reference(reference) => match reference.expr.as_ref() {
            syn::Expr::Array(array) => array.elems.iter().filter_map(string_literal).collect(),
            _ => Vec::new(),
        },
        other => string_literal(other).into_iter().collect(),
    }
}

/// The value of a string-literal expression.
fn string_literal(expr: &syn::Expr) -> Option<String> {
    let syn::Expr::Lit(lit) = expr else {
        return None;
    };
    let syn::Lit::Str(text) = &lit.lit else {
        return None;
    };
    Some(text.value())
}

/// Whether a program, by its basename, is one of [`INTERPRETERS`].
fn is_interpreter(program: &str) -> bool {
    INTERPRETERS.contains(&program.rsplit('/').next().unwrap_or(program))
}

/// The project-relative path a literal names, when it reads as a path to a
/// file: never an option, and without a directory only with a script's
/// extension.
fn argv_path(literal: &str) -> Option<String> {
    let path = literal
        .strip_prefix("./")
        .unwrap_or(literal)
        .trim_start_matches('/');
    let script = SCRIPTS.iter().any(|extension| path.ends_with(extension));
    if path.starts_with('-')
        || path.ends_with('/')
        || (!path.contains('/') && !script)
        || path
            .chars()
            .any(|c| c.is_whitespace() || c == '=' || c == ':')
    {
        return None;
    }
    Some(path.to_owned())
}
