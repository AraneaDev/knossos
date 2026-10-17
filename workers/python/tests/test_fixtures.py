"""Which conftest fixtures hand a test a module loaded by path, read from the syntax tree alone."""

from __future__ import annotations

import ast
import importlib
from pathlib import PurePosixPath
from types import ModuleType

CONFTEST = PurePosixPath("tests/conftest.py")


def fixtures_of(worker: ModuleType, source: str) -> dict[str, str]:
    # The worker puts its package on the path; the reader is one of its modules.
    del worker
    reader = importlib.import_module("knossos_python.fixtures")
    found = reader.path_loading_fixtures(ast.parse(source), CONFTEST)
    return {name: path.as_posix() for name, path in found.items()}


def test_a_fixture_returning_a_loader_names_the_file_the_loader_reads(worker: ModuleType) -> None:
    source = """
import importlib.util
from pathlib import Path
import pytest

PATH = Path(__file__).resolve().parents[1] / "bin" / "tool.py"

def _load():
    spec = importlib.util.spec_from_file_location("tool", PATH)
    return spec

@pytest.fixture(scope="session")
def tool():
    return _load()
"""
    assert fixtures_of(worker, source) == {"tool": "bin/tool.py"}


def test_a_fixture_that_loads_itself_under_the_name_it_is_given(worker: ModuleType) -> None:
    source = """
import importlib.util
from pathlib import Path
from pytest import fixture

@fixture(name="helper")
def _helper():
    spec = importlib.util.spec_from_file_location(name="h", location=Path(__file__).parent / "helper.py")
    return spec
"""
    assert fixtures_of(worker, source) == {"helper": "tests/helper.py"}


def test_paths_climb_with_parent_and_parents_and_keep_with_absolute(worker: ModuleType) -> None:
    source = """
import importlib.util
import pathlib
import pytest

@pytest.fixture
def near():
    return importlib.util.spec_from_file_location("a", pathlib.Path(__file__).absolute().parents[0] / "a.py")

@pytest.fixture
def root():
    return importlib.util.spec_from_file_location("b", pathlib.Path(__file__).parent.parent / "b.py")
"""
    assert fixtures_of(worker, source) == {"near": "tests/a.py", "root": "b.py"}


def test_nothing_is_named_for_a_path_that_cannot_be_told_or_leaves_the_project(worker: ModuleType) -> None:
    source = """
import importlib.util
import os
from pathlib import Path
import pytest

@pytest.fixture
def outside():
    return importlib.util.spec_from_file_location("o", Path(__file__).parents[5] / "o.py")

@pytest.fixture
def climbing():
    return importlib.util.spec_from_file_location("c", Path(__file__).parent / ".." / "c.py")

@pytest.fixture
def computed():
    return importlib.util.spec_from_file_location("x", os.environ["WHERE"])

@pytest.fixture
def indexed():
    return importlib.util.spec_from_file_location("i", Path(__file__).parents[True] / "i.py")

@pytest.fixture
def named():
    return importlib.util.spec_from_file_location("n", Path("elsewhere") / "n.py")

TIMEOUT = 3

def make():
    return 1

@pytest.fixture
def plain():
    return 1

@pytest.fixture
def unloaded():
    return make()

@pytest.fixture
def nowhere():
    return importlib.util.spec_from_file_location("w")

@pytest.fixture
def stringly():
    return importlib.util.spec_from_file_location("t", str(Path(__file__)))

@pytest.fixture
def silent():
    importlib.util.spec_from_file_location("s", Path(__file__).parent / "s.py")

def not_a_fixture():
    return importlib.util.spec_from_file_location("f", Path(__file__).parent / "f.py")

@pytest.mark.slow
def marked():
    return importlib.util.spec_from_file_location("m", Path(__file__).parent / "m.py")
"""
    assert fixtures_of(worker, source) == {}
