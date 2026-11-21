"""What a test does with each double it holds: the members it names, the values it pins, the calls it asserts.

Read from every use of a handle (a name, a ``self.`` attribute, a call that
makes or starts a mock), each matched to its double through
:class:`Bindings`. A member is a name read through a spec'd handle, or
written through a ``spec_set`` one (Decision 2); the ``Mock`` API itself is
never a member. Pins come from ``return_value``, ``side_effect`` (one
``once`` pin per item of a literal iterable, none for an exception, the
returned literal of a lambda or a local def), ``**{"m.return_value": v}`` and
``new=``. Dynamic names (``setattr(m, name, v)``, ``configure_mock(**kw)``)
are skipped, and leave a plain-spec double's names open.
"""

from __future__ import annotations

import ast
from typing import TYPE_CHECKING

from ..signatures import signature_of
from .factories import is_mock_name, literal_string
from .file_index import FUNCTIONS, TestFileIndex, position
from .frozen_names import CALL_LIST_MATCHERS, CALL_MATCHERS, is_mock_api
from .imports import NameResolver
from .pinned_type import UNKNOWN, pinned_type, raises
from .records import DoubleRecord, Pin

if TYPE_CHECKING:
    from .bindings import Bindings, Handle

READING_BUILTINS = frozenset({"isinstance", "issubclass", "id", "type", "repr", "str", "print", "hasattr", "callable"})
"""Builtins a double is handed to without anything calling it."""

HANDING_ON = (ast.Return, ast.Yield, ast.YieldFrom, ast.Await, ast.List, ast.Tuple, ast.Set, ast.Dict)
"""Where a double's value goes on to whatever receives it."""


