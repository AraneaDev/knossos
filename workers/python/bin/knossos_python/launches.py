"""Launches: the files an argv the code builds runs as a process."""

import ast
from collections.abc import Callable

from .accumulator import PythonFactAccumulator
from .ast_helpers import dotted, ref

# Programs that run the script named after them, by basename: an argv starting with one launches that script.
# BEGIN GENERATED worker-constants:launch_interpreters
INTERPRETERS = {"php", "node", "nodejs", "bun", "deno", "python", "python3", "ruby", "perl", "bash", "sh", "zsh"}
# END GENERATED worker-constants:launch_interpreters


class LaunchRecorder:
    """Record the file a built argv launches.

    The collector shares its facts and import aliases, and supplies the
    declaration the argv is built in (``current``).
    """

    def __init__(self, facts: PythonFactAccumulator, aliases: dict[str, str], current: Callable[[], str]) -> None:
        self.facts = facts
        self.aliases = aliases
        self.current = current

    def launched_file(self, values: list[ast.expr], node: ast.AST) -> None:
        """An argv the code builds: ``[sys.executable, ..., str(ROOT / "worker.py")]`` or
        ``["node", "js/worker.js"]``, or ``[str(ROOT) + "/bin/tool", ...]``.

        Its script (the first path after an interpreter) or its program (a path joined to a
        root in first place) is a file the process runs, so a speculative ``launches`` edge
        names it by path; the core resolves the path to that file's module and drops the edge
        when the project holds no such file.
        """
        if not values:
            return
        first = values[0]
        spelled = dotted(first) or ""
        head = spelled.split(".")[0]
        interpreter = (
            isinstance(first, ast.Constant)
            and isinstance(first.value, str)
            and first.value.rsplit("/", 1)[-1] in INTERPRETERS
        ) or (spelled.endswith(".executable") and self.aliases.get(head, head) in {"sys", "py:module:sys"})
        for value in values[1:] if interpreter else values[:1]:
            path = argv_path(value, program=not interpreter)
            if path is not None:
                attributes = {"speculative": True}
                self.facts.add_edge(
                    "launches", self.current(), ref("launched_file", path), node, attributes, confidence="probable"
                )
                return


def argv_path(value: ast.expr, program: bool) -> str | None:
    """The project-relative path an argv element names, when it reads as a path to a file.

    A string literal, or the literal parts a path is joined from: ``str(ROOT / "a" / "b.py")``,
    ``ROOT + "/bin/tool"``, ``f"{ROOT}/bin/tool"`` or ``os.path.join(ROOT, "bin", "tool")``. A
    program in first place must be joined to something, since a bare literal there is a command
    looked up on PATH; an option (``-I``, ``--flag``) is never a path, and a name with no
    directory is one only with a script's extension (``run.py``).
    """
    if isinstance(value, ast.Call) and dotted(value.func) == "str" and len(value.args) == 1:
        value = value.args[0]
    literal: str | None = None
    if isinstance(value, ast.Constant) and isinstance(value.value, str):
        literal = None if program else value.value
    elif isinstance(value, ast.BinOp) and isinstance(value.op, ast.Div):
        parts: list[str] = []
        while isinstance(value, ast.BinOp) and isinstance(value.op, ast.Div):
            if not (isinstance(value.right, ast.Constant) and isinstance(value.right.value, str)):
                break
            parts.append(value.right.value)
            value = value.left
        literal = "/".join(reversed(parts)) if parts else None
    elif isinstance(value, ast.BinOp) and isinstance(value.op, ast.Add):
        right = value.right
        literal = right.value if isinstance(right, ast.Constant) and isinstance(right.value, str) else None
    elif isinstance(value, ast.JoinedStr) and len(value.values) > 1:
        last = value.values[-1]
        literal = last.value if isinstance(last, ast.Constant) and isinstance(last.value, str) else None
    elif isinstance(value, ast.Call) and (dotted(value.func) or "").endswith("path.join"):
        tail: list[str] = []
        for argument in reversed(value.args):
            if not (isinstance(argument, ast.Constant) and isinstance(argument.value, str)):
                break
            tail.append(argument.value)
        literal = "/".join(reversed(tail)) if tail and len(tail) < len(value.args) else None
    if literal is None:
        return None
    path = literal.removeprefix("./").lstrip("/")
    script = path.endswith((".py", ".js", ".mjs", ".cjs", ".ts", ".php", ".rb", ".pl", ".sh"))
    if (
        path.startswith("-")
        or ("/" not in path and not script)
        or path.endswith("/")
        or any(c in path for c in " \t=:")
    ):
        return None
    return path
