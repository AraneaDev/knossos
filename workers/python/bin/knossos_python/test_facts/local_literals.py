"""Which names of a test file hold a literal: a function's local bound once, to a constant (Decision 5).

A name is followed to its literal only when nothing the file shows, and
nothing it could hide, may write it. Its function binds it exactly once (an
assignment, a loop target, a walrus inside a comprehension, an unpacking,
``except … as``, a ``match`` capture, ``del``: each is a binding), and that
one binding is a plain assignment of a constant or a tuple of constants
reaching the use (an earlier statement of a block that holds the use, so not
a branch the use sits outside). A ``nonlocal`` or ``global`` declaration of
the name, here or in any scope nested inside, hands it to code this cannot
follow, and ``locals()``, ``vars()``, ``globals()``, ``exec`` and ``eval``
(also as ``builtins.exec`` or ``__builtins__["exec"]``) anywhere in the
function, or a frame read anywhere in the file
(``sys._getframe``, ``inspect.currentframe``, ``f_locals``), may write any
local. The value is immutable, so no call, method or alias can change it in
place: a list, dict or set is never followed.

Never a literal: an attribute (``self.x``, ``cls.x``, ``module.X``), a module
global, a class attribute, a parameter (a fixture's value, a ``parametrize``
argument, a patch's mock).
"""

from __future__ import annotations

import ast

from .file_index import FUNCTIONS, Binding, Scope, TestFileIndex

DYNAMIC_CALLS = frozenset({"locals", "vars", "globals", "exec", "eval"})
"""Builtins that read or write a function's locals by name."""

BUILTINS_MODULES = frozenset({"builtins", "__builtins__"})
"""The names that hold the builtins themselves, so ``builtins.exec`` is ``exec`` under another spelling."""

FRAME_NAMES = frozenset(
    {
        "_getframe",
        "currentframe",
        "f_locals",
        "f_back",
        "f_globals",
        "getouterframes",
        "getinnerframes",
        "settrace",
        "setprofile",
    }
)
"""Names that reach another function's frame, whose locals a frame write (PEP 667) can change."""

INSPECT_FRAMES = frozenset({"stack", "trace"})
"""``inspect``'s functions that hand back every frame on the stack."""


def is_constant(value: ast.AST) -> bool:
    """Whether ``value`` is a constant whose text states it: a literal, a signed number, a tuple of constants."""
    if isinstance(value, ast.Constant):
        return True
    if isinstance(value, ast.UnaryOp) and isinstance(value.op, (ast.USub, ast.UAdd)):
        return isinstance(value.operand, ast.Constant) and isinstance(value.operand.value, (int, float, complex))
    if isinstance(value, ast.Tuple):
        return all(is_constant(element) for element in value.elts)
    return False


def is_literal_display(value: ast.AST) -> bool:
    """Whether ``value`` is a constant, or a list, set, dict or tuple display of literals only."""
    if is_constant(value):
        return True
    if isinstance(value, (ast.List, ast.Set, ast.Tuple)):
        return all(is_literal_display(element) for element in value.elts)
    if isinstance(value, ast.Dict):
        return all(key is not None and is_literal_display(key) for key in value.keys) and all(
            is_literal_display(item) for item in value.values
        )
    return False


class LocalLiterals:
    """Follow a test file's names to the constant each holds, where only one can reach the use."""

    def __init__(self, files: TestFileIndex) -> None:
        self.files = files
        self._dynamic: set[int] = set()
        self._frames = False
        for name in files.names:
            # `__builtins__` reaches the same functions by key as well: `__builtins__["exec"](…)`.
            if name.id in DYNAMIC_CALLS or name.id == "__builtins__":
                self._mark_dynamic(name)
            elif name.id in FRAME_NAMES:
                self._frames = True
        for attribute in files.attributes:
            if (
                attribute.attr in DYNAMIC_CALLS
                and isinstance(attribute.value, ast.Name)
                and attribute.value.id in BUILTINS_MODULES
            ):
                self._mark_dynamic(attribute)
            if attribute.attr in FRAME_NAMES or (
                attribute.attr in INSPECT_FRAMES
                and isinstance(attribute.value, ast.Name)
                and attribute.value.id == "inspect"
            ):
                self._frames = True

    def _mark_dynamic(self, node: ast.AST) -> None:
        """Every function around ``node`` may have its locals written by name."""
        current: Scope | None = self.files.scope_of(node)
        while current is not None:
            if isinstance(current.node, (*FUNCTIONS, ast.Lambda)):
                self._dynamic.add(id(current.node))
            current = current.parent

    def is_dynamic(self, node: ast.AST) -> bool:
        """Whether the locals of the function ``node`` is read in may be written by name, out of sight."""
        function = self.files.function_of(node)
        return self._frames or (function is not None and id(function) in self._dynamic)

    def literal_of(self, name: ast.Name) -> ast.expr | None:
        """The constant ``name`` holds at this use, or None when it may hold anything else."""
        binding = self.single(name)
        value = None if binding is None or binding.kind != "assign" else binding.value
        return value if isinstance(value, ast.expr) and is_constant(value) else None

    def single(self, name: ast.Name) -> Binding | None:
        """The one binding of a function local that reaches ``name``, when nothing else may write it."""
        if self._frames:
            return None
        scope = self.files.scope_of(name)
        owner = self.files.owner_of(name.id, scope)
        if owner is None or owner.kind != "function" or id(owner.node) in self._dynamic:
            return None
        bindings = owner.bindings.get(name.id, [])
        if len(bindings) != 1:
            return None
        binding = bindings[0]
        if owner is scope:
            return binding if self.files.binding_of(name) is binding else None
        # Read from a nested function: only a binding the owner always runs, at the top of its body.
        return binding if binding.block == (id(owner.node), "body") else None
