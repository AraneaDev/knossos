"""Which calls make a test double, and how each names its framework, its pattern and its arguments.

A call counts only when its callee resolves to the mock library: a name
imported from ``unittest.mock`` (or its ``mock`` backport), a method of a
pytest-mock ``*mocker`` fixture, or ``monkeypatch.setattr``, each fixture a
parameter of the function the call sits in. A project function that happens
to be called ``patch`` is no double.
"""

from __future__ import annotations

import ast
from dataclasses import dataclass

from .file_index import TestFileIndex
from .frozen_names import MOCK_CLASSES, MOCKER_FIXTURES
from .imports import NameResolver

UNITTEST = "unittest.mock."

PATCH_KINDS = frozenset({"patch", "patch.object", "patch.multiple", "patch.dict"})

_MOCKS = {name: "mock" for name in MOCK_CLASSES} | {"mock_open": "mock", "create_autospec": "autospec"}
_UNITTEST_KINDS = _MOCKS | {kind: kind for kind in PATCH_KINDS}
_MOCKER_KINDS = _UNITTEST_KINDS | {"spy": "spy", "stub": "mock", "async_stub": "mock"}
_LEAVES = frozenset(suffix.rsplit(".", 1)[-1] for suffix in _UNITTEST_KINDS)


@dataclass(frozen=True)
class Construct:
    """A call that makes a double.

    ``kind`` is ``mock`` (the ``Mock`` family), ``autospec``, a patch form,
    ``spy`` or ``setattr``. ``returns_mock`` is whether the call hands back
    the mock itself (a ``Mock``, ``mocker.patch``) rather than a patcher that
    ``start()`` or ``with`` turns into one.
    """

    kind: str
    framework: str
    pattern: str
    returns_mock: bool
    callable_name: str


def fixture_parameter(name: ast.AST, files: TestFileIndex, fixtures: frozenset[str]) -> bool:
    """Whether ``name`` is a parameter, by one of ``fixtures``' names, of the function it is read in."""
    if not isinstance(name, ast.Name) or name.id not in fixtures:
        return False
    binding = files.binding_of(name)
    return binding is not None and binding.kind == "param"


def construct_of(call: ast.Call, files: TestFileIndex, names: NameResolver) -> Construct | None:
    """The double ``call`` makes, or None when it makes none."""
    path: list[str] = []
    base: ast.AST = call.func
    while isinstance(base, ast.Attribute):
        path.insert(0, base.attr)
        base = base.value
    if path and fixture_parameter(base, files, MOCKER_FIXTURES):
        suffix = ".".join(path)
        kind = _MOCKER_KINDS.get(suffix)
        if kind is None:
            return None
        returns_mock = kind not in {"patch.multiple", "patch.dict"}
        return Construct(kind, "pytest-mock", f"pytest_mock.mocker.{suffix}", returns_mock, path[-1])
    if path == ["setattr"] and fixture_parameter(base, files, frozenset({"monkeypatch"})):
        return Construct("setattr", "pytest", "pytest.monkeypatch.setattr", False, "setattr")
    leaf = path[-1] if path else base.id if isinstance(base, ast.Name) else None
    if leaf not in _LEAVES:
        # Resolving a callee reads the modules its import names: only a name the mock library uses is worth it.
        return None
    resolved = names.resolve(call.func)
    if resolved is None or not resolved.qualified.startswith(UNITTEST) or resolved.symbol is not None:
        return None
    suffix = resolved.qualified.removeprefix(UNITTEST)
    kind = _UNITTEST_KINDS.get(suffix)
    if kind is None:
        return None
    return Construct(kind, "unittest", resolved.qualified, kind not in PATCH_KINDS, suffix.rsplit(".", 1)[-1])


def is_mock_name(node: ast.AST, names: NameResolver, files: TestFileIndex, name: str) -> bool:
    """Whether ``node`` names ``unittest.mock.<name>`` (``call``, ``ANY``, ``DEFAULT``) or ``mocker.<name>``."""
    if isinstance(node, ast.Attribute) and node.attr == name and fixture_parameter(node.value, files, MOCKER_FIXTURES):
        return True
    resolved = names.resolve(node)
    return resolved is not None and resolved.symbol is None and resolved.qualified == UNITTEST + name


