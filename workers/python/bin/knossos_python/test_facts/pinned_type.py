"""The type of a value a test pins on a double, as the worker names it (Decision 6).

One of ``int``, ``float``, ``complex``, ``str``, ``bytes``, ``bool``,
``None``, ``list``, ``dict``, ``set``, ``tuple``, ``class:<qualname>``,
``callable`` or ``unknown``. Only what the source spells is typed: a name, a
call of anything but a project class or a builtin constructor, and a mock are
``unknown``, and the core is then silent.
"""

from __future__ import annotations

import ast
from collections.abc import Callable

from .frozen_names import BUILTINS, EXCEPTION_SUFFIXES

UNKNOWN = "unknown"

_CONSTANTS: tuple[tuple[type, str], ...] = (
    (bool, "bool"),
    (int, "int"),
    (float, "float"),
    (complex, "complex"),
    (str, "str"),
    (bytes, "bytes"),
)

_DISPLAYS: tuple[tuple[tuple[type[ast.AST], ...], str], ...] = (
    ((ast.List, ast.ListComp), "list"),
    ((ast.Tuple,), "tuple"),
    ((ast.Dict, ast.DictComp), "dict"),
    ((ast.Set, ast.SetComp), "set"),
    ((ast.JoinedStr,), "str"),
    ((ast.Lambda,), "callable"),
)

_CONSTRUCTORS = frozenset({"int", "float", "complex", "str", "bytes", "bool", "list", "dict", "set", "tuple"})


def pinned_type(value: ast.AST, class_of: Callable[[ast.AST], str | None]) -> str:
    """The type of ``value``.

    ``class_of`` names the project class a callee is, or a builtin as
    ``builtins.<name>``, or gives None.
    """
    if isinstance(value, ast.Constant):
        if value.value is None:
            return "None"
        for kind, name in _CONSTANTS:
            if isinstance(value.value, kind):
                return name
        return UNKNOWN
    if isinstance(value, ast.UnaryOp) and isinstance(value.op, (ast.USub, ast.UAdd)):
        inner = pinned_type(value.operand, class_of)
        return inner if inner in {"int", "float", "complex"} else UNKNOWN
    for kinds, name in _DISPLAYS:
        if isinstance(value, kinds):
            return name
    if isinstance(value, ast.Call):
        named = class_of(value.func)
        if named is None:
            return UNKNOWN
        if named.startswith("builtins."):
            builtin = named.removeprefix("builtins.")
            return builtin if builtin in _CONSTRUCTORS else UNKNOWN
        return f"class:{named}"
    return UNKNOWN


def raises(value: ast.AST) -> bool:
    """Whether a ``side_effect`` value is an exception, by its class's name: raised, it pins nothing."""
    target = value.func if isinstance(value, ast.Call) else value
    name = target.attr if isinstance(target, ast.Attribute) else target.id if isinstance(target, ast.Name) else None
    if name is None:
        return False
    return (name in BUILTINS and name[:1].isupper() and name not in {"Ellipsis", "NotImplemented"}) or name.endswith(
        EXCEPTION_SUFFIXES
    )
