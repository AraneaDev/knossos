"""The test facts of a Python test file: its ``test_double`` nodes, each tied to its module and its target,
and its ``test_case`` nodes, each with its production calls and its assertions.

Run after :class:`PythonAstFactCollector` on a file the core takes for a test
(:func:`is_test_path`), inside the index's read scope for that file, so the
modules a target or a call resolves through are named among the file's reads.
The tree is walked once for all of it (:class:`TestFileIndex`).
"""

from __future__ import annotations

import ast
from typing import Any

from ..ast_helpers import evidence, ref
from ..module_index import ProjectModuleIndex
from .cases import TestCases
from .doubles import TestDoubles
from .file_index import TestFileIndex
from .imports import NameResolver
from .paths import is_test_path

__all__ = ["add_test_facts", "case_facts", "is_test_path", "read_doubles", "test_facts"]


def read_doubles(relative: str, tree: ast.Module, index: ProjectModuleIndex, module: str | None = None) -> TestDoubles:
    """Walk one test file and read its doubles, which its test cases are then read against.

    ``module`` is the id the file's own module node takes, which may differ
    from where it sits when another file owns that location.
    """
    module = module or index.layout.module_for(relative)
    files = TestFileIndex(tree)
    return TestDoubles(files, NameResolver(index, files, relative, module))


def test_facts(
    relative: str,
    tree: ast.Module,
    index: ProjectModuleIndex,
    module: str | None = None,
    doubles: TestDoubles | None = None,
) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    """The ``test_double`` nodes of one test file, and their ``contains`` and ``doubles`` edges."""
    doubles = doubles or read_doubles(relative, tree, index, module)
    module_id = ref("module", doubles.names.module)
    nodes: list[dict[str, Any]] = []
    edges: list[dict[str, Any]] = []
    for record in doubles.doubles():
        canonical = record.canonical(relative)
        local_id = ref("test_double", canonical)
        proof = evidence(relative, record.node)
        nodes.append(
            {
                "local_id": local_id,
                "kind": "test_double",
                "canonical_name": canonical,
                "display_name": record.display(),
                "origin": "ast",
                "confidence": "certain",
                "evidence": proof,
                "attributes": record.attributes(),
            }
        )
        edges.append(_edge("contains", module_id, local_id, proof))
        if record.owner is not None:
            edges.append(_edge("doubles", local_id, record.owner.edge(), proof))
    return nodes, edges


test_facts.__test__ = False  # type: ignore[attr-defined]  # a function, not a test pytest should collect


def case_facts(relative: str, doubles: TestDoubles) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    """The ``test_case`` nodes of one test file, and the ``contains`` edge to each."""
    return TestCases(relative, doubles).facts(ref("module", doubles.names.module))


def add_test_facts(
    contribution: dict[str, Any], relative: str, tree: ast.Module, index: ProjectModuleIndex, module: str
) -> None:
    """Add a test file's test facts to its contribution, or, when reading them fails, a warning instead.

    The file's other facts are what test impact, untested code and dead code
    read, so a fault here never costs them (Ruling P16); a fault in its test
    cases never costs its doubles.
    """
    if not is_test_path(relative):
        return
    try:
        doubles = read_doubles(relative, tree, index, module)
        nodes, edges = test_facts(relative, tree, index, module, doubles)
    except Exception as error:  # any fault in one file's test facts is isolated to them
        _failed(contribution, relative, "test doubles", error)
        return
    try:
        cases, contained = case_facts(relative, doubles)
    except Exception as error:  # the cases fail alone: the doubles stand without them
        _failed(contribution, relative, "test cases", error)
    else:
        nodes, edges = [*nodes, *cases], [*edges, *contained]
    if not nodes:
        return
    contribution["nodes"] = sorted([*contribution["nodes"], *nodes], key=lambda item: item["local_id"])
    contribution["edges"] = sorted(
        [*contribution["edges"], *edges],
        key=lambda item: (item["kind"], item["source"], item["target"], item["evidence"]["start_line"]),
    )


def _failed(contribution: dict[str, Any], relative: str, what: str, error: Exception) -> None:
    contribution.setdefault("diagnostics", []).append(
        {
            "severity": "warning",
            "code": "PY_TEST_FACTS_FAILED",
            "message": f"The file's {what} could not be read, so none are reported: {error}",
            "evidence": {"path": relative, "start_line": 1, "end_line": 1},
        }
    )


def _edge(kind: str, source: str, target: str, proof: dict[str, Any]) -> dict[str, Any]:
    return {
        "kind": kind,
        "source": source,
        "target": target,
        "origin": "ast",
        "confidence": "certain",
        "evidence": proof,
        "attributes": {},
    }
