"""The assertions a test makes, each with its matcher's name and the expressions it compares.

Ported from Momus's ``extractAssertions``. A bare ``assert`` on one
comparison is named for its operator (``assert ==``, ``assert is``,
``assert in``, ``assert <``…), ``!=``, ``is not`` and ``not in`` negated; a
chained comparison (``a < b < c``) is none, and any other bare ``assert``
is ``assert`` with the one expression it tests. A ``unittest`` assertion on
the test's receiver keeps its own name and its compared arguments, by
position or by name, never ``msg`` (the R6 analog); ``assertFalse`` and the
``Not`` forms are negated. ``pytest.raises``, ``pytest.warns``,
``assertRaises`` and ``assertWarns`` compare nothing and expect the test to
raise. A ``Mock`` assertion (``m.assert_called_once_with(...)``) has the
double it is made on as its one operand.
"""

from __future__ import annotations

import ast
from dataclasses import dataclass

from .bindings import Bindings
from .imports import NameResolver

COMPARISONS: dict[type[ast.cmpop], tuple[str, bool]] = {
    ast.Eq: ("==", False),
    ast.NotEq: ("!=", True),
    ast.Lt: ("<", False),
    ast.LtE: ("<=", False),
    ast.Gt: (">", False),
    ast.GtE: (">=", False),
    ast.Is: ("is", False),
    ast.IsNot: ("is not", True),
    ast.In: ("in", False),
    ast.NotIn: ("not in", True),
}
"""Each comparison operator's name, and whether it expects its operands to differ."""

_PAIR = ("first", "second")
UNITTEST_SHAPES: dict[str, tuple[str, ...]] = {
    "assertTrue": ("expr",),
    "assertFalse": ("expr",),
    "assertIsNone": ("obj",),
    "assertIsNotNone": ("obj",),
    "assertEqual": _PAIR,
    "assertEquals": _PAIR,
    "assertNotEqual": _PAIR,
    "assertNotEquals": _PAIR,
    "assertAlmostEqual": _PAIR,
    "assertAlmostEquals": _PAIR,
    "assertNotAlmostEqual": _PAIR,
    "assertNotAlmostEquals": _PAIR,
    "assertCountEqual": _PAIR,
    "assertMultiLineEqual": _PAIR,
    "assertIs": ("expr1", "expr2"),
    "assertIsNot": ("expr1", "expr2"),
    "assertIn": ("member", "container"),
    "assertNotIn": ("member", "container"),
    "assertIsInstance": ("obj", "cls"),
    "assertNotIsInstance": ("obj", "cls"),
    "assertGreater": ("a", "b"),
    "assertGreaterEqual": ("a", "b"),
    "assertLess": ("a", "b"),
    "assertLessEqual": ("a", "b"),
    "assertRegex": ("text", "expected_regex"),
    "assertNotRegex": ("text", "unexpected_regex"),
    "assertSequenceEqual": ("seq1", "seq2"),
    "assertListEqual": ("list1", "list2"),
    "assertTupleEqual": ("tuple1", "tuple2"),
    "assertSetEqual": ("set1", "set2"),
    "assertDictEqual": ("d1", "d2"),
}
"""``unittest.TestCase``'s value assertions and the parameters each compares, in order."""

UNITTEST_RAISES = frozenset(
    {"assertRaises", "assertRaisesRegex", "assertRaisesRegexp", "assertWarns", "assertWarnsRegex"}
)
"""``unittest`` assertions that expect the code they wrap to raise or warn."""

PYTEST_RAISES = frozenset({"pytest.raises", "pytest.warns", "pytest.deprecated_call"})
"""pytest's assertions that expect the code they wrap to raise or warn."""

IDENTITY_APIS = frozenset({"assert is", "assert is not", "assertIs", "assertIsNot"})
"""Assertions that compare by identity, under which a name read twice is the same value."""

MOCK_ASSERTIONS = frozenset(
    """
    assert_called assert_called_once assert_called_with assert_called_once_with assert_any_call assert_has_calls
    assert_not_called assert_awaited assert_awaited_once assert_awaited_with assert_awaited_once_with
    assert_any_await assert_has_awaits assert_not_awaited
    """.split()
)
"""The ``Mock`` API's assertions on the calls a double received."""


@dataclass(frozen=True)
class Found:
    """One assertion: where it is written, its name, whether negated, and the expressions it compares."""

    node: ast.AST
    api: str
    operands: list[ast.expr]
    negated: bool = False
    raises: bool = False

    @property
    def identity(self) -> bool:
        return self.api in IDENTITY_APIS


class AssertionSyntax:
    """Recognise the assertions of one test file."""

    def __init__(self, names: NameResolver, bindings: Bindings) -> None:
        self.names = names
        self.bindings = bindings

    def of_assert(self, statement: ast.Assert) -> Found | None:
        test = statement.test
        if not isinstance(test, ast.Compare):
            return Found(statement, "assert", [test])
        if len(test.ops) != 1:
            return None
        operator, negated = COMPARISONS[type(test.ops[0])]
        return Found(statement, f"assert {operator}", [test.left, test.comparators[0]], negated)

    def of_call(self, call: ast.Call) -> Found | None:
        func = call.func
        if isinstance(func, ast.Attribute):
            if func.attr in MOCK_ASSERTIONS:
                return Found(call, func.attr, [func.value])
            if self.bindings.receiver_of(func.value):
                return self._unittest(call, func.attr)
        resolved = self.names.resolve(func)
        if resolved is not None and resolved.symbol is None and resolved.qualified in PYTEST_RAISES:
            return Found(call, resolved.qualified, [], raises=True)
        return None

    @staticmethod
    def _unittest(call: ast.Call, name: str) -> Found | None:
        if name in UNITTEST_RAISES:
            return Found(call, name, [], raises=True)
        shape = UNITTEST_SHAPES.get(name)
        if shape is None:
            return None
        negated = name == "assertFalse" or "Not" in name.removeprefix("assert")
        if any(isinstance(argument, ast.Starred) for argument in call.args) or any(
            keyword.arg is None for keyword in call.keywords
        ):
            # A spread hides which argument is which.
            return Found(call, name, [], negated)
        by_name = {keyword.arg: keyword.value for keyword in call.keywords}
        operands: list[ast.expr] = []
        for index, parameter in enumerate(shape):
            value = call.args[index] if index < len(call.args) else by_name.get(parameter)
            if value is not None:
                operands.append(value)
        return Found(call, name, operands, negated)
