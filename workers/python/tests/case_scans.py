"""What the test case tests share: a small project to scan, and readers of the `test_case` nodes it gives."""

from __future__ import annotations

from typing import Any

REPO = """\
class Repo:
    def save(self, item: int) -> int:
        return item
"""

HEADER = """\
import pytest
from unittest import TestCase, mock
from unittest.mock import AsyncMock, MagicMock, Mock

from app.repo import Repo

"""


def scan_nodes(project, scan_collect, files: dict[str, str], path: str = "tests/test_it.py") -> list[dict[str, Any]]:
    root = project({"src/app/__init__.py": "", "src/app/repo.py": REPO, **files})
    [contribution] = [item for item in scan_collect(root, sorted(files)) if item["owner_key"].endswith(path)]
    return [node for node in contribution["nodes"] if node["kind"] == "test_case"]


def scan_cases(project, scan_collect, source: str, extra: dict[str, str] | None = None) -> dict[str, dict[str, Any]]:
    """The test cases of ``tests/test_it.py`` by name, its source under the shared imports."""
    nodes = scan_nodes(project, scan_collect, {"tests/test_it.py": HEADER + source, **(extra or {})})
    return {node["attributes"]["name"]: node["attributes"] for node in nodes}


def operands(case: dict[str, Any]) -> list[list[dict[str, Any]]]:
    return [assertion["operands"] for assertion in case["assertions"]]
