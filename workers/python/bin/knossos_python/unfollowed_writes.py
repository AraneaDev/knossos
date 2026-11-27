"""Writes this worker sees a module make but does not follow, named by the pattern each matches (U1).

``docs/languages/python.md`` lists writes that add a name a complete list
(``member_names``, ``exports``) never holds, or rewrite a def, which the user
chose on 2026-11-27 not to follow (G4-02, G4-05, G4-08). The list stays as it
is, but the node it belongs to names each pattern it meets in
``unfollowed_writes``, sorted, and a core reports a finding that rests on that
list as a warning only. On a class:

- ``self_alias``: a method binds its receiver to another name (``me = self``);
- ``instance_write``: an instance built right there is written (``C().x = 1``);
- ``nested_class_write``: a class nested in another is written (``O.I.x = 1``);
- ``default_holder``: a parameter default holds the class (``def f(k=C)``);
- ``rebound_builtin``: the class is handed to a call named like a read-only
  builtin that the module binds to something else;
- ``builtins_exec``: the module runs ``builtins.exec`` or ``builtins.eval``;
- ``assigned_frame_hack``: an assignment in the class body reaches a frame
  (``x = sys._getframe(0).f_locals…``).

On a module: ``module_self_write`` (it writes itself through an import of
itself), ``builtins_exec``, and ``frame_write``, code that reads a frame's
``f_locals``, which may write a caller's locals (Python 3.13 and later).
"""

import ast
from collections import Counter
from collections.abc import Iterable

from .ast_helpers import dotted

BUILTINS_EXEC = frozenset({"builtins.exec", "builtins.eval"})
"""``exec`` and ``eval`` under the spelling the module-level check by name does not catch."""

FRAME_NAMES = frozenset({"_getframe", "currentframe", "f_locals"})
"""Attributes that reach a frame, and through it a namespace's names."""


def runs_builtins_exec(tree: ast.Module) -> bool:
    """Whether the module calls ``builtins.exec`` or ``builtins.eval``."""
    return any(isinstance(node, ast.Call) and dotted(node.func) in BUILTINS_EXEC for node in ast.walk(tree))


def writes_frames(tree: ast.Module) -> bool:
    """Whether the module reads a frame's ``f_locals``, through which it may write a caller's locals."""
    return any(isinstance(node, ast.Attribute) and node.attr == "f_locals" for node in ast.walk(tree))


class UnfollowedWrites:
    """The patterns of :mod:`unfollowed_writes` each class of one module meets."""

    def __init__(self, tree: ast.Module, bindings: Counter[str], read_only: Iterable[str]) -> None:
        self.paths: dict[int, str] = {}
        self._path(tree.body, "")
        read_only = frozenset(read_only)
        self.stores: set[str] = set()
        self.instances: set[str] = set()
        self.defaults: set[str] = set()
        self.handed: set[str] = set()
        for node in ast.walk(tree):
            if isinstance(node, ast.Attribute) and isinstance(node.ctx, (ast.Store, ast.Del)):
                if isinstance(node.value, ast.Call):
                    self.instances.add(dotted(node.value.func) or "")
                self.stores.add(dotted(node.value) or "")
            elif isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef, ast.Lambda)):
                given = [*node.args.defaults, *node.args.kw_defaults]
                self.defaults |= {dotted(default) or "" for default in given if default is not None}
            elif isinstance(node, ast.Call) and isinstance(node.func, ast.Name) and node.func.id in read_only:
                if bindings[node.func.id] > 0:
                    arguments = [*node.args, *(keyword.value for keyword in node.keywords)]
                    self.handed |= {dotted(argument) or "" for argument in arguments}
        self.exec = runs_builtins_exec(tree)

    def _path(self, body: list[ast.stmt], prefix: str) -> None:
        """Each class of the module scope or nested in a class, by its dotted path."""
        for item in body:
            if isinstance(item, ast.ClassDef):
                path = f"{prefix}{item.name}"
                self.paths[id(item)] = path
                self._path(item.body, f"{path}.")

    def reasons(self, node: ast.ClassDef) -> list[str]:
        """The patterns the class meets, sorted."""
        path = self.paths.get(id(node), node.name)
        found = {
            "self_alias": _aliases_receiver(node),
            "instance_write": path in self.instances,
            "nested_class_write": "." in path and path in self.stores,
            "default_holder": path in self.defaults,
            "rebound_builtin": path in self.handed,
            "builtins_exec": self.exec,
            "assigned_frame_hack": _assigned_frame_hack(node),
        }
        return sorted(reason for reason, met in found.items() if met)


def _aliases_receiver(node: ast.ClassDef) -> bool:
    """Whether a method of the class binds its receiver to another name: ``me = self``."""
    for method in node.body:
        if not isinstance(method, (ast.FunctionDef, ast.AsyncFunctionDef)):
            continue
        positional = [*method.args.posonlyargs, *method.args.args]
        if not positional:
            continue
        receiver = positional[0].arg
        for child in ast.walk(method):
            if isinstance(child, (ast.Assign, ast.AnnAssign, ast.NamedExpr)):
                value = child.value
                if isinstance(value, ast.Name) and value.id == receiver:
                    return True
    return False


def _assigned_frame_hack(node: ast.ClassDef) -> bool:
    """Whether an assignment in the class body reaches a frame, which may write the class's names."""
    for item in node.body:
        if isinstance(item, (ast.Assign, ast.AnnAssign)) and item.value is not None:
            if any(isinstance(child, ast.Attribute) and child.attr in FRAME_NAMES for child in ast.walk(item.value)):
                return True
    return False
