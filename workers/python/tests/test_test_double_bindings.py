"""How a test reaches its doubles: setup attributes, fixtures, conftests, patch.multiple, new= and the mock backport."""

from __future__ import annotations

import ast
import sys
from pathlib import Path
from types import SimpleNamespace
from typing import Any

from test_test_doubles import FIXTURES, REPO, doubles, member, members, scan_fixture, scan_test

SETUP = """\
import unittest
from unittest import mock
from repo import Repo


class TestSetUp(unittest.TestCase):
    def setUp(self):
        self.repo = mock.Mock(spec=Repo)
        self.patcher = mock.patch("repo.helper")
        self.helper = self.patcher.start()

    def test_one(self):
        self.repo.price.return_value = 1
        self.helper.return_value = 2
        self.helper()


class TestRebound(unittest.TestCase):
    def setUp(self):
        self.repo = mock.Mock(spec=Repo)

    def test_one(self):
        self.repo = mock.Mock(spec=Repo)
        self.repo.load.return_value = "k"


class TestWrittenFirst(unittest.TestCase):
    def test_one(self):
        self.repo = None

    def setUp(self):
        self.repo = mock.Mock(spec=Repo)

    def test_two(self):
        self.repo.save
"""


def test_a_setup_attribute_binds_every_test_of_its_class(project, scan_collect) -> None:
    found = scan_test(project, scan_collect, SETUP)

    assert members(found["8:21"][0]) == ["price"]
    assert member(found["9:24"][0], "helper")["returns"][0]["type"] == "int"
    assert found["9:24"][0]["invocations"] == 1
    # Written twice in its class, `self.repo` holds neither double for certain.
    assert members(found["20:21"][0]) == []
    assert members(found["23:21"][0]) == []
    assert members(found["32:21"][0]) == []


FIXTURE = """\
import pytest
from unittest.mock import Mock
from repo import Repo


@pytest.fixture
def repo():
    return Mock(spec=Repo)


@pytest.fixture
def patched(mocker):
    yield mocker.patch("repo.helper")


@pytest.fixture(name="named")
def _named():
    made = Mock(spec=Repo)
    return made


def test_uses(repo, patched, named):
    repo.price.return_value = 1
    patched.return_value = 2
    named.save.return_value = 3
    patched()


@pytest.mark.parametrize("repo", [1])
def test_parametrized(repo):
    repo.load.return_value = "k"


def helper(repo):
    repo.load.return_value = "k"
"""


def test_a_fixture_that_returns_or_yields_a_double_binds_its_parameter(project, scan_collect) -> None:
    found = scan_test(project, scan_collect, FIXTURE)

    assert members(found["8:12"][0]) == ["price"]
    assert member(found["13:11"][0], "helper")["returns"][0]["type"] == "int"
    assert found["13:11"][0]["invocations"] == 2
    assert members(found["18:12"][0]) == ["save"]


CONFTEST = """\
import pytest
from unittest.mock import Mock


@pytest.fixture
def shared():
    return Mock()


@pytest.fixture
def plain():
    return 1
"""


def _bindings(root: Path, relative: str, source: str) -> tuple[Any, ast.Module]:
    package = sys.modules["knossos_python.test_facts"]
    from knossos_python.module_index import ProjectModuleIndex

    index = ProjectModuleIndex(root, 2_000_000)
    tree = ast.parse(source)
    files = package.file_index.TestFileIndex(tree)
    names = package.imports.NameResolver(index, files, relative, index.layout.module_for(relative))
    return package.doubles.TestDoubles(files, names, package.doubles.read_conftest).bindings, tree


def _parameter(tree: ast.Module, function: str, name: str) -> Any:
    [node] = [item for item in ast.walk(tree) if isinstance(item, ast.FunctionDef) and item.name == function]
    return next(argument for argument in node.args.args if argument.arg == name)


