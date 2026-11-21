"""What a double stands in for: the project module, class or instance whose attribute it replaces.

A dotted string resolves through the longest prefix that is a project module,
as ``patch`` imports it; a name an import of a project declaration binds is
judged where it is declared (Decision 2). A target that resolves to nothing
in the project, when its first segment is a project module, is never
invented: it is unknown. A target whose module is not the project's keeps an
edge to the external reference, so the core counts it as external.
"""

from __future__ import annotations

import ast
from dataclasses import dataclass

from ..ast_helpers import ref
from .file_index import TestFileIndex
from .frozen_names import is_mock_api
from .imports import NameResolver


@dataclass(frozen=True)
class Owner:
    """A double's target: ``kind`` is ``module``, ``class``, ``instance`` or ``external``.

    ``name`` is the module's or class's canonical name, or the dotted path
    of an external one; ``specifier`` the module as the test spells it.
    """

    kind: str
    name: str
    specifier: str | None = None

    def edge(self) -> str:
        if self.kind == "module":
            return ref("module", self.name)
        if self.kind == "external":
            return ref("external_symbol", self.name)
        return ref("class", self.name)

    def wire(self, member: str | None) -> dict[str, str]:
        """The ``target`` attribute of a double of this owner, replacing ``member`` of it when one is named."""
        target: dict[str, str]
        if self.kind == "module" or (self.kind == "external" and member is not None):
            target = {"kind": "module", "specifier": self.specifier or self.name}
        else:
            kind = {"instance": "instance-member", "external": "class"}.get(self.kind, "class")
            target = {"kind": kind, "export": self.name}
            if self.specifier is not None:
                target["specifier"] = self.specifier
        if member is not None:
            target["member"] = member
        return target


@dataclass(frozen=True)
class Located:
    """Where a replaced attribute lives: its owner, its name, and the project symbol it is, if declared."""

    owner: Owner
    member: str | None
    declared: str | None = None


class TargetResolver:
    """Resolve patch targets and spec objects of one test file."""

    def __init__(self, names: NameResolver, files: TestFileIndex) -> None:
        self.names = names
        self.files = files
        self.index = names.index

    def dotted(self, text: str) -> Located | None:
        """Where ``patch("<text>")`` replaces an attribute, or None when it cannot be told."""
        parts = text.split(".")
        if len(parts) < 2 or not all(part.isidentifier() for part in parts):
            return None
        for end in range(len(parts) - 1, 0, -1):
            spelled = ".".join(parts[:end])
            if self.index.layout.module_file(spelled) is not None:
                module = self.index.layout.canonical_module(spelled)
                return self.in_module(module, spelled, parts[end:])
        return Located(Owner("external", ".".join(parts[:-1])), parts[-1])

    def whole(self, text: str) -> Owner | None:
        """The owner a dotted string names itself, as ``patch.multiple`` and ``patch.dict`` read it."""
        parts = text.split(".")
        if not all(part.isidentifier() for part in parts):
            return None
        module = self.names.project_module(text)
        if module is not None:
            return Owner("module", module.removeprefix("py:module:"), text)
        located = self.dotted(text)
        if located is None:
            return Owner("external", text) if len(parts) == 1 else None
        if located.owner.kind == "external":
            return Owner("external", text)
        if located.declared is not None and located.declared.startswith("py:class:"):
            return Owner("class", located.declared.removeprefix("py:class:"), located.owner.specifier)
        return None

    def in_module(self, module: str, spelled: str, rest: list[str]) -> Located | None:
        declared = self.index.module_declarations(module).get(rest[0])
        if len(rest) == 1:
            return Located(Owner("module", declaring_module(declared, rest[0]) or module, spelled), rest[0], declared)
        if len(rest) == 2 and declared is not None and declared.startswith("py:class:"):
            return Located(Owner("class", declared.removeprefix("py:class:"), spelled), rest[1])
        return None

    def of_object(self, node: ast.AST) -> Owner | None:
        """The owner an expression names (``patch.object``'s first argument, a ``spec``), or None."""
        instance = self.local_instance(node)
        if instance is not None:
            return Owner("instance", instance)
        resolved = self.names.resolve(node)
        if resolved is None or not resolved.qualified:
            return None
        symbol = resolved.symbol
        if symbol is None:
            return Owner("external", resolved.qualified)
        if symbol.startswith("py:module:"):
            name = symbol.removeprefix("py:module:")
            return Owner("module", name, name)
        if symbol.startswith("py:class:"):
            return Owner("class", symbol.removeprefix("py:class:"))
        return None

    def attribute(self, owner: Owner, member: str) -> Located:
        """``member`` of ``owner``; a module's member is judged in the module that declares it."""
        if owner.kind != "module":
            return Located(owner, member)
        declared = self.index.module_declarations(owner.name).get(member)
        moved = declaring_module(declared, member)
        return Located(Owner("module", moved or owner.name, owner.specifier), member, declared)

    def local_instance(self, node: ast.AST) -> str | None:
        """The project class of a local bound once to a call of it and never written, handed on or rebound.

        ``repo = Repo()`` and then ``patch.object(repo, "x")`` replaces an
        attribute of that instance, whose names are the class's. Anything
        else done with the local could add a name to it unseen.
        """
        if not isinstance(node, ast.Name):
            return None
        binding = self.files.binding_of(node)
        if binding is None or binding.kind != "assign" or not isinstance(binding.value, ast.Call):
            return None
        scope = self.files.scope_of(node)
        if len(scope.bindings.get(node.id, [])) != 1:
            return None
        resolved = self.names.resolve(binding.value.func)
        if resolved is None or resolved.symbol is None or not resolved.symbol.startswith("py:class:"):
            return None
        for use in self.files.names:
            if use.id == node.id and self.files.binding_of(use) is binding and not self._reads_only(use):
                return None
        return resolved.symbol.removeprefix("py:class:")

    def _reads_only(self, use: ast.Name) -> bool:
        """Whether ``use`` reads an attribute or calls a method, or is the object a patch form names first."""
        parent = self.files.parent(use)
        if isinstance(parent, ast.Attribute):
            return isinstance(parent.ctx, ast.Load)
        if isinstance(parent, ast.Call) and isinstance(parent.func, ast.Attribute) and is_mock_api(parent.func.attr):
            return True  # an assertion on a double reads the instance it was called with
        return isinstance(parent, ast.Call) and bool(parent.args) and parent.args[0] is use and _patches(parent)


def _patches(call: ast.Call) -> bool:
    """Whether a call is spelled as a patch form that takes an object and a member name."""
    func = call.func
    if not isinstance(func, ast.Attribute):
        return False
    # The builtin `setattr(obj, ...)` is a plain name; `monkeypatch.setattr` and `mocker.spy` are attributes.
    base = func.value
    prefix = base.attr if isinstance(base, ast.Attribute) else base.id if isinstance(base, ast.Name) else ""
    return (func.attr == "object" and prefix == "patch") or func.attr in {"spy", "setattr"}


def declaring_module(declared: str | None, member: str) -> str | None:
    """The module a ``py:function:`` or ``py:class:`` reference is declared in, when it declares it as ``member``.

    ``from a import Name as Alias`` binds ``Alias`` in the importing module
    only: ``a`` has no ``Alias``, so a patch of it stays with the module that
    binds it, whose names include the alias.
    """
    if declared is None or not declared.startswith(("py:function:", "py:class:")):
        return None
    module, _, leaf = declared.split(":", 2)[2].rpartition(".")
    return module if leaf == member else None