@dataclass(frozen=True)
class Arguments:
    """A patch form's arguments by the role each plays."""

    owner: ast.expr | None
    member: ast.expr | None
    new: ast.expr | None
    keywords: dict[str, ast.expr]
    extra: list[ast.keyword]
    spread: bool


_SHAPES = {
    # Positional parameters of each patch form, after which come the keyword-only ones.
    "patch": ("owner", "new", "spec", "create", "spec_set", "autospec", "new_callable"),
    "patch.object": ("owner", "member", "new", "spec", "create", "spec_set", "autospec", "new_callable"),
    "patch.multiple": ("owner", "spec", "create", "spec_set", "autospec", "new_callable"),
    "patch.dict": ("owner", "values", "clear"),
    "spy": ("owner", "member"),
    "setattr": ("owner", "member", "new", "raising"),
    "mock": ("spec",),
    "autospec": ("spec", "spec_set", "instance"),
}

_OWN = {
    "patch": frozenset({"new", "spec", "create", "spec_set", "autospec", "new_callable", "unsafe"}),
    "patch.object": frozenset({"new", "spec", "create", "spec_set", "autospec", "new_callable", "unsafe"}),
    "patch.multiple": frozenset({"spec", "create", "spec_set", "autospec", "new_callable"}),
    "patch.dict": frozenset({"values", "clear"}),
    "spy": frozenset(),
    "setattr": frozenset({"raising"}),
    # `return_value` and `side_effect` configure the mock as any other keyword does, so they stay `extra`.
    "mock": frozenset({"spec", "spec_set", "wraps", "name", "unsafe", "parent", "read_data"}),
    "autospec": frozenset({"spec", "spec_set", "instance", "unsafe", "_name", "_parent"}),
}


def arguments_of(construct: Construct, call: ast.Call) -> Arguments:
    """Sort ``call``'s arguments into the roles ``construct``'s signature gives them.

    ``monkeypatch.setattr("pkg.mod.name", value)`` names its target as one
    dotted string, so its value moves up a place.
    """
    shape = _SHAPES[construct.kind]
    if construct.callable_name in {"mock_open", "stub", "async_stub"}:
        # `mock_open(mock, read_data)` and `mocker.stub(name)` take no spec.
        shape = ()
    if construct.kind == "setattr" and len(call.args) == 2 and _is_string(call.args[0]):
        shape = ("owner", "new", "raising")
    roles: dict[str, ast.expr] = {}
    spread = False
    for role, value in zip(shape, call.args, strict=False):
        if isinstance(value, ast.Starred):
            spread = True
            break
        roles[role] = value
    if len(call.args) > len(shape) or any(isinstance(value, ast.Starred) for value in call.args):
        spread = True
    own = _OWN[construct.kind]
    extra: list[ast.keyword] = []
    for keyword in call.keywords:
        if keyword.arg is not None and (keyword.arg in own or keyword.arg in shape):
            roles.setdefault(keyword.arg, keyword.value)
        else:
            extra.append(keyword)
    keywords = {key: value for key, value in roles.items() if key not in {"owner", "member", "new"}}
    return Arguments(roles.get("owner"), roles.get("member"), roles.get("new"), keywords, extra, spread)


def _is_string(node: ast.AST) -> bool:
    return isinstance(node, ast.Constant) and isinstance(node.value, str)


def literal_string(node: ast.AST | None) -> str | None:
    """The text of a plain string literal; None for anything else, an f-string included."""
    return node.value if isinstance(node, ast.Constant) and isinstance(node.value, str) else None


def is_true(node: ast.AST | None) -> bool:
    return isinstance(node, ast.Constant) and node.value is True


def is_given(node: ast.AST | None) -> bool:
    """Whether an optional flag is passed as anything but a literal ``None`` or ``False``."""
    return node is not None and not (isinstance(node, ast.Constant) and node.value in (None, False))