class Configuration:
    """Read the configuration of every double of one test file."""

    def __init__(self, files: TestFileIndex, names: NameResolver) -> None:
        self.files = files
        self.names = names
        self.bindings: Bindings | None = None
        self.records: dict[int, DoubleRecord] = {}

    def read(self, records: dict[int, DoubleRecord]) -> None:
        self.records = records
        if self.bindings is None:
            return
        uses: list[ast.AST] = [*self.files.names, *self.files.calls]
        uses += [
            node
            for node in self.files.attributes
            if isinstance(node.value, ast.Name) and isinstance(node.ctx, ast.Load)
        ]
        uses.sort(key=position)
        for node in uses:
            handle = self.bindings.handle_of(node)
            if handle is not None:
                self._use(handle, node)

    # -- construction keywords and replacements ------------------------------------------------------------------

    def keywords(self, record: DoubleRecord, keywords: list[ast.keyword], owner_level: bool | None) -> None:
        """Keywords that configure a double's mock: ``return_value=``, ``side_effect=``, ``**{"a.return_value": v}``."""
        level = record.construct.kind in {"mock", "autospec"} if owner_level is None else owner_level
        for keyword in keywords:
            if keyword.arg is not None:
                self._key(record, keyword.arg, keyword.value, level)
                continue
            literal = keyword.value
            if isinstance(literal, ast.Dict) and all(literal_string(key) is not None for key in literal.keys):
                for key, value in zip(literal.keys, literal.values, strict=True):
                    self._key(record, literal_string(key) or "", value, level)
            elif level:
                record.names_open = True

    def _key(self, record: DoubleRecord, key: str, value: ast.expr, owner_level: bool) -> None:
        parts = key.split(".")
        last = parts[-1]
        if last in {"return_value", "side_effect"} and len(parts) <= 2:
            member = parts[0] if len(parts) == 2 else None
            if member is not None:
                if not owner_level:
                    return
                self._named(record, member, value.lineno, store=False)
            self._configured(record, member, last, value)
        elif owner_level and len(parts) == 1:
            self._named(record, key, value.lineno, store=True)
        elif owner_level:
            # `configure_mock(**{"a.b": 1})` reads `a` to set `b` on it.
            self._named(record, parts[0], value.lineno, store=False)

    def replacement(self, record: DoubleRecord, member: str | None, value: ast.expr) -> None:
        """A value put in place of a member (``new=``, ``monkeypatch.setattr``'s value): its pin and signature."""
        callable_ = self._callable(value)
        if callable_ is not None:
            returned = self._returned(callable_)
            if returned is not None:
                self._pin(record, member, "new", returned, False)
            if record.owner is not None and record.owner.kind == "module":
                # A callable on a module is called as it is; on a class it would be bound to `self`.
                record.params.append((member, signature_of(callable_, receiver=False)["params"]))
            return
        kind = self._type(value)
        if kind != UNKNOWN:
            self._pin(record, member, "new", value, False)

    def replacements(self, record: DoubleRecord, keywords: list[ast.keyword]) -> None:
        """``patch.multiple``'s keywords: each a member, replaced unless by ``DEFAULT``."""
        for keyword in keywords:
            if keyword.arg is not None and not is_mock_name(keyword.value, self.names, self.files, "DEFAULT"):
                self.replacement(record, keyword.arg, keyword.value)

    # -- one use of a handle ----------------------------------------------------------------------------------------

    def _use(self, handle: Handle, node: ast.AST) -> None:
        record = handle.record.resolved()
        focus = handle.member
        chain: list[ast.Attribute] = []
        top = node
        parent = self.files.parent(top)
        while isinstance(parent, ast.Attribute) and parent.value is top:
            chain.append(parent)
            top = parent
            parent = self.files.parent(top)
        if not chain:
            self._whole(record, node, top, parent)
            return
        owner_level = handle.focus is None and record.self_member is None
        if owner_level and record.instance_spec and len(chain) > 1 and chain[0].attr == "return_value":
            chain = chain[1:]
        first = chain[0]
        if is_mock_api(first.attr):
            self._api(record, focus, chain, parent)
            return
        if owner_level:
            self._named(record, first.attr, first.lineno, store=isinstance(first.ctx, ast.Store))
        if len(chain) == 1:
            if isinstance(parent, ast.Call) and parent.func is first:
                record.sites += 1
            return
        if owner_level and is_mock_api(chain[1].attr):
            self._api(record, first.attr, chain[1:], parent)

    def _whole(self, record: DoubleRecord, node: ast.AST, top: ast.AST, parent: ast.AST | None) -> None:
        """A use of the handle itself: a call, a value handed on, or a read through a builtin."""
        if isinstance(parent, ast.keyword):
            parent = self.files.parent(parent)
        elif isinstance(parent, ast.Starred):
            parent = self.files.parent(parent)
        if isinstance(parent, ast.Call):
            if parent.func is top:
                record.sites += 1
            elif not self._through_builtin(record, top, parent) and not self._reads(parent):
                record.sites += 1
            return
        if isinstance(parent, (ast.Assign, ast.AnnAssign, ast.withitem)):
            if not (isinstance(node, ast.Call) and self._binds(parent, self.bindings)):
                record.sites += 1
            return
        if isinstance(parent, HANDING_ON):
            record.sites += 1

    @staticmethod
    def _binds(parent: ast.AST, bindings: Bindings | None) -> bool:
        """Whether the statement binds a name, or a receiver's attribute, to the value rather than handing it on."""
        if isinstance(parent, ast.Assign):
            targets = parent.targets
        elif isinstance(parent, ast.AnnAssign):
            targets = [parent.target]
        else:
            return True
        return all(
            isinstance(target, ast.Name)
            or (bindings is not None and isinstance(target, ast.Attribute) and bindings.receiver_of(target.value))
            for target in targets
        )

    def _through_builtin(self, record: DoubleRecord, top: ast.AST, call: ast.Call) -> bool:
        """``getattr(m, "a")``, ``setattr(m, "a", v)`` and their kin: a name read or written on the double itself."""
        func = call.func
        if not isinstance(func, ast.Name) or not call.args or call.args[0] is not top:
            return False
        resolved = self.names.resolve(func)
        if resolved is None or not resolved.qualified.startswith("builtins."):
            return False
        builtin = func.id
        name = literal_string(call.args[1]) if len(call.args) > 1 else None
        if builtin == "getattr" and name is not None and record.self_member is None:
            self._named(record, name, call.lineno, store=False)
        elif builtin == "setattr":
            if name is None:
                record.names_open = True
            elif record.self_member is None:
                self._named(record, name, call.lineno, store=True)
        return builtin in {"getattr", "setattr", "delattr"} or builtin in READING_BUILTINS

    def _reads(self, call: ast.Call) -> bool:
        """Whether a call reads a double rather than handing it to code: an assertion, the mock API, a factory."""
        func = call.func
        if id(call) in self.records:
            return True
        if isinstance(func, ast.Attribute) and (is_mock_api(func.attr) or func.attr.startswith("assert")):
            return True
        return is_mock_name(func, self.names, self.files, "call") or (
            isinstance(func, ast.Attribute) and is_mock_name(func.value, self.names, self.files, "call")
        )

    def _api(
        self, record: DoubleRecord, member: str | None, chain: list[ast.Attribute], parent: ast.AST | None
    ) -> None:
        """A use of the mock API on the double (``member`` None) or on one of its members."""
        first = chain[0]
        name = first.attr
        if len(chain) != 1:
            return
        if isinstance(first.ctx, ast.Store) and isinstance(parent, (ast.Assign, ast.AnnAssign)):
            value = parent.value
            if value is not None and name in {"return_value", "side_effect"}:
                self._configured(record, member, name, value)
            return
        if not isinstance(parent, ast.Call) or parent.func is not first:
            return
        if name in CALL_MATCHERS:
            record.matchers.append((member, self._matcher(record, member, name, parent)))
        elif name in CALL_LIST_MATCHERS and parent.args and isinstance(parent.args[0], (ast.List, ast.Tuple)):
            for listed in parent.args[0].elts:
                self._listed(record, member, name, listed)
        elif member is None and name == "configure_mock":
            self.keywords(record, parent.keywords, True)
        elif member is None and name in {"mock_add_spec", "attach_mock"}:
            record.names_open = True

    def _listed(self, record: DoubleRecord, member: str | None, api: str, listed: ast.expr) -> None:
        """One ``call(...)`` (or ``call.<name>(...)``) of an ``assert_has_calls`` list."""
        if not isinstance(listed, ast.Call):
            return
        func = listed.func
        if is_mock_name(func, self.names, self.files, "call"):
            record.matchers.append((member, self._matcher(record, member, api, listed)))
        elif (
            member is None
            and record.self_member is None
            and isinstance(func, ast.Attribute)
            and is_mock_name(func.value, self.names, self.files, "call")
        ):
            self._named(record, func.attr, listed.lineno, store=False)
            record.matchers.append((func.attr, self._matcher(record, func.attr, api, listed)))

    @staticmethod
    def _matcher(record: DoubleRecord, member: str | None, api: str, call: ast.Call) -> dict[str, object]:
        keywords = [keyword.arg for keyword in call.keywords if keyword.arg is not None]
        spread = any(isinstance(argument, ast.Starred) for argument in call.args) or any(
            keyword.arg is None for keyword in call.keywords
        )
        # Decision 4: a class-level autospec and `mocker.spy` on a class record the instance as the first argument.
        receiver = record.receiver and member in (None, record.self_member)
        return {
            "api": api,
            "line": call.lineno,
            "count": len(call.args) + len(keywords),
            "spread": spread or receiver,
            "keywords": keywords,
        }

    # -- names, pins and signatures -----------------------------------------------------------------------------

    @staticmethod
    def _named(record: DoubleRecord, name: str, line: int, store: bool) -> None:
        """A name read (``attribute``) or written (``assigned``) through the double itself."""
        if is_mock_api(name):
            return
        if store:
            if record.spec == "spec_set":
                record.name(name, line, "assigned")
            else:
                record.written.add(name)
        else:
            # Kept whatever the spec: only a spec'd double's names reach the wire (DoubleRecord.records_names).
            record.name(name, line, "attribute")

    def _configured(self, record: DoubleRecord, member: str | None, api: str, value: ast.expr) -> None:
        if api == "return_value":
            self._pin(record, member, api, value, False)
            return
        if isinstance(value, (ast.List, ast.Tuple)):
            for item in value.elts:
                if isinstance(item, ast.Starred):
                    return
                if not raises(item):
                    self._pin(record, member, api, item, True)
            return
        # An exception, raised, pins nothing: it is neither a lambda nor a def of this file.
        callable_ = self._callable(value)
        if callable_ is None:
            return
        returned = self._returned(callable_)
        if returned is not None:
            self._pin(record, member, api, returned, False)
        if not record.autospec:
            record.params.append((member, signature_of(callable_, receiver=False)["params"]))

    def _pin(self, record: DoubleRecord, member: str | None, api: str, value: ast.expr, once: bool) -> None:
        record.pins.append(Pin(member, api, value.lineno, once, self._type(value), value))

    def _type(self, value: ast.AST) -> str:
        def class_of(callee: ast.AST) -> str | None:
            resolved = self.names.resolve(callee)
            if resolved is None:
                return None
            if resolved.symbol is not None and resolved.symbol.startswith("py:class:"):
                return resolved.symbol.removeprefix("py:class:")
            return resolved.qualified if resolved.qualified.startswith("builtins.") else None

        return pinned_type(value, class_of)

    def _callable(self, value: ast.expr) -> ast.Lambda | ast.FunctionDef | ast.AsyncFunctionDef | None:
        """The lambda, or the function this file defines, that a value is."""
        if isinstance(value, ast.Lambda):
            return value
        if isinstance(value, ast.Name):
            binding = self.files.binding_of(value)
            if binding is not None and binding.kind == "def" and isinstance(binding.node, FUNCTIONS):
                return binding.node
        return None

    def _returned(self, callable_: ast.Lambda | ast.FunctionDef | ast.AsyncFunctionDef) -> ast.expr | None:
        """The value a lambda, or a def with one ``return`` and no ``yield``, hands back, when its type is known."""
        if isinstance(callable_, ast.Lambda):
            returned: ast.expr | None = callable_.body
        else:
            values = self.files.returns.get(id(callable_), [])
            if len(values) != 1 or not isinstance(self.files.parent(values[0]), ast.Return):
                return None
            returned = values[0]
        if returned is None or self._type(returned) in {UNKNOWN, "callable"}:
            return None
        return returned
