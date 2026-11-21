"""What a test runs of the code under test: its production calls and their subjects, fixtures included."""

from __future__ import annotations

from case_scans import operands, scan_cases


def test_counts_production_calls_and_subjects(project, scan_collect) -> None:
    source = """\
import json

from tests.helpers import build


@pytest.fixture
def stored():
    return Repo().save(1)


def make():
    return Repo()


def test_direct():
    Repo().save(1)


def test_through_names_and_helpers(stored):
    repo = make()
    repo.save(1)
    kept = Repo()
    kept.save(1)
    json.dumps({})
    build()
    len([])
    pytest.approx(1)
    mock.call(1)
    Mock().save()
    assert kept.save(1) == 1


class Base(TestCase):
    def made(self):
        return Repo()


class TestSetUp(Base):
    def setUp(self):
        self.repo = Repo()
        self.double = Mock()

    def own(self):
        return Repo()

    def test_set_up(self):
        self.inherited()
        self.assertTrue(True)
        self.own()
        self.made()
        self.repo.save(1)
        self.double()
"""
    helpers = "from app.repo import Repo\n\n\ndef build():\n    return Repo()\n"
    cases = scan_cases(project, scan_collect, source, {"tests/helpers.py": helpers, "tests/__init__.py": ""})

    assert (cases["test_direct"]["production_calls"], cases["test_direct"]["subjects"]) == (1, ["src/app/repo.py"])
    # make()'s Repo(), kept = Repo(), kept.save(), json.dumps(), the assertion's kept.save(), and the fixture's Repo().
    assert cases["test_through_names_and_helpers"]["production_calls"] == 6
    assert cases["test_through_names_and_helpers"]["subjects"] == ["src/app/repo.py"]
    assert operands(cases["test_through_names_and_helpers"])[0][0] == {"source": "production"}
    # setUp's Repo(), a method inherited from a base the file does not show, and own()'s and made()'s Repo().
    assert (cases["TestSetUp.test_set_up"]["production_calls"], cases["TestSetUp.test_set_up"]["subjects"]) == (
        4,
        ["src/app/repo.py"],
    )


def test_conftest_and_autouse_fixtures_count_for_their_tests(project, scan_collect) -> None:
    conftest = """\
import pytest
from app.repo import Repo


@pytest.fixture
def repo():
    return Repo()


@pytest.fixture(autouse=True)
def reset(repo):
    Repo().save(1)
"""
    source = """\
@pytest.fixture(autouse=True)
def local():
    Repo()


@pytest.mark.usefixtures("repo")
def test_it():
    pass
"""
    case = scan_cases(project, scan_collect, source, {"tests/conftest.py": conftest})["test_it"]

    # local(), usefixtures' repo(), and the conftest's autouse reset(), which requests repo() again.
    assert (case["production_calls"], case["subjects"]) == (4, ["src/app/repo.py"])


def test_an_unreadable_fixture_counts_as_production(project, scan_collect) -> None:
    source = "def test_it(client, tmp_path, mocker, monkeypatch):\n    pass\n"
    case = scan_cases(project, scan_collect, source)["test_it"]

    assert (case["production_calls"], case["subjects"]) == (1, [])


def test_a_path_loaded_fixture_names_its_file(project, scan_collect) -> None:
    conftest = """\
import importlib.util
from pathlib import Path

import pytest

TOOL = Path(__file__).resolve().parents[1] / "bin" / "tool.py"


@pytest.fixture
def tool():
    spec = importlib.util.spec_from_file_location("tool", TOOL)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module
"""
    files = {"tests/conftest.py": conftest, "bin/tool.py": "def run():\n    return 1\n"}
    case = scan_cases(project, scan_collect, "def test_it(tool):\n    assert tool.run() == 1\n", files)["test_it"]

    assert case["subjects"] == ["bin/tool.py"]
    assert case["production_calls"] == 3
