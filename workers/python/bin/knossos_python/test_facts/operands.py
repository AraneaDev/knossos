"""Where an assertion's operand gets its value, inside one test: the wire ``{source, text?, value?, double?}``.

Ported from Momus's ``exprIR``, after the PHP worker's ``OperandSource``.
The sources:

- ``literal``: a constant, a display of constants, or a local bound once to
  a constant (:class:`LocalLiterals`, Decision 5);
- ``mock-config``: a call of a double that answers with the value the test
  pinned on it (:class:`EchoGuard`), which ``value`` carries;
- ``mock-call``: anything else read off a double, held through ``self.`` or
  a fixture included (the R14 analog: identity, never a pinned value);
- ``production``: a call that lands in code under test, and what is read
  off its result;
- ``parameter``: a ``parametrize`` argument;
- ``unknown``: anything else.

``text`` is what lets the core show an operand equal to another, so it is
left out wherever evaluating the operand again may give another value.
Under ``==``, the orderings and ``in``, a value's own ``__eq__`` or a NaN
can make ``x == x`` false, so only a literal carries text there (the
constant's own text, for a local that holds one, Ruling P6). Under ``is``,
a bare name carries its own text, and a literal its text unless it is a
display that makes a new object each time (``[] is []`` is false).
"""

from __future__ import annotations

import ast
from dataclasses import dataclass
from typing import Any

from .bindings import Bindings, Handle, parametrized
from .doubles import TestDoubles
from .echoes import EchoGuard
from .file_index import FUNCTIONS, TestFileIndex
from .frozen_names import is_dunder, is_mock_api
from .local_literals import LocalLiterals, is_constant, is_literal_display
from .production_calls import ProductionCalls
from .records import DoubleRecord

MAX_DEPTH = 12
"""Links followed at most from an operand to the value it was bound to."""

MOCK_SOURCES = frozenset({"mock-config", "mock-call"})


@dataclass(frozen=True)
class Source:
    """Where a value comes from; for a double, its canonical name and its pinned value."""

    source: str
    double: str | None = None
    unconfigured_spy: bool = False
    value: str | None = None
    text: str | None = None


UNKNOWN = Source("unknown")
PRODUCTION = Source("production")


