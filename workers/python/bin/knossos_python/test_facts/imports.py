"""What a name or dotted expression in a test file stands for: a standard-library path, or a project symbol.

A name is followed to the binding that reaches it (:class:`TestFileIndex`),
so a local that shadows an import stands for nothing. Imports resolve as the
collector resolves them, through the project's module index, so the modules
consulted are read by the file's own scope.
"""

from __future__ import annotations

import ast
from dataclasses import dataclass
from pathlib import PurePosixPath

from ..ast_helpers import absolute_import, ref
from ..module_index import ProjectModuleIndex
from .file_index import Binding, TestFileIndex
from .frozen_names import BUILTINS


@dataclass(frozen=True)
class Resolved:
    """What an expression names: its dotted path as imported, and the project symbol, if it is one."""

    qualified: str
    symbol: str | None = None


class NameResolver:
    """Resolve names and attribute chains of one test file."""

    def __init__(self, index: ProjectModuleIndex, files: TestFileIndex, relative: str, module: str) -> None:
        self.index = index
        self.files = files
        self.relative = relative
        self.module = module
        self.location = index.layout.module_for(relative)
        self.is_package = PurePosixPath(relative).stem == "__init__"
        self._imports: dict[int, Resolved] = {}

    def resolve(self, node: ast.AST) -> Resolved | None:
        if isinstance(node, ast.Name):
            binding = self.files.binding_of(node)
            if binding is None:
                if self.files.owner_of(node.id, self.files.scope_of(node)) is None and node.id in BUILTINS:
                    return Resolved(f"builtins.{node.id}")
                return None
            return self.of_binding(binding)
        if isinstance(node, ast.Attribute):
            base = self.resolve(node.value)
            return None if base is None else self.member(base, node.attr)
        return None

    def of_binding(self, binding: Binding) -> Resolved | None:
        if (
            binding.kind == "import"
            and isinstance(binding.node, (ast.Import, ast.ImportFrom))
            and isinstance(binding.value, ast.alias)
        ):
            key = id(binding.value)
            cached = self._imports.get(key)
            if cached is None:
                cached = self._import(binding.node, binding.value)
                self._imports[key] = cached
            return cached
        if binding.kind in {"def", "class"} and self.files.scope_of(binding.node) is self.files.module_scope:
            kind = "class" if binding.kind == "class" else "function"
            return Resolved(f"{self.module}.{binding.name}", ref(kind, f"{self.module}.{binding.name}"))
        return None

    def member(self, base: Resolved, name: str) -> Resolved:
        """What ``<base>.<name>`` names: a project module's declaration or submodule, else the dotted path."""
        qualified = f"{base.qualified}.{name}"
        if base.symbol is None or not base.symbol.startswith("py:module:"):
            return Resolved(qualified)
        module = base.symbol.removeprefix("py:module:")
        declared = self.index.module_declarations(module).get(name)
        if declared is not None:
            return Resolved(qualified, declared)
        return Resolved(qualified, self.project_module(f"{module}.{name}"))

    def project_module(self, spelled: str) -> str | None:
        """The ``py:module:`` reference of a project module an import of ``spelled`` finds, or None."""
        if self.index.layout.module_file(spelled) is None:
            return None
        return ref("module", self.index.layout.canonical_module(spelled))

    def _import(self, statement: ast.Import | ast.ImportFrom, alias: ast.alias) -> Resolved:
        if isinstance(statement, ast.Import):
            spelled = alias.name if alias.asname else alias.name.split(".")[0]
            return Resolved(self._standard(spelled), self._module_or_sibling(spelled))
        source = absolute_import(self.location, statement.level, statement.module, self.is_package)
        if not source:
            return Resolved("")
        if statement.level == 0 and self.index.layout.module_file(source) is None:
            sibling = self._sibling(source)
            source = sibling or source
        declared = self.index.module_declarations(source).get(alias.name) if self._is_project(source) else None
        symbol = declared or self.project_module(f"{source}.{alias.name}")
        return Resolved(self._standard(f"{source}.{alias.name}"), symbol)

    def _is_project(self, spelled: str) -> bool:
        return self.index.layout.module_file(spelled) is not None

    def _module_or_sibling(self, spelled: str) -> str | None:
        found = self.project_module(spelled)
        if found is None:
            sibling = self._sibling(spelled)
            found = None if sibling is None else self.project_module(sibling)
        return found

    def _sibling(self, spelled: str) -> str | None:
        """A module beside a test in a directory that is no package: pytest puts that directory on the path."""
        directory = PurePosixPath(self.relative).parent
        if not directory.parts or self.index.layout.is_package_directory(directory):
            return None
        return self.index.layout.script_sibling_module(self.relative, spelled)

    def _standard(self, qualified: str) -> str:
        """``mock`` is the backport of ``unittest.mock``: one name for both, unless the project has a ``mock``."""
        head = qualified.split(".", 1)[0]
        if head == "mock" and not self._is_project("mock"):
            return "unittest." + qualified
        return qualified
