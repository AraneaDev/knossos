"""Which double an expression of a test file holds, and through which binding.

Per function: a local bound to a double, a ``with … as`` target, a
``start()`` result, a parameter a patch decorator injects (bottom-up, a
decorator given ``new=`` injecting nothing, a class decorator applying to
each ``test*`` method), ``self.`` and ``cls.`` attributes the class's
``setUp`` (and its kin) writes once, and a parameter named after a fixture of
the same file that returns or yields a double. A double held through
``self.`` or a fixture is ``held``: code the file does not show may swap it,
so it keeps its identity but never answers with a pinned value (the R12 and
R14 analogs).
"""

from __future__ import annotations

import ast
from dataclasses import dataclass

from ..fixtures import fixture_name
from .conftests import Derive, conftest_double
from .factories import PATCH_KINDS, arguments_of, is_mock_name
from .file_index import FUNCTIONS, Binding, TestFileIndex
from .frozen_names import MOCKER_FIXTURES, PYTEST_BUILTIN_FIXTURES, SETUP_METHODS
from .imports import NameResolver
from .records import DoubleRecord

MAX_DEPTH = 8
"""Links followed at most from a use to the call that made its double."""

TEST_PREFIX = "test"
"""``patch.TEST_PREFIX``: the methods a class decorator patches."""


@dataclass(frozen=True)
class Handle:
    """A double as an expression holds it: the record, the member it is the mock of, and whether it is held."""

    record: DoubleRecord
    focus: str | None = None
    held: bool = False

    @property
    def member(self) -> str | None:
        """The member this handle's own `return_value` and call assertions configure."""
        return self.focus if self.focus is not None else self.record.resolved().self_member


@dataclass(frozen=True)
class Value:
    """What an expression evaluates to: a double's mock, or a patcher that ``start()`` or ``with`` makes one of."""

    handle: Handle
    patcher: bool = False