def test_a_conftest_fixture_names_its_double(project, worker) -> None:
    source = "import pytest\n\n\ndef test_it(shared, plain, local):\n    pass\n\n\n"
    source += "@pytest.fixture\ndef local():\n    return 1\n"
    root = project({"tests/conftest.py": CONFTEST, "tests/unit/test_it.py": source, "conftest.py": CONFTEST})
    bindings, tree = _bindings(root, "tests/unit/test_it.py", source)

    def foreign(name: str) -> str | None:
        argument = _parameter(tree, "test_it", name)
        [binding] = [item for item in bindings.files.scope_of(argument).bindings[name] if item.node is argument]
        return bindings.foreign_fixture(binding)

    assert foreign("shared") == "tests/conftest.py#double:7:12"
    assert foreign("plain") is None
    # This file's own fixture wins over any conftest's.
    assert foreign("local") is None
    # A root conftest is no test module: its doubles are no nodes to name.
    bindings, tree = _bindings(root, "test_top.py", "def test_it(shared):\n    pass\n")
    argument = _parameter(tree, "test_it", "shared")
    [binding] = bindings.files.scope_of(argument).bindings["shared"]
    assert bindings.foreign_fixture(binding) is None


def test_an_unreadable_conftest_names_no_double(project) -> None:
    source = "def test_it(shared):\n    pass\n"
    root = project(
        {"tests/conftest.py": "def broken(:\n", "tests/unit/conftest.py/keep": "", "tests/unit/test_it.py": source}
    )
    bindings, tree = _bindings(root, "tests/unit/test_it.py", source)
    argument = _parameter(tree, "test_it", "shared")
    [binding] = bindings.files.scope_of(argument).bindings["shared"]

    assert bindings.foreign_fixture(binding) is None


MULTIPLE = """\
import os
from unittest.mock import DEFAULT, PropertyMock, patch


@patch.multiple("repo.Repo", save=DEFAULT, load="fixed")
def test_multiple(save):
    save.return_value = 1
    save()


def test_dict_and_callables():
    with patch.dict("repo.CONFIG", {"a": 1}), patch.dict(os.environ, {"A": "1"}):
        pass
    with patch("repo.Repo.price", new_callable=PropertyMock, return_value=3) as price:
        price.return_value = 4
    with patch("repo.Repo.load", new_callable=list) as listed:
        listed.return_value = 5
"""


def test_patch_multiple_dict_and_new_callable(project, scan_collect) -> None:
    found = scan_test(project, scan_collect, MULTIPLE)

    multiple, edge = found["5:2"]
    assert (multiple["target"], edge) == (
        {"kind": "class", "export": "repo.Repo", "specifier": "repo"},
        "py:class:repo.Repo",
    )
    assert [(item["name"], item["api"]) for item in multiple["members"]] == [
        ("save", "patch.multiple"),
        ("load", "patch.multiple"),
    ]
    assert [pin["api"] for pin in member(multiple, "save")["returns"]] == ["return_value"]
    assert [pin["api"] for pin in member(multiple, "load")["returns"]] == ["new"]
    assert multiple["invocations"] == 1
    assert member(found["12:10"][0], "CONFIG")["api"] == "patch.dict"
    assert "12:47" not in found
    assert [pin["type"] for pin in member(found["14:10"][0], "price")["returns"]] == ["int", "int"]
    assert member(found["16:10"][0], "load")["returns"] == []


def test_patch_multiple_names_each_member(scan_collect) -> None:
    contributions = scan_fixture(scan_collect, "nemesis-experiments/patch-multiple")
    attributes, edge = doubles(contributions, "tests/test_vault.py")["6:5"]

    assert edge == "py:class:app.vault.Vault"
    assert members(attributes) == ["seal", "reseal"]


REPLACED = """\
import mock
from unittest.mock import Mock, mock_open, patch
from repo import Repo


def test_it(monkeypatch):
    with patch("builtins.open", mock_open(read_data="x")):
        pass
    stand_in = Mock(return_value=1)
    monkeypatch.setattr(Repo, "save", stand_in)
    stand_in()
    specd = Mock(spec=Repo)
    monkeypatch.setattr(Repo, "load", specd)
    backport = mock.Mock(spec=Repo)
    backport.price.return_value = 2
"""


def test_a_mock_given_as_new_is_the_double_of_the_member(project, scan_collect) -> None:
    found = scan_test(project, scan_collect, REPLACED)

    opened, edge = found["7:33"]
    assert (opened["target"], edge) == (
        {"kind": "module", "specifier": "builtins", "member": "open"},
        "py:external_symbol:builtins",
    )
    assert "7:10" not in found
    stand_in = found["9:16"][0]
    assert stand_in["target"] == {"kind": "class", "export": "repo.Repo", "member": "save"}
    assert [pin["type"] for pin in member(stand_in, "save")["returns"]] == ["int"]
    assert stand_in["invocations"] == 1
    # A spec'd double stays its own; the setattr is a second double with no pin.
    assert members(found["13:5"][0]) == ["load"]
    assert found["14:16"][0]["framework"] == "unittest"
    assert found["14:16"][0]["pattern"] == "unittest.mock.Mock"
    assert members(found["14:16"][0]) == ["price"]


