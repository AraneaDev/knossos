"""The names one file writes on a project module or class that another file declares (G4-03).

The worker reads one file at a time, so a write such as ``config.FLAG = 1`` or
``Settings.debug = True`` from outside the target's own file adds a name its
complete list (``exports``, ``member_names``) never holds. Such a file's module
node names each write in ``foreign_writes``: the target's reference and the
name written, or no name for ``setattr`` with a name no literal spells. A core
opens the target's list on any of them. A write to an instance built right
there (``Far().x = 1``) is named with ``instance``: no core follows it, so it
leaves the list as it is and a finding resting on it a warning (U1). The
module node also names the patterns of :mod:`unfollowed_writes` it meets.

Only a target this file reaches through an import counts, a module of the
project or a class one of them declares, spelled as an attribute store
(``m.NAME = …``, ``m.Cls.NAME = …``, ``del``, ``+=``) or ``setattr``. Anything
else (a name the file binds itself, its own module, a module outside the
project, a class nested in another, a dunder) is not named. Each module the
writes resolve through is read here, so the writer's contribution names it
and a change to it rescans the writer.
"""

import ast
from typing import Any

from .ast_helpers import dotted, ref
from .class_members import is_dunder, scope_bindings, scope_nodes
from .module_index import ProjectModuleIndex
from .unfollowed_writes import runs_builtins_exec, writes_frames


class ForeignWrites:
    """Find the writes of one file whose targets another project file declares."""

    def __init__(self, tree: ast.Module, name: str, aliases: dict[str, str], index: ProjectModuleIndex) -> None:
        self.tree = tree
        self.own = ref("module", name)
        self.aliases = aliases
        self.index = index

    def attributes(self) -> dict[str, Any]:
        """``foreign_writes``, sorted, and the module's own ``unfollowed_writes``; nothing of either it has none of."""
        shadowed = "setattr" in scope_bindings(scope_nodes(self.tree.body))[0]
        writes: set[tuple[str, str, bool]] = set()
        written_self = False
        for node in ast.walk(self.tree):
            if isinstance(node, ast.Attribute) and isinstance(node.ctx, (ast.Store, ast.Del)):
                receiver, name = node.value, node.attr
            elif isinstance(node, ast.Call) and _is_setattr(node) and not shadowed:
                spelled = node.args[1].value if isinstance(node.args[1], ast.Constant) else None
                receiver, name = node.args[0], spelled if isinstance(spelled, str) else ""
            else:
                continue
            # `C().x = 1` writes an instance, which a core does not follow (U1, G4-02): it is named so.
            instance = isinstance(receiver, ast.Call)
            found = self.target(receiver.func if isinstance(receiver, ast.Call) else receiver)
            written_self = written_self or (found == self.own and not instance)
            if found is not None and found != self.own and not is_dunder(name):
                if not instance or found.startswith("py:class:"):
                    writes.add((found, name, instance))
        listed = [
            {"target": target} | ({"name": name} if name else {}) | ({"instance": True} if instance else {})
            for target, name, instance in sorted(writes)
        ]
        reasons = {
            "module_self_write": written_self,
            "builtins_exec": runs_builtins_exec(self.tree),
            "frame_write": writes_frames(self.tree),
        }
        unfollowed = sorted(reason for reason, met in reasons.items() if met)
        return ({"foreign_writes": listed} if listed else {}) | (
            {"unfollowed_writes": unfollowed} if unfollowed else {}
        )

    def target(self, receiver: ast.expr) -> str | None:
        """The project module or class that ``receiver`` names through an import, this file's own module included."""
        spelled = dotted(receiver)
        if spelled is None:
            return None
        first, *rest = spelled.split(".")
        found = self.aliases.get(first)
        while found is not None and found.startswith("py:module:") and found != self.own and rest:
            found = self.member(found.removeprefix("py:module:"), rest.pop(0))
        if found is None or rest:
            return None
        if found.startswith("py:module:") and found != self.own:
            return found if self.index.layout.module_file(found.removeprefix("py:module:")) is not None else None
        return found if found.startswith(("py:class:", "py:module:")) else None

    def member(self, module: str, name: str) -> str | None:
        """What ``<module>.<name>`` names in the project: a declaration of the module, or its submodule."""
        if self.index.layout.module_file(module) is None:
            return None
        declared = self.index.module_declarations(module).get(name)
        if declared is not None:
            return declared if declared.startswith(("py:module:", "py:class:")) else None
        submodule = f"{module}.{name}"
        if self.index.layout.module_file(submodule) is None:
            return None
        return ref("module", self.index.layout.canonical_module(submodule))


def _is_setattr(node: ast.Call) -> bool:
    return (
        isinstance(node.func, ast.Name)
        and node.func.id == "setattr"
        and len(node.args) >= 2
        and not any(isinstance(argument, ast.Starred) for argument in node.args[:2])
    )