class Bindings:
    """Follow expressions of one test file to the doubles they hold."""

    def __init__(
        self, files: TestFileIndex, names: NameResolver, records: dict[int, DoubleRecord], conftests: Derive
    ) -> None:
        self.files = files
        self.names = names
        self.records = records
        self.conftests = conftests
        self.injected: dict[int, Handle] = {}
        self.fixtures: dict[tuple[int, str], Handle | None] = {}
        self._setups: dict[int, dict[str, Value | None]] = {}
        self._pending: set[tuple[int, str]] = set()
        self._receiver_writes: (
            dict[int, list[tuple[ast.FunctionDef | ast.AsyncFunctionDef, str | None, ast.AST | None]]] | None
        ) = None
        for function in files.functions:
            self._inject(function)

    def handle_of(self, node: ast.AST) -> Handle | None:
        """The double's mock ``node`` holds, or None."""
        value = self.value_of(node)
        return None if value is None or value.patcher else value.handle

    def value_of(self, node: ast.AST, depth: int = 0) -> Value | None:
        if depth > MAX_DEPTH:
            return None
        if isinstance(node, ast.Call):
            return self._call(node, depth)
        if isinstance(node, ast.Name):
            binding = self.files.binding_of(node)
            return None if binding is None else self._binding(binding, depth)
        if isinstance(node, ast.Attribute):
            return self._attribute(node, depth)
        return None

    def _call(self, node: ast.Call, depth: int) -> Value | None:
        record = self.records.get(id(node))
        if record is not None:
            if record.construct.returns_mock:
                resolved = record.resolved()
                return None if resolved is record and record.handle_less else Value(Handle(resolved))
            return Value(Handle(record), patcher=True)
        func = node.func
        if isinstance(func, ast.Attribute) and func.attr == "start" and not node.args and not node.keywords:
            return self._started(self.value_of(func.value, depth + 1))
        return None

    @staticmethod
    def _started(value: Value | None) -> Value | None:
        """The mock a patcher hands over when started or entered."""
        if value is None or not value.patcher:
            return None
        record = value.handle.record
        resolved = record.resolved()
        if (resolved is record and record.handle_less) or record.construct.kind in {"patch.multiple", "patch.dict"}:
            return None
        return Value(Handle(resolved, value.handle.focus, value.handle.held))

    def _binding(self, binding: Binding, depth: int) -> Value | None:
        if binding.kind == "assign" and binding.value is not None:
            return self.value_of(binding.value, depth + 1)
        if binding.kind == "with" and binding.value is not None:
            return self._started(self.value_of(binding.value, depth + 1))
        if binding.kind == "param":
            injected = self.injected.get(id(binding.node))
            if injected is not None:
                return Value(injected)
            fixture = self.fixture_of(binding)
            return None if fixture is None else Value(fixture)
        return None

    def _attribute(self, node: ast.Attribute, depth: int) -> Value | None:
        """``self.<x>`` or ``cls.<x>`` in a method, written once by its class's setup."""
        method = self._method_receiver(node.value)
        return None if method is None else self._setup(method[1], depth).get(node.attr)

    def _setup(self, klass: ast.ClassDef, depth: int) -> dict[str, Value | None]:
        """What each receiver attribute of ``klass`` holds, when one setup method writes it, once."""
        known = self._setups.get(id(klass))
        if known is not None:
            return known
        setup: dict[str, Value | None] = {}
        self._setups[id(klass)] = setup
        writes = self._writes().get(id(klass), [])
        if any(attribute is None for _, attribute, _ in writes):
            return setup
        counts: dict[str | None, int] = {}
        for _, attribute, _ in writes:
            counts[attribute] = counts.get(attribute, 0) + 1
        for function, attribute, value in writes:
            if attribute is None:
                continue
            if counts[attribute] != 1 or function.name not in SETUP_METHODS or value is None:
                setup[attribute] = None
                continue
            found = self.value_of(value, depth + 1)
            setup[attribute] = (
                None if found is None else Value(Handle(found.handle.record, found.handle.focus, True), found.patcher)
            )
        return setup

    def _writes(self) -> dict[int, list[tuple[ast.FunctionDef | ast.AsyncFunctionDef, str | None, ast.AST | None]]]:
        """By class, each write to an attribute of one of its methods' receiver; a dynamic one names no attribute."""
        if self._receiver_writes is not None:
            return self._receiver_writes
        writes: dict[int, list[tuple[ast.FunctionDef | ast.AsyncFunctionDef, str | None, ast.AST | None]]] = {}
        for node in [*self.files.attributes, *self.files.calls]:
            if isinstance(node, ast.Attribute):
                if isinstance(node.ctx, ast.Load):
                    continue
                parent = self.files.parent(node)
                written: tuple[ast.AST, str | None, ast.AST | None] = (
                    node.value,
                    node.attr,
                    parent.value if isinstance(parent, ast.Assign) and len(parent.targets) == 1 else None,
                )
            elif (
                isinstance(node, ast.Call)
                and isinstance(node.func, ast.Name)
                and node.func.id in {"setattr", "delattr"}
                and node.args
            ):
                written = (node.args[0], None, None)
            else:
                continue
            method = self._method_receiver(written[0])
            if method is not None:
                writes.setdefault(id(method[1]), []).append((method[0], written[1], written[2]))
        self._receiver_writes = writes
        return writes

    def receiver_class(self, node: ast.AST) -> ast.ClassDef | None:
        """The class whose method ``node`` is the receiver of, or None."""
        method = self._method_receiver(node)
        return None if method is None else method[1]

    def receiver_of(self, node: ast.AST) -> bool:
        """Whether ``node`` is the receiver (``self``, ``cls``) of the method it is read in."""
        return self._method_receiver(node) is not None

    def _method_receiver(self, node: ast.AST) -> tuple[ast.FunctionDef | ast.AsyncFunctionDef, ast.ClassDef] | None:
        """The method and class whose receiver (its first parameter) ``node`` reads."""
        if not isinstance(node, ast.Name):
            return None
        binding = self.files.binding_of(node)
        if binding is None or binding.kind != "param":
            return None
        function = self.files.scope_of(binding.node).node
        klass = self.files.scope_of(function).node
        if not isinstance(function, FUNCTIONS) or not isinstance(klass, ast.ClassDef):
            return None
        positional = [*function.args.posonlyargs, *function.args.args]
        if not positional or positional[0] is not binding.node:
            return None
        return function, klass

    def _inject(self, function: ast.FunctionDef | ast.AsyncFunctionDef) -> None:
        """The parameters the patch decorators on ``function`` (and on its class, for a test) fill."""
        decorators = list(reversed(function.decorator_list))
        scope = self.files.scope_of(function)
        klass = scope.node if isinstance(scope.node, ast.ClassDef) else None
        if klass is not None and function.name.startswith(TEST_PREFIX):
            decorators += list(reversed(klass.decorator_list))
        positional = [*function.args.posonlyargs, *function.args.args]
        if klass is not None and not _is_static(function) and positional:
            positional = positional[1:]
        by_name = {argument.arg: argument for argument in [*positional, *function.args.kwonlyargs]}
        slot = 0
        for decorator in decorators:
            if not isinstance(decorator, ast.Call):
                continue
            record = self.records.get(id(decorator))
            if record is None or record.construct.returns_mock or record.construct.kind not in PATCH_KINDS:
                continue
            arguments = arguments_of(record.construct, decorator)
            if record.construct.kind == "patch.multiple":
                for keyword in arguments.extra:
                    if keyword.arg in by_name and is_mock_name(keyword.value, self.names, self.files, "DEFAULT"):
                        self.injected[id(by_name[keyword.arg])] = Handle(record, keyword.arg)
                continue
            if record.construct.kind == "patch.dict" or arguments.new is not None:
                continue
            if slot < len(positional):
                self.injected[id(positional[slot])] = Handle(record)
            slot += 1

    def fixture_of(self, binding: Binding) -> Handle | None:
        """The double a pytest fixture of this file hands the parameter ``binding``, held."""
        for owner in self._fixture_owners(binding):
            if self._declares(owner, binding.name):
                return self.fixture(owner, binding.name)
        return None

    def foreign_fixture(self, binding: Binding) -> str | None:
        """The canonical name of the double a ``conftest.py`` fixture hands the parameter ``binding``, if one does.

        Only when no fixture of this file takes the name: the nearest
        definition wins. The double is the conftest's node, so it gives the
        test its identity and nothing else.
        """
        owners = self._fixture_owners(binding)
        if not owners or any(self._declares(owner, binding.name) for owner in owners):
            return None
        return conftest_double(self.names.index, self.names.relative, binding.name, self.conftests)

    def _fixture_owners(self, binding: Binding) -> list[int]:
        """Where a fixture for parameter ``binding`` may be declared, nearest first; none if no fixture fills it."""
        function = self.files.scope_of(binding.node).node
        name = binding.name
        if binding.kind != "param" or not isinstance(function, FUNCTIONS) or id(binding.node) in self.injected:
            return []
        if (
            name in MOCKER_FIXTURES
            or name in PYTEST_BUILTIN_FIXTURES
            or parametrized(function, name, self._class_of(function))
        ):
            return []
        if not (function.name.startswith(TEST_PREFIX) or fixture_name(function) is not None):
            return []
        scope = self.files.scope_of(function)
        owners = [id(scope.node)] if isinstance(scope.node, ast.ClassDef) else []
        return [*owners, id(self.files.tree)]

    def _class_of(self, function: ast.AST) -> ast.ClassDef | None:
        scope = self.files.scope_of(function).node
        return scope if isinstance(scope, ast.ClassDef) else None

    def _declares(self, owner: int, name: str) -> bool:
        return any(
            fixture_name(function) == name and id(self.files.scope_of(function).node) == owner
            for function in self.files.functions
        )

    def fixture(self, owner: int, name: str) -> Handle | None:
        """The double the last fixture ``name`` declared in ``owner`` (a class or the module) returns or yields."""
        key = (owner, name)
        if key in self.fixtures:
            return self.fixtures[key]
        if key in self._pending:
            return None
        self._pending.add(key)
        found: Handle | None = None
        for function in self.files.functions:
            if fixture_name(function) != name or id(self.files.scope_of(function).node) != owner:
                continue
            handles = [self.handle_of(value) for value in self.files.returns.get(id(function), [])]
            held = [handle for handle in handles if handle is not None]
            found = None
            if held and len(held) == len(handles) and len({id(handle.record) for handle in held}) == 1:
                found = Handle(held[0].record, held[0].focus, True)
        self.fixtures[key] = found
        return found


def _is_static(function: ast.FunctionDef | ast.AsyncFunctionDef) -> bool:
    return any(
        isinstance(decorator, ast.Name) and decorator.id == "staticmethod" for decorator in function.decorator_list
    )


def parametrized(
    function: ast.FunctionDef | ast.AsyncFunctionDef, name: str, klass: ast.ClassDef | None = None
) -> bool:
    """Whether a ``parametrize`` mark on ``function``, or on its class, names ``name``, which then takes no fixture."""
    for decorator in [*function.decorator_list, *(klass.decorator_list if klass is not None else [])]:
        if not isinstance(decorator, ast.Call) or not decorator.args:
            continue
        func = decorator.func
        if isinstance(func, ast.Attribute) and func.attr == "parametrize":
            names = decorator.args[0]
            if isinstance(names, ast.Constant) and isinstance(names.value, str):
                if name in [part.strip() for part in names.value.split(",")]:
                    return True
            elif not isinstance(names, (ast.List, ast.Tuple)) or any(
                isinstance(element, ast.Constant) and element.value == name for element in names.elts
            ):
                return True
    return False
