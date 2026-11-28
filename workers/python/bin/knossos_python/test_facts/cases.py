"""The tests of a test file, as ``test_case`` nodes: each test's calls into production, and its assertions.

A test is what pytest's default collection finds, plus unittest's: a
module-level ``test*`` function, a ``test*`` method of a ``Test*`` class
without ``__init__`` (nested in another such class included), and a
``test*`` method of a ``TestCase`` subclass; async ones too, and never a
fixture. Its name is ``test_x`` or ``TestX.test_y``, its canonical name
``<file>#test:<def line>`` (no two ``def`` share a line). An
assertion belongs to the innermost test around it, nested functions
included.
"""

from __future__ import annotations

import ast
from dataclasses import dataclass
from typing import Any

from ..ast_helpers import evidence, ref
from ..fixtures import fixture_name
from .assertions import AssertionSyntax, Found
from .doubles import TestDoubles
from .echoes import EchoGuard
from .file_index import FUNCTIONS, Scope, TestFileIndex, position
from .local_literals import LocalLiterals
from .operands import OperandReader
from .production_calls import ProductionCalls

TEST_PREFIX = "test"
CLASS_PREFIX = "Test"


@dataclass(frozen=True)
class Case:
    """One test: its function, its name, and the class it is a method of."""

    function: ast.FunctionDef | ast.AsyncFunctionDef
    name: str
    klass: ast.ClassDef | None


class TestCases:
    """Read the tests of one test file against the doubles :class:`TestDoubles` read from the same walk."""

    __test__ = False  # a reader, not a test class pytest should collect

    def __init__(self, relative: str, doubles: TestDoubles) -> None:
        self.relative = relative
        self.files: TestFileIndex = doubles.files
        self.names = doubles.names
        self.cases = find_cases(self.files)
        literals = LocalLiterals(self.files)
        self.production = ProductionCalls(self.files, self.names, doubles.bindings, doubles.records)
        echoes = EchoGuard(self.files, self.names, doubles.bindings, literals)
        self.operands = OperandReader(relative, doubles, literals, self.production, echoes)
        self.syntax = AssertionSyntax(self.names, doubles.bindings)

    def facts(self, module_id: str) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
        """The ``test_case`` nodes, and a ``contains`` edge from the module to each."""
        found = self._assertions()
        nodes: list[dict[str, Any]] = []
        edges: list[dict[str, Any]] = []
        for case in self.cases:
            canonical = f"{self.relative}#test:{case.function.lineno}"
            assertions = sorted(found.get(id(case.function), []), key=lambda item: position(item.node))
            reach = self.production.of_case(case.function, case.klass)
            attributes: dict[str, Any] = {
                "name": case.name,
                "production_calls": reach.calls,
                "subjects": sorted(reach.subjects),
            }
            if any(assertion.raises for assertion in assertions):
                attributes["should_panic"] = True
            attributes["assertions"] = [self._wire(assertion) for assertion in assertions]
            local_id = ref("test_case", canonical)
            proof = evidence(self.relative, case.function)
            nodes.append(
                {
                    "local_id": local_id,
                    "kind": "test_case",
                    "canonical_name": canonical,
                    "display_name": case.name,
                    "origin": "ast",
                    "confidence": "certain",
                    "evidence": proof,
                    "attributes": attributes,
                }
            )
            edges.append(
                {
                    "kind": "contains",
                    "source": module_id,
                    "target": local_id,
                    "origin": "ast",
                    "confidence": "certain",
                    "evidence": proof,
                    "attributes": {},
                }
            )
        return nodes, edges

    def _wire(self, found: Found) -> dict[str, Any]:
        wire: dict[str, Any] = {"api": found.api, "line": getattr(found.node, "lineno", 1)}
        if found.negated:
            wire["negated"] = True
        wire["operands"] = [self.operands.wire(operand, found.identity) for operand in found.operands]
        return wire

    def _assertions(self) -> dict[int, list[Found]]:
        """Each assertion of the file, by the function of the innermost test it is made in."""
        tests = {id(case.function) for case in self.cases}
        by_test: dict[int, list[Found]] = {}
        nodes: list[ast.Assert | ast.Call] = [*self.files.asserts, *self.files.calls]
        for node in nodes:
            test = self._test_around(node, tests)
            if test is None:
                continue
            found = self.syntax.of_assert(node) if isinstance(node, ast.Assert) else self.syntax.of_call(node)
            if found is not None:
                by_test.setdefault(test, []).append(found)
        return by_test

    def _test_around(self, node: ast.AST, tests: set[int]) -> int | None:
        current: Scope | None = self.files.scope_of(node)
        while current is not None:
            if id(current.node) in tests:
                return id(current.node)
            current = current.parent
        return None


def find_cases(files: TestFileIndex) -> list[Case]:
    """The file's tests in source order."""
    cases: list[Case] = []
    for function in files.functions:
        if not function.name.startswith(TEST_PREFIX) or fixture_name(function) is not None:
            continue
        scope = files.scope_of(function)
        if scope is files.module_scope:
            cases.append(Case(function, function.name, None))
            continue
        klass = scope.node
        if isinstance(klass, ast.ClassDef):
            qualified = _test_class(files, klass)
            if qualified is not None:
                cases.append(Case(function, f"{qualified}.{function.name}", klass))
    return cases


def _test_class(files: TestFileIndex, klass: ast.ClassDef) -> str | None:
    """The dotted name of a class whose tests are collected, or None: module-level, or nested in such a class."""
    scope = files.scope_of(klass)
    if scope is files.module_scope:
        outer: str | None = ""
    elif isinstance(scope.node, ast.ClassDef):
        outer = _test_class(files, scope.node)
    else:
        return None
    if outer is None or not (_is_test_case(files, klass, set()) or _is_pytest_class(klass)):
        return None
    return f"{outer}.{klass.name}" if outer else klass.name


def _is_pytest_class(klass: ast.ClassDef) -> bool:
    return klass.name.startswith(CLASS_PREFIX) and not any(
        isinstance(statement, FUNCTIONS) and statement.name == "__init__" for statement in klass.body
    )


def _is_test_case(files: TestFileIndex, klass: ast.ClassDef, seen: set[int]) -> bool:
    """Whether a class derives from a ``TestCase``: by a base's name, or through a class of the file that does."""
    seen.add(id(klass))
    for base in klass.bases:
        named = base.attr if isinstance(base, ast.Attribute) else base.id if isinstance(base, ast.Name) else None
        if named is not None and named.endswith("TestCase"):
            return True
        binding = files.binding_of(base) if isinstance(base, ast.Name) else None
        if (
            binding is not None
            and isinstance(binding.node, ast.ClassDef)
            and id(binding.node) not in seen
            and _is_test_case(files, binding.node, seen)
        ):
            return True
    return False