CONFIGURED = """\
from unittest.mock import Mock
from repo import Repo


class Service:
    def __init__(self, repo):
        self.repo = repo


def test_it():
    literal = Mock(spec=Repo, **{"save.return_value": 3})
    literal.configure_mock(**{"load.side_effect": ["a", "b"]})
    strict = Mock(spec_set=Repo, price=5)
    loose = Mock(spec=Repo, extra=5)
    loose.extra.return_value = 1
    read = Mock(spec=Repo)
    getattr(read, "price")
    Service(Mock(spec=Repo))
    held = Mock()
    Service(held)
    items = [held]
    return held
"""


def test_literal_configuration_and_hand_ons(project, scan_collect) -> None:
    found = scan_test(project, scan_collect, CONFIGURED)

    literal = found["11:15"][0]
    assert [(item["name"], [pin["type"] for pin in item["returns"]]) for item in literal["members"]] == [
        ("save", ["int"]),
        ("load", ["str", "str"]),
    ]
    assert [(item["name"], item["api"]) for item in found["13:14"][0]["members"]] == [("price", "assigned")]
    assert members(found["14:13"][0]) == []
    assert members(found["16:12"][0]) == ["price"]
    assert found["18:13"][0]["invocations"] == 1
    assert found["19:12"][0]["invocations"] == 3


def test_every_copied_fixture_scans(scan_collect) -> None:
    for root in sorted(FIXTURES.glob("nemesis-experiments/*")):
        contributions = scan_fixture(scan_collect, f"nemesis-experiments/{root.name}")
        assert all("PY_TEST_FACTS_FAILED" not in str(item.get("diagnostics")) for item in contributions)


def test_the_repo_source_has_no_double() -> None:
    assert "Mock" not in REPO


def _records(root: Path, source: str) -> dict[int, Any]:
    package = sys.modules["knossos_python.test_facts"]
    from knossos_python.module_index import ProjectModuleIndex

    index = ProjectModuleIndex(root, 2_000_000)
    files = package.file_index.TestFileIndex(ast.parse(source))
    names = package.imports.NameResolver(index, files, "tests/test_it.py", "tests.test_it")
    doubles = package.doubles.TestDoubles(files, names, package.doubles.read_conftest)
    return {record.node.lineno: record for record in doubles.doubles()}


def test_a_pin_through_a_held_handle_is_marked_held(project) -> None:
    held = _records(project({"repo.py": REPO, "tests/test_it.py": SETUP}), SETUP)
    fixture = _records(project({"tests/test_fixture.py": FIXTURE}), FIXTURE)
    local = _records(project({"repo.py": REPO, "tests/test_local.py": CONFIGURED}), CONFIGURED)

    # Pinned through `self.` or a fixture, which code the file does not show may swap: the R14 analog.
    assert [(pin.member, pin.held) for pin in held[8].pins] == [("price", True)]
    assert [(pin.member, pin.held) for pin in held[9].pins] == [("helper", True)]
    assert [(pin.member, pin.held) for pin in fixture[8].pins] == [("price", True)]
    # A local handle, and a constructor keyword, pin a value the test itself set.
    assert [(pin.member, pin.held) for pin in local[11].pins] == [("save", False), ("load", False), ("load", False)]


def test_a_use_read_inside_another_restores_the_outer_handles_held_mark(worker) -> None:
    configuration = sys.modules["knossos_python.test_facts.configuration"].Configuration
    seen: list[bool] = []
    reader = configuration.__new__(configuration)
    reader._held = False
    outer, inner = SimpleNamespace(held=True), SimpleNamespace(held=False)

    def read(handle: Any, node: Any) -> None:
        seen.append(reader._held)
        if handle is outer:
            reader._use(inner, node)
            seen.append(reader._held)

    reader._read_use = read
    reader._use(outer, None)

    # G4-07: the inner use reads its own mark, and the outer one reads its own again after it.
    assert (seen, reader._held) == ([True, False, True], False)
