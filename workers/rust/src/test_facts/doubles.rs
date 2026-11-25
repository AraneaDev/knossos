//! One `test_double` node in the wire shape (Decisions 2 and 3), with its
//! `contains` edge from its module and its `doubles` edge to what it stands
//! in for.
//!
//! Every Rust double is checked by the compiler against what it stands in
//! for, so each carries `compile_checked: true` and no drift rule judges it
//! (Decision 1). Its target is `{kind: "class"}` and never states a
//! `specifier`.

use std::collections::BTreeMap;

use serde_json::{json, Value};

use crate::facts::{reference, Facts};

/// One value a member is pinned to.
#[derive(Debug)]
pub(super) struct Pin {
    /// The verb: `return_const`, `returning`, `return_once`…
    pub(super) api: String,
    /// The verb's line.
    pub(super) line: usize,
    /// Whether it returns once only (`return_once`).
    pub(super) once: bool,
}

/// One member the test configures.
#[derive(Debug)]
pub(super) struct Member {
    /// The member's name.
    pub(super) name: String,
    /// The line it is first configured on.
    pub(super) line: usize,
    /// How it is configured: `expect` or `context`.
    pub(super) api: &'static str,
    /// The values it is pinned to.
    pub(super) returns: Vec<Pin>,
}

/// One double and what its function does with it.
#[derive(Debug)]
pub(super) struct DoubleFact {
    /// `<file>#double:<line>:<col>`.
    pub(super) canonical: String,
    /// The mock type's canonical path.
    pub(super) mock: String,
    /// The construct, where the evidence starts and ends.
    pub(super) span: proc_macro2::Span,
    /// The generator's pattern.
    pub(super) pattern: &'static str,
    /// What the mock stands in for: its node kind and canonical path.
    pub(super) target: Option<(&'static str, String)>,
    /// The members the function configures, in order.
    pub(super) members: Vec<Member>,
    /// The calls on the double and the hand-ons of it.
    pub(super) invocations: usize,
    /// The enclosing case's canonical name, none in a helper.
    pub(super) test_case: Option<String>,
    /// The module the function is declared in.
    pub(super) module: String,
}

impl DoubleFact {
    /// The member `name`, added with `api` at `line` when it is new.
    pub(super) fn member(&mut self, name: &str, api: &'static str, line: usize) -> &mut Member {
        let position = match self.members.iter().position(|member| member.name == name) {
            Some(position) => position,
            None => {
                self.members.push(Member {
                    name: name.to_owned(),
                    line,
                    api,
                    returns: Vec::new(),
                });
                self.members.len() - 1
            }
        };
        &mut self.members[position]
    }

    /// The node's attributes.
    fn attributes(&self) -> BTreeMap<String, Value> {
        let members: Vec<Value> = self
            .members
            .iter()
            .map(|member| {
                let returns: Vec<Value> = member
                    .returns
                    .iter()
                    .map(|pin| {
                        json!({
                            "api": pin.api,
                            "line": pin.line,
                            "once": pin.once,
                            "assignable": "unknown",
                            "typed": false,
                        })
                    })
                    .collect();
                json!({"name": member.name, "line": member.line, "api": member.api, "returns": returns})
            })
            .collect();
        let mut attributes = BTreeMap::from([
            ("framework".to_owned(), json!("mockall")),
            ("pattern".to_owned(), json!(self.pattern)),
            ("target".to_owned(), json!({"kind": "class"})),
            ("automock".to_owned(), json!(false)),
            ("compile_checked".to_owned(), json!(true)),
            ("invocations".to_owned(), json!(self.invocations)),
            ("members".to_owned(), Value::Array(members)),
        ]);
        if let Some(case) = &self.test_case {
            attributes.insert("test_case".to_owned(), json!(case));
        }
        attributes
    }

    /// Add the node and its edges to `facts`.
    pub(super) fn merge_into(self, facts: &mut Facts) {
        let subject = self
            .target
            .as_ref()
            .and_then(|(_, path)| path.rsplit("::").next())
            .or_else(|| self.mock.rsplit("::").next())
            .unwrap_or_default()
            .to_owned();
        let local_id = reference("test_double", &self.canonical);
        facts.node_with_attributes(
            "test_double",
            &self.canonical,
            &format!("{} {subject}", self.pattern),
            self.span,
            self.span,
            self.attributes(),
        );
        facts.edge(
            "contains",
            &reference("module", &self.module),
            &local_id,
            "certain",
            self.span,
        );
        if let Some((kind, path)) = &self.target {
            facts.edge(
                "doubles",
                &local_id,
                &reference(kind, path),
                "certain",
                self.span,
            );
        }
    }
}
