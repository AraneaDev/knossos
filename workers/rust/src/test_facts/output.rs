//! The test facts of one file, held apart from the walk's [`Facts`] until
//! the whole pass has run, so a pass that fails costs nothing the walk found.

use std::collections::BTreeMap;

use serde_json::Value;

use super::doubles::DoubleFact;
use crate::facts::{reference, Facts};

/// One `test_case` node and the module that contains it.
pub(super) struct CaseFact {
    /// `<file>#test:<line of the fn token>`.
    pub(super) canonical: String,
    /// The case's name, `tests::adds`.
    pub(super) display: String,
    /// The `fn` token, where the evidence starts.
    pub(super) start: proc_macro2::Span,
    /// The body's closing brace, where it ends.
    pub(super) end: proc_macro2::Span,
    /// The node's attributes, its scope's `test` and `cfg` among them.
    pub(super) attributes: BTreeMap<String, Value>,
    /// The canonical path of the module the case is declared in.
    pub(super) module: String,
}

/// The nodes and edges one file's test-facts pass collected.
#[derive(Default)]
pub(crate) struct TestFacts {
    /// The cases, in source order.
    pub(super) cases: Vec<CaseFact>,
    /// The doubles, in the order their functions were read.
    pub(super) doubles: Vec<DoubleFact>,
}

impl TestFacts {
    /// Add every collected node to `facts`, each with the `contains` edge
    /// from its module.
    pub(crate) fn merge_into(self, facts: &mut Facts) {
        for double in self.doubles {
            double.merge_into(facts);
        }
        for case in self.cases {
            facts.node_with_attributes(
                "test_case",
                &case.canonical,
                &case.display,
                case.start,
                case.end,
                case.attributes,
            );
            facts.edge(
                "contains",
                &reference("module", &case.module),
                &reference("test_case", &case.canonical),
                "certain",
                case.start,
            );
        }
    }
}
