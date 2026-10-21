"""The per-file fact accumulator: what a scan of one file collected, rendered deterministically.

Its own module so the framework enrichers and the collector that drives them
can both take it without importing each other.
"""

import ast
from typing import Any

from .ast_helpers import evidence


class PythonFactAccumulator:
    """Store and deterministically render facts collected for one Python file."""

    def __init__(self, relative: str) -> None:
        self.relative = relative
        self.nodes: dict[str, dict[str, Any]] = {}
        # One entry per site: the core collapses repeated edges.
        self.edges: list[dict[str, Any]] = []
        self.diagnostics: list[dict[str, Any]] = []

    def add_node(
        self,
        local_id: str,
        kind: str,
        canonical: str,
        display: str,
        node: ast.AST,
        attributes: dict[str, Any] | None = None,
        origin: str = "ast",
    ) -> None:
        self.nodes.setdefault(
            local_id,
            {
                "local_id": local_id,
                "kind": kind,
                "canonical_name": canonical,
                "display_name": display,
                "origin": origin,
                "confidence": "certain",
                "evidence": evidence(self.relative, node),
                "attributes": attributes or {},
            },
        )

    def add_edge(
        self,
        kind: str,
        source: str,
        target: str,
        node: ast.AST,
        attributes: dict[str, Any] | None = None,
        origin: str = "ast",
        confidence: str = "certain",
    ) -> None:
        self.edges.append(
            {
                "kind": kind,
                "source": source,
                "target": target,
                "origin": origin,
                "confidence": confidence,
                "evidence": evidence(self.relative, node),
                "attributes": attributes or {},
            }
        )

    def add_diagnostic(self, code: str, message: str, node: ast.AST) -> None:
        self.diagnostics.append(
            {"severity": "warning", "code": code, "message": message, "evidence": evidence(self.relative, node)}
        )

    def result(self) -> dict[str, Any]:
        return {
            "owner_key": f"knossos.python:file:{self.relative}",
            "nodes": sorted(self.nodes.values(), key=lambda item: item["local_id"]),
            "edges": sorted(
                self.edges,
                key=lambda item: (item["kind"], item["source"], item["target"], item["evidence"]["start_line"]),
            ),
            "diagnostics": self.diagnostics,
        }
