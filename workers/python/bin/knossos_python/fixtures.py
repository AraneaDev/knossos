"""The pytest fixtures a conftest.py defines that hand a test a module loaded by path.

A test suite can load the code it tests as a file rather than import it:

    WORKER_PATH = Path(__file__).resolve().parents[1] / "bin" / "worker.py"

    def _load():
        spec = importlib.util.spec_from_file_location("worker", WORKER_PATH)
        ...
        return module

    @pytest.fixture
    def worker():
        return _load()

A test that takes ``worker`` calls into ``bin/worker.py``, yet no import says
so. This module reads a conftest's syntax tree, statically and nothing more,
for such fixtures and the project path each one loads.
"""

from __future__ import annotations

import ast
from pathlib import PurePosixPath

from .ast_helpers import dotted


def path_loading_fixtures(tree: ast.Module, conftest: PurePosixPath) -> dict[str, PurePosixPath]:
    """Each fixture of ``tree`` that returns a module loaded by path, by the name a test takes it under.

    ``conftest`` is the conftest's project-relative path, which ``__file__``
    names. A path that cannot be worked out statically, or that climbs out of
    the project, loads nothing this can name.
    """
    paths: dict[str, PurePosixPath] = {}
    for statement in tree.body:
        if (
            isinstance(statement, ast.Assign)
            and len(statement.targets) == 1
            and isinstance(statement.targets[0], ast.Name)
        ):
            path = _path_of(statement.value, conftest, paths)
            if path is not None:
                paths[statement.targets[0].id] = path
    functions = [statement for statement in tree.body if isinstance(statement, (ast.FunctionDef, ast.AsyncFunctionDef))]
    loaders = {
        function.name: path for function in functions if (path := _loaded_path(function, conftest, paths)) is not None
    }
    fixtures: dict[str, PurePosixPath] = {}
    for function in functions:
        name = _fixture_name(function)
        if name is None:
            continue
        path = _loaded_path(function, conftest, paths) or _returned_loader(function, loaders)
        if path is not None:
            fixtures[name] = path
    return fixtures


def _fixture_name(function: ast.FunctionDef | ast.AsyncFunctionDef) -> str | None:
    """The name a test takes ``function`` under when ``@pytest.fixture`` or ``@fixture`` decorates it, else ``None``."""
    for decorator in function.decorator_list:
        call = decorator if isinstance(decorator, ast.Call) else None
        named = dotted(call.func if call is not None else decorator)
        if named not in {"pytest.fixture", "fixture"}:
            continue
        for keyword in call.keywords if call is not None else []:
            if (
                keyword.arg == "name"
                and isinstance(keyword.value, ast.Constant)
                and isinstance(keyword.value.value, str)
            ):
                return keyword.value.value
        return function.name
    return None


def _loaded_path(
    function: ast.FunctionDef | ast.AsyncFunctionDef, conftest: PurePosixPath, paths: dict[str, PurePosixPath]
) -> PurePosixPath | None:
    """The path ``function`` loads a module from with ``spec_from_file_location``, when it returns something."""
    if not any(isinstance(node, ast.Return) and node.value is not None for node in ast.walk(function)):
        return None
    for node in ast.walk(function):
        if isinstance(node, ast.Call) and (dotted(node.func) or "").rsplit(".", 1)[-1] == "spec_from_file_location":
            location = (
                node.args[1]
                if len(node.args) >= 2
                else next((k.value for k in node.keywords if k.arg == "location"), None)
            )
            if location is not None:
                return _path_of(location, conftest, paths)
    return None


def _returned_loader(
    function: ast.FunctionDef | ast.AsyncFunctionDef, loaders: dict[str, PurePosixPath]
) -> PurePosixPath | None:
    """The path a loader loads, when ``function`` returns that loader's result (``return _load()``)."""
    for node in ast.walk(function):
        if isinstance(node, ast.Return) and isinstance(node.value, ast.Call) and isinstance(node.value.func, ast.Name):
            if node.value.func.id in loaders:
                return loaders[node.value.func.id]
    return None


def _path_of(node: ast.expr, conftest: PurePosixPath, paths: dict[str, PurePosixPath]) -> PurePosixPath | None:
    """The project-relative path an expression built on ``__file__`` names, or ``None`` when it cannot be told.

    ``Path(__file__)`` is the conftest; ``.resolve()`` and ``.absolute()``
    keep it; ``.parent`` and ``.parents[n]`` climb; ``/ "name"`` descends;
    a module-level name assigned such a path is that path.
    """
    if isinstance(node, ast.Name):
        return paths.get(node.id)
    if isinstance(node, ast.Call):
        if dotted(node.func) in {"Path", "pathlib.Path"} and len(node.args) == 1:
            argument = node.args[0]
            return conftest if isinstance(argument, ast.Name) and argument.id == "__file__" else None
        if isinstance(node.func, ast.Attribute) and node.func.attr in {"resolve", "absolute"} and not node.args:
            return _path_of(node.func.value, conftest, paths)
        return None
    if isinstance(node, ast.Attribute) and node.attr == "parent":
        inner = _path_of(node.value, conftest, paths)
        return _climb(inner, 1)
    if isinstance(node, ast.Subscript) and isinstance(node.value, ast.Attribute) and node.value.attr == "parents":
        index = node.slice
        if (
            isinstance(index, ast.Constant)
            and isinstance(index.value, int)
            and not isinstance(index.value, bool)
            and index.value >= 0
        ):
            return _climb(_path_of(node.value.value, conftest, paths), index.value + 1)
        return None
    if isinstance(node, ast.BinOp) and isinstance(node.op, ast.Div):
        inner = _path_of(node.left, conftest, paths)
        if inner is None or not isinstance(node.right, ast.Constant) or not isinstance(node.right.value, str):
            return None
        joined = inner / node.right.value
        return None if ".." in joined.parts or joined.is_absolute() else joined
    return None


def _climb(path: PurePosixPath | None, levels: int) -> PurePosixPath | None:
    """``path`` ``levels`` directories up; ``None`` past the project root."""
    if path is None or levels > len(path.parts):
        return None
    return PurePosixPath(*path.parts[: len(path.parts) - levels]) if levels < len(path.parts) else PurePosixPath(".")
