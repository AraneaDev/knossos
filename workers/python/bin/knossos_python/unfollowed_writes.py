"""Writes this worker sees a module make but does not follow, named by the pattern each matches (U1).

``docs/languages/python.md`` lists writes that add a name a complete list
(``member_names``, ``exports``) never holds, or rewrite a def, which the user
chose on 2026-11-27 not to follow (G4-02, G4-05, G4-08). The list stays as it
is, but the node it belongs to names each pattern it meets in
``unfollowed_writes``, sorted, and a core reports a finding that rests on that
list as a warning only. On a class:

- ``self_alias``: a method writes through another name it binds its receiver
  to (``me = self; me.x = 1``);
- ``instance_write``: an instance built right there is written (``C().x = 1``);
- ``nested_class_write``: a class nested in another is written (``O.I.x = 1``);
- ``default_holder``: a function writes through a parameter whose default
  holds the class (``def f(k=C): k.x = 1``);
- ``rebound_builtin``: the class is handed to a call named like a read-only
  builtin that the module binds to something else;
- ``builtins_exec``: the module runs ``builtins.exec`` or ``builtins.eval``;
- ``assigned_frame_hack``: an assignment in the class body reaches a frame
  (``x = sys._getframe(0).f_locals…``).

On a module: ``module_self_write`` (it writes itself through an import of
itself), ``builtins_exec``, and ``frame_write``, code that may write a
frame's ``f_locals``, and so a caller's locals (Python 3.13 and later).
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


LOCALS_MUTATORS = frozenset({"update", "setdefault", "pop", "popitem", "clear", "__setitem__", "__delitem__"})
"""The methods of a frame's ``f_locals`` that write it."""


def writes_frames(tree: ast.Module) -> bool:
    """Whether the module may write a frame's ``f_locals``, and through it a caller's locals.

    A store or ``del`` through a subscript of it, a call of a method that
    writes it, or the mapping bound to a name, handed to a call or returned,
    where it may be written out of sight. A read (``f_locals["x"]``,
    ``f_locals.get("x")``) writes nothing.
    """
    parents = {id(child): node for node in ast.walk(tree) for child in ast.iter_child_nodes(node)}
    for node in ast.walk(tree):
        if not (isinstance(node, ast.Attribute) and node.attr == "f_locals"):
            continue
        parent = parents.get(id(node))
        if isinstance(parent, ast.keyword):
            parent = parents.get(id(parent))
        if isinstance(parent, ast.Subscript) and isinstance(parent.ctx, (ast.Store, ast.Del)):
            return True
        if isinstance(parent, ast.Attribute) and parent.attr in LOCALS_MUTATORS:
            return True
        if isinstance(parent, (ast.Assign, ast.AnnAssign, ast.NamedExpr, ast.Return)) and parent.value is node:
            return True
        if isinstance(parent, ast.Call) and parent.func is not node:
            return True
    return False


WRITING_BUILTINS = frozenset({"setattr", "delattr"})
"""The builtins that write an attribute of what they are handed first."""


def _writes_through(function: ast.AST, names: set[str]) -> bool:
    """Whether ``function`` writes an attribute through one of ``names``: a store, ``del``, ``setattr``."""
    for child in ast.walk(function):
        if isinstance(child, ast.Attribute) and isinstance(child.ctx, (ast.Store, ast.Del)):
            if isinstance(child.value, ast.Name) and child.value.id in names:
                return True
        elif (
            isinstance(child, ast.Call) and isinstance(child.func, ast.Name) and child.func.id in ("setattr", "delattr")
        ):
            if child.args and isinstance(child.args[0], ast.Name) and child.args[0].id in names:
                return True
    return False


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
                self.defaults |= _defaults_written(node)
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


def _defaults_written(function: ast.FunctionDef | ast.AsyncFunctionDef | ast.Lambda) -> set[str]:
    """The holders a parameter default names whose parameter the function writes through (``def f(k=C): k.x = 1``)."""
    positional = [*function.args.posonlyargs, *function.args.args]
    pairs: list[tuple[ast.arg, ast.expr | None]] = list(
        zip(positional[len(positional) - len(function.args.defaults) :], function.args.defaults, strict=True)
    )
    pairs += [(arg, default) for arg, default in zip(function.args.kwonlyargs, function.args.kw_defaults, strict=True)]
    return {
        dotted(default) or "" for arg, default in pairs if default is not None and _writes_through(function, {arg.arg})
    }


def _aliases_receiver(node: ast.ClassDef) -> bool:
    """Whether a method of the class writes through another name it binds its receiver to: ``me = self; me.x = 1``."""
    for method in node.body:
        if not isinstance(method, (ast.FunctionDef, ast.AsyncFunctionDef)):
            continue
        positional = [*method.args.posonlyargs, *method.args.args]
        if not positional:
            continue
        receiver = positional[0].arg
        aliases: set[str] = set()
        for child in ast.walk(method):
            if isinstance(child, (ast.Assign, ast.AnnAssign, ast.NamedExpr)):
                value = child.value
                if isinstance(value, ast.Name) and value.id == receiver:
                    targets = child.targets if isinstance(child, ast.Assign) else [child.target]
                    aliases |= {target.id for target in targets if isinstance(target, ast.Name)}
        if aliases - {receiver} and _writes_through(method, aliases - {receiver}):
            return True
    return False


def _assigned_frame_hack(node: ast.ClassDef) -> bool:
    """Whether an assignment in the class body reaches a frame, which may write the class's names."""
    for item in node.body:
        if isinstance(item, (ast.Assign, ast.AnnAssign)) and item.value is not None:
            if any(isinstance(child, ast.Attribute) and child.attr in FRAME_NAMES for child in ast.walk(item.value)):
                return True
    return False
