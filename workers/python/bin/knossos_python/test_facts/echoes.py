"""Whether a call of a double answers with the one value the test pinned on it (Decision 5's ``mock-config``).

Only a double the test makes itself, as a plain ``Mock``, ``MagicMock`` or
``AsyncMock`` given nothing but its ``return_value`` and ``name``, answers
with a value the source states: a spec, an autospec or a patch may make any
member an ``AsyncMock`` (so a call hands back a coroutine), ``wraps=`` calls
the real thing, and ``side_effect=`` answers in its place. The value is the
member's one pin, a plain ``return_value`` of a constant set in an earlier
statement of a block that holds the call, in the function that made the
double; never one set through a ``self.`` or fixture handle (the R14
analog). An ``AsyncMock`` answers so only when awaited, any other only when
not.

Anything that could change the answer out of sight leaves every member of
the double without one: ``side_effect``, ``reset_mock``, ``configure_mock``,
``mock_add_spec``, ``attach_mock`` or a mock internal anywhere, a member
replaced or deleted, a name written through ``setattr`` or ``vars``, and the
double handed to any call that is no assertion (a helper may configure it),
put in a container, stored on an attribute, returned or yielded.
"""

from __future__ import annotations

import ast

from .bindings import Bindings
from .factories import is_mock_name
from .file_index import TestFileIndex, position
from .frozen_names import is_dunder, is_mock_api
from .imports import NameResolver
from .local_literals import LocalLiterals, is_constant
from .records import DoubleRecord

SYNC_MOCKS = frozenset({"Mock", "MagicMock", "PropertyMock", "ThreadingMock", "stub"})
"""Mock classes whose calls, and their children's, answer with ``return_value`` itself."""

ASYNC_MOCKS = frozenset({"AsyncMock", "async_stub"})
"""Mock classes whose calls, and their children's, answer with ``return_value`` once awaited."""

DEFEATING = frozenset({"side_effect", "reset_mock", "configure_mock", "mock_add_spec", "attach_mock", "__dict__"})
"""Names that change what a double answers in a way its pins do not show."""

READING = frozenset({"isinstance", "issubclass", "id", "repr", "str", "print", "hasattr", "callable", "len"})
"""Builtins a double may be handed to without anything configuring it (not ``type``: its class takes writes)."""

PLAIN_KEYWORDS = frozenset({"return_value", "name", "unsafe"})
"""Construction keywords that leave a mock answering with its ``return_value``."""

READ_IN = (ast.Compare, ast.Expr, ast.Assert, ast.Await, ast.If, ast.While, ast.withitem)
"""Where a double is only read: compared, tested, awaited, entered, its value dropped."""


class EchoGuard:
    """The doubles of one test file whose pinned values the test can read back."""

    def __init__(self, files: TestFileIndex, names: NameResolver, bindings: Bindings, literals: LocalLiterals) -> None:
        self.files = files
        self.names = names
        self.bindings = bindings
        self.literals = literals
        self.defeated: set[int] = set()
        uses: list[ast.AST] = [*files.names, *files.calls]
        uses += [node for node in files.attributes if isinstance(node.value, ast.Name)]
        uses.sort(key=position)
        for node in uses:
            handle = bindings.handle_of(node)
            if handle is not None and not self._harmless(node):
                self.defeated.add(id(handle.record.resolved()))

    def _harmless(self, node: ast.AST) -> bool:
        """Whether one use of a double leaves what it answers as its pins say.

        Anything but a read, a call of it or of a member, a pin (``…return_value
        = v``) or a plain alias (``other = m``, which the bindings follow) may
        reach code that configures it.
        """
        top = node
        parent = self.files.parent(top)
        while isinstance(parent, ast.Attribute) and parent.value is top:
            name = parent.attr
            if name in DEFEATING or name.startswith("_mock_"):
                return False
            if not isinstance(parent.ctx, ast.Load):
                holder = self.files.parent(parent)
                return (
                    name == "return_value"
                    and isinstance(parent.ctx, ast.Store)
                    and isinstance(holder, (ast.Assign, ast.AnnAssign))
                    and any(target is parent for target in _targets(holder))
                )
            top = parent
            parent = self.files.parent(top)
        if isinstance(parent, (ast.keyword, ast.Starred)):
            return self._read_by(self.files.parent(parent))
        if isinstance(parent, ast.Call):
            return parent.func is top or self._read_by(parent)
        if isinstance(parent, (ast.Assign, ast.AnnAssign)) and parent.value is top:
            return all(isinstance(target, ast.Name) for target in _targets(parent))
        if isinstance(parent, ast.For):
            return parent.iter is top
        return isinstance(parent, READ_IN) or (isinstance(parent, ast.UnaryOp) and isinstance(parent.op, ast.Not))

    def _read_by(self, call: ast.AST | None) -> bool:
        """Whether a call a double is handed to only reads it: an assertion, a call matcher, a reading builtin."""
        if not isinstance(call, ast.Call):
            return False
        func = call.func
        if isinstance(func, ast.Attribute) and (func.attr.startswith("assert") or is_mock_api(func.attr)):
            return True
        if is_mock_name(func, self.names, self.files, "call"):
            return True
        resolved = self.names.resolve(func) if isinstance(func, ast.Name) else None
        return resolved is not None and resolved.qualified.removeprefix("builtins.") in READING

    def pinned(self, record: DoubleRecord, member: str | None, call: ast.Call, awaited: bool) -> str | None:
        """The text of the value a call of ``member`` (None: the double's own call) answers with, or None."""
        construct = record.construct
        if id(record) in self.defeated or record.node.args:
            # Configured out of sight, or given a spec, a target or a spread that may be either.
            return None
        if not all(_plain(keyword) for keyword in record.node.keywords) or (member is not None and is_dunder(member)):
            # `side_effect=`, `wraps=`, `spec=` or `**options` at construction; a protocol hook.
            return None
        if construct.callable_name not in (ASYNC_MOCKS if awaited else SYNC_MOCKS):
            return None
        if self.files.function_of(record.node) is not self.files.function_of(call) or self.literals.is_dynamic(call):
            # Made elsewhere, or held in a local that a frame write or `exec` may rebind.
            return None
        pins = [pin for pin in record.pins if (pin.member or record.self_member) == member]
        pin = pins[0] if len(pins) == 1 else None
        if pin is None or pin.held or pin.value is None:
            # Several pins, or one set through a held handle (the R14 analog): no one value the call answers with.
            # A `side_effect` or `new=` pin never gets here: either defeats the double first.
            return None
        statement = self.files.statement_of(pin.value)
        if statement is None or not self.files.precedes(statement, call):
            return None
        return self._text(pin.value)

    def _text(self, value: ast.AST) -> str | None:
        if is_constant(value):
            return ast.unparse(value)
        if isinstance(value, ast.Name):
            literal = self.literals.literal_of(value)
            return None if literal is None else ast.unparse(literal)
        return None


def _targets(statement: ast.Assign | ast.AnnAssign) -> list[ast.expr]:
    return statement.targets if isinstance(statement, ast.Assign) else [statement.target]


def _plain(keyword: ast.keyword) -> bool:
    """Whether a construction keyword only pins: ``return_value=``, ``name=``, ``**{"a.return_value": v}``."""
    if keyword.arg is not None:
        return keyword.arg in PLAIN_KEYWORDS
    spread = keyword.value
    return isinstance(spread, ast.Dict) and all(
        isinstance(key, ast.Constant) and isinstance(key.value, str) and key.value.split(".")[-1] == "return_value"
        for key in spread.keys
    )
