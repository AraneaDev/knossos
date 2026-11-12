"""A bare import a ``sys.path`` edit makes loadable resolves to the module it loads."""

from __future__ import annotations

from types import ModuleType
from typing import Any


def edges(contributions: list[dict], kind: str) -> list[tuple[str, str]]:
    return [
        (edge["source"], edge["target"])
        for contribution in contributions
        for edge in contribution["edges"]
        if edge["kind"] == kind
    ]


def test_an_import_from_a_directory_a_sys_path_edit_adds_resolves(
    worker: ModuleType, project: Any, scan_collect: Any
) -> None:
    # `examples/mcp/prepare.py` puts `examples/` on the path, then imports
    # `_client_input` from it; `examples/plugins/prepare.py` does the same
    # through `os.path`. Both read as importing a module nothing declares.
    prepare = (
        "import sys\n"
        "from pathlib import Path\n"
        "sys.path.insert(0, str(Path(__file__).resolve().parents[1]))\n"
        "from _client_input import open_client\n"
        "if __name__ == '__main__':\n"
        "    open_client()\n"
    )
    plugins = (
        "import os, sys\n"
        "sys.path.append(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))\n"
        "import _client_input\n"
        "_client_input.open_client()\n"
    )
    root = project(
        {
            "examples/_client_input.py": "def open_client():\n    return 1\n",
            "examples/mcp/prepare.py": prepare,
            "examples/plugins/prepare.py": plugins,
        }
    )
    contributions = scan_collect(
        root, ["examples/_client_input.py", "examples/mcp/prepare.py", "examples/plugins/prepare.py"]
    )
    imports = edges(contributions, "imports")
    calls = edges(contributions, "calls")

    assert ("py:module:examples.mcp.prepare", "py:module:examples._client_input") in imports
    assert ("py:module:examples.plugins.prepare", "py:module:examples._client_input") in imports
    assert ("py:module:examples.mcp.prepare", "py:function:examples._client_input.open_client") in calls
    assert ("py:module:examples.plugins.prepare", "py:function:examples._client_input.open_client") in calls


def test_a_sys_path_edit_that_climbs_out_of_the_project_adds_nothing(worker: ModuleType) -> None:
    import ast

    tree = ast.parse("import sys\nfrom pathlib import Path\nsys.path.insert(0, str(Path(__file__).parents[3]))\n")
    assert worker_directories(tree, "tools/run.py") == []
    tree = ast.parse("import sys\nfrom pathlib import Path\nsys.path.insert(0, str(Path(__file__).parent / 'lib'))\n")
    assert [str(path) for path in worker_directories(tree, "tools/run.py")] == ["tools/lib"]


def worker_directories(tree: Any, relative: str) -> list:
    from knossos_python.collector import sys_path_directories

    return sys_path_directories(tree, relative)
