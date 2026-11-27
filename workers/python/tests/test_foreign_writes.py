"""A file names each write it makes on a project module or class another file declares (G4-03)."""

from __future__ import annotations

from typing import Any

PROJECT = {
    "config.py": "FLAG = 0\n\n\nclass Settings:\n    level = 1\n\n    class Inner:\n        pass\n",
    "pkg/__init__.py": "",
    "pkg/m.py": "NAME = 1\n",
}


def _scan(project, scan_collect, source: str, path: str = "main.py") -> dict[str, Any]:
    root = project({**PROJECT, path: source})
    [contribution] = [item for item in scan_collect(root, [path]) if item["owner_key"].endswith(path)]
    return contribution


def _writes(contribution: dict[str, Any]) -> list[dict[str, str]] | None:
    [module] = [node for node in contribution["nodes"] if node["kind"] == "module"]
    return module["attributes"].get("foreign_writes")


def test_a_module_global_written_from_another_file_is_named_with_its_module(project, scan_collect) -> None:
    contribution = _scan(project, scan_collect, "import config\n\n\ndef enable():\n    config.FLAG = 1\n")

    assert _writes(contribution) == [{"target": "py:module:config", "name": "FLAG"}]
    # Read for the write's sake, so a change to the target rescans the writer.
    assert "config.py" in contribution["reads"]


def test_a_submodule_imported_from_its_package_and_a_class_are_named(project, scan_collect) -> None:
    source = (
        "import config\nfrom config import Settings\nfrom pkg import m\n\n"
        "m.OTHER = 2\nSettings.debug = True\nconfig.Settings.verbose = 1\nconfig.FLAG += 1\n"
    )

    assert _writes(_scan(project, scan_collect, source)) == [
        {"target": "py:class:config.Settings", "name": "debug"},
        {"target": "py:class:config.Settings", "name": "verbose"},
        {"target": "py:module:config", "name": "FLAG"},
        {"target": "py:module:pkg.m", "name": "OTHER"},
    ]


def test_setattr_names_a_literal_and_leaves_a_dynamic_name_unnamed(project, scan_collect) -> None:
    source = "import config\nfrom config import Settings\n\n\ndef load(key):\n"
    source += '    setattr(config, "MODE", 1)\n    setattr(Settings, key, 1)\n'

    assert _writes(_scan(project, scan_collect, source)) == [
        {"target": "py:class:config.Settings"},
        {"target": "py:module:config", "name": "MODE"},
    ]


def test_writes_it_cannot_resolve_to_a_project_target_are_not_named(project, scan_collect) -> None:
    source = (
        "import os\nimport config\nimport main\n\n\nclass Own:\n    pass\n\n\n"
        "os.extra = 1\nthing.x = 1\nOwn.x = 1\nmain.x = 1\nconfig.Settings.Inner.x = 1\n"
        'config.__doc__ = ""\nconfig.missing.x = 1\n'
    )

    assert _writes(_scan(project, scan_collect, source)) is None


def test_a_file_without_foreign_writes_sends_none(project, scan_collect) -> None:
    assert _writes(_scan(project, scan_collect, "import config\n\nprint(config.FLAG)\n")) is None