class OperandReader:
    """Read the operands of one test file's assertions."""

    def __init__(
        self,
        relative: str,
        doubles: TestDoubles,
        literals: LocalLiterals,
        production: ProductionCalls,
        echoes: EchoGuard,
    ) -> None:
        self.relative = relative
        self.files: TestFileIndex = doubles.files
        self.bindings: Bindings = doubles.bindings
        self.records = doubles.records
        self.literals = literals
        self.production = production
        self.echoes = echoes

    def wire(self, operand: ast.expr, identity: bool) -> dict[str, Any]:
        found = self.source(operand)
        wire: dict[str, Any] = {"source": found.source}
        text = _text(operand, found, identity)
        if text is not None:
            wire["text"] = text
        if found.value is not None:
            wire["value"] = found.value
        if found.double is not None:
            wire["double"] = found.double
            if found.unconfigured_spy:
                wire["unconfigured_spy"] = True
        return wire

    def source(self, node: ast.AST, depth: int = 0) -> Source:
        if depth > MAX_DEPTH:
            return UNKNOWN
        if isinstance(node, ast.Await):
            inner = node.value
            return (
                self._call(inner, True, depth)
                if isinstance(inner, ast.Call)
                else _derived(self.source(inner, depth + 1))
            )
        if is_literal_display(node):
            return Source("literal", text=ast.unparse(node))
        if isinstance(node, ast.Name):
            return self._name(node, depth)
        if isinstance(node, ast.Call):
            return self._call(node, False, depth)
        handle = self.bindings.handle_of(node)
        if handle is not None:
            return self._double(handle.record)
        if isinstance(node, (ast.Attribute, ast.Subscript)):
            return _derived(self.source(node.value, depth + 1))
        return UNKNOWN

    def _double(self, record: DoubleRecord, value: str | None = None) -> Source:
        resolved = record.resolved()
        spy = resolved.spy and not resolved.pins
        source = "mock-call" if value is None else "mock-config"
        return Source(source, resolved.canonical(self.relative), spy, value)

    def _name(self, name: ast.Name, depth: int) -> Source:
        literal = self.literals.literal_of(name)
        if literal is not None:
            return Source("literal", text=ast.unparse(literal))
        handle = self.bindings.handle_of(name)
        if handle is not None:
            return self._double(handle.record)
        binding = self.files.binding_of(name)
        if binding is None:
            return UNKNOWN
        if binding.kind == "param":
            foreign = self.bindings.foreign_fixture(binding)
            if foreign is not None:
                return Source("mock-call", foreign)
            function = self.files.scope_of(binding.node).node
            klass = self.files.scope_of(function).node
            if isinstance(function, FUNCTIONS) and parametrized(
                function, binding.name, klass if isinstance(klass, ast.ClassDef) else None
            ):
                return Source("parameter")
            return UNKNOWN
        if binding.kind not in {"assign", "with"} or binding.value is None:
            return UNKNOWN
        found = self.source(binding.value, depth + 1)
        if found.source == "literal" or (binding.kind == "with" and found.source not in MOCK_SOURCES | {"production"}):
            # A literal bound where it may not be the only value, or something a `with` hands over.
            return UNKNOWN
        if found.value is not None and (binding.kind == "with" or self.literals.single(name) is not binding):
            # The name may hold something else by now than the pinned value it was once given.
            return Source("mock-call", found.double, found.unconfigured_spy)
        return found

    def _call(self, call: ast.Call, awaited: bool, depth: int) -> Source:
        record = self.records.get(id(call))
        if record is not None:
            return self._double(record)
        func = call.func
        handle = self.bindings.handle_of(func)
        if handle is not None:
            return self._answer(handle, handle.member, call, awaited)
        if isinstance(func, ast.Attribute):
            owner = self.bindings.handle_of(func.value)
            if owner is not None:
                member = func.attr
                if owner.focus is not None or owner.record.resolved().self_member is not None or is_mock_api(member):
                    return self._double(owner.record)
                return self._answer(owner, member, call, awaited)
        if self.production.is_production(call):
            return PRODUCTION
        if isinstance(func, ast.Name):
            # A conftest fixture's double, called: its identity, as for any held double.
            called = self._name(func, depth + 1)
            if called.source == "mock-call":
                return called
        landed = self.production.landing(call)
        if isinstance(landed, FUNCTIONS):
            return self._helper(landed, depth)
        if isinstance(func, ast.Attribute):
            return _derived(self.source(func.value, depth + 1))
        return UNKNOWN

    def _answer(self, handle: Handle, member: str | None, call: ast.Call, awaited: bool) -> Source:
        """A call of a double: the value pinned on ``member`` when it answers with it for certain, else the double."""
        record = handle.record.resolved()
        # `handle.held` (the R14 analog) is defence in depth, unreachable today: a held double is made in a setUp
        # or a fixture, so `EchoGuard.pinned`'s same-function check would refuse it anyway.
        if handle.held or (member is not None and is_dunder(member)):
            return self._double(record)
        return self._double(record, self.echoes.pinned(record, member, call, awaited))

    def _helper(self, function: ast.FunctionDef | ast.AsyncFunctionDef, depth: int) -> Source:
        """What a helper of the file hands back: the source of its one ``return``, but never a literal or a pin."""
        values = self.files.returns.get(id(function), [])
        if len(values) != 1 or not isinstance(self.files.parent(values[0]), ast.Return):
            return UNKNOWN
        found = self.source(values[0], depth + 1)
        if found.source in {"literal", "unknown", "parameter"}:
            return UNKNOWN
        return found if found.value is None else Source("mock-call", found.double, found.unconfigured_spy)


def _derived(base: Source) -> Source:
    """What is read off a value (an attribute, an item, a call of a method): its double's, or production's."""
    if base.source in MOCK_SOURCES:
        return Source("mock-call", base.double, base.unconfigured_spy)
    return PRODUCTION if base.source == "production" else UNKNOWN


def _text(operand: ast.expr, found: Source, identity: bool) -> str | None:
    if not identity:
        return found.text if found.source == "literal" else None
    if isinstance(operand, ast.Name):
        return operand.id
    return found.text if found.source == "literal" and is_constant(operand) else None
