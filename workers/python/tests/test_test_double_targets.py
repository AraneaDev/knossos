"""What a double's target resolves to, and when a name is bound to a double for certain."""

from __future__ import annotations

from test_test_doubles import doubles, member, members, scan_test

PACKAGE = {
    "pkg/__init__.py": "",
    "pkg/sub.py": "def run() -> int:\n    return 1\n",
}

OBJECTS = """\
import os
import pkg.sub
import repo
from unittest.mock import patch
from repo import Repo


def handed(value):
    return value


def test_it():
    with patch.object(repo.Repo, "save"), patch.object(repo, "helper"), patch.object(pkg.sub, "run"):
        pass
    kept = Repo()
    with patch.object(kept, "save"):
        kept.load("k")
    given = Repo()
    handed(given)
    with patch.object(given, "save"):
        pass
    written = Repo()
    written.extra = 1
    with patch.object(written, "extra"):
        pass
    with patch.object(os.path, "exists"), patch("os.getcwd"), patch.dict("os.environ", {"A": "1"}):
        pass
"""


def test_an_object_resolves_to_a_class_a_module_an_instance_or_outside(project, scan_collect) -> None:
    found = scan_test(project, scan_collect, OBJECTS, PACKAGE)

    assert found["13:10"][1] == "py:class:repo.Repo"
    assert found["13:43"][1] == "py:module:repo"
    assert found["13:73"][1] == "py:module:pkg.sub"
    assert found["16:10"][0]["target"] == {"kind": "instance-member", "export": "repo.Repo", "member": "save"}
    assert found["16:10"][1] == "py:class:repo.Repo"
    # Handed on or written, an instance may have names its class does not list.
    assert found["20:10"][0]["target"] == {"kind": "unknown"}
    assert found["24:10"][0]["target"] == {"kind": "unknown"}
    assert (found["26:10"][0]["target"], found["26:10"][1]) == (
        {"kind": "module", "specifier": "os.path", "member": "exists"},
        "py:external_symbol:os.path",
    )
    assert found["26:43"][1] == "py:external_symbol:os"
    assert found["26:63"][0]["target"] == {"kind": "module", "specifier": "os", "member": "environ"}


SCOPES = """\
from unittest.mock import Mock
from repo import Repo


def test_branches(flag):
    if flag:
        m = Mock(spec=Repo)
        m.save
    else:
        m = Mock(spec=Repo)
        m.load
    m.price


def test_rebound():
    m = Mock(spec=Repo)
    m.save
    m = object()
    m.load
    n: Mock = Mock(spec=Repo)
    n.price


shared = Mock(spec=Repo)
swapped = Mock(spec=Repo)


def test_global():
    global shared
    shared.save


def reset():
    global swapped
    swapped = None


def test_swapped():
    swapped.load


def test_closure():
    outer = Mock(spec=Repo)

    def inner():
        outer.load

    inner()
    callback = lambda outer: outer.price
    try:
        pass
    except ValueError as outer:
        pass


def test_match(value):
    held = Mock(spec=Repo)
    match value:
        case {"a": 1, **held}:
            pass
        case [*rest] if rest:
            pass
    held.save
"""


def test_a_name_holds_a_double_only_where_its_binding_is_certain(project, scan_collect) -> None:
    found = scan_test(project, scan_collect, SCOPES)

    # Bound in two branches, `m` is certain in neither: the walk does not follow control flow.
    assert members(found["7:13"][0]) == []
    assert members(found["10:13"][0]) == []
    assert members(found["16:9"][0]) == ["save"]
    assert members(found["20:15"][0]) == ["price"]
    # `global shared` hands the name to code this walk does not follow.
    assert members(found["24:10"][0]) == []
    assert members(found["25:11"][0]) == []
    # Rebound in its own scope by an `except ... as`, `outer` is no longer certain.
    assert members(found["43:13"][0]) == []
    assert members(found["57:12"][0]) == []


SPECS = """\
from unittest.mock import Mock, create_autospec, patch
import repo
from repo import Repo, helper


def test_it(mocker, kw):
    function = create_autospec(helper)
    function.return_value = 1
    listed = Mock(spec=["save"])
    listed.save
    spread = Mock(Repo, None)
    spread.save
    with patch.multiple("repo", helper=7, open=1), patch.multiple("os", getcwd=2):
        pass
    with patch.multiple(Repo, create=True, x=1):
        pass
    with patch.multiple(Repo, **kw), patch.multiple(repo, autospec=True, helper=mocker.DEFAULT):
        pass
    mocker.resetall()
    mocker.patch.object(Repo, "save", spec_set=True)
"""


def test_specs_and_patch_multiple_owners(project, scan_collect) -> None:
    found = scan_test(project, scan_collect, SPECS)

    function, edge = found["7:16"]
    assert (function["target"], edge) == ({"kind": "module", "specifier": "repo", "member": "helper"}, "py:module:repo")
    assert [(item["name"], [pin["type"] for pin in item["returns"]]) for item in function["members"]] == [
        ("helper", ["int"])
    ]
    assert (found["9:14"][0]["target"], members(found["9:14"][0])) == ({"kind": "unknown"}, ["save"])
    assert members(found["11:14"][0]) == []
    assert (found["13:10"][1], members(found["13:10"][0])) == ("py:module:repo", ["helper"])
    assert found["13:52"][1] == "py:external_symbol:os"
    assert (found["15:10"][0]["target"], members(found["15:10"][0])) == ({"kind": "unknown"}, ["x"])
    assert found["17:10"][0]["members"] == []
    assert found["17:38"][0]["automock"] is True
    assert len(found) == 9


SETUPS = """\
import pytest
from unittest import mock
from repo import Repo


class TestDynamic:
    def setup_method(self):
        self.repo = mock.Mock(spec=Repo)
        setattr(self, "other", 1)

    def test_one(self):
        self.repo.save


class TestNoSetup:
    def test_one(self):
        self.repo.save

    @staticmethod
    def test_static(repo):
        repo.save


@pytest.mark.parametrize(["repo"], [(1,)])
def test_listed(repo):
    repo.save


@pytest.mark.parametrize(("other", "repo"), [(1, 2)])
def test_tupled(repo):
    repo.save


@pytest.mark.parametrize(names(), [(1,)])
def test_computed(repo):
    repo.save


@pytest.fixture
def repo():
    return mock.Mock(spec=Repo)


def names():
    return "repo"
"""


def test_setup_writes_and_parametrize_forms(project, scan_collect) -> None:
    found = scan_test(project, scan_collect, SETUPS)

    # A `setattr` on the receiver may write any name, so no setup attribute is certain.
    assert members(found["8:21"][0]) == []
    assert members(found["41:12"][0]) == ["save"]


CONFIGURATION = """\
from unittest.mock import Mock
from repo import Repo


def test_it():
    m = Mock(spec=Repo)
    m.configure_mock(**{"save.side_effect": KeyError, "load.extra": 1})
    m.price.side_effect = Exception("x")
    strict = Mock(spec_set=Repo)
    setattr(strict, "added", 1)
    setattr(m, "hidden", 1)
    m.hidden
    pinned = Mock(spec=Repo, return_value=-1)
    pinned.save.return_value = int("3")
    pinned.load.return_value = sorted([])
    pinned.price.return_value = ...
    pinned.save.side_effect = [*range(3)]
    obj = object()
    obj.attr: Mock = Mock()
    handed(*[pinned])


def handed(*values):
    return values
"""


def test_configuration_through_the_api_and_builtins(project, scan_collect) -> None:
    found = scan_test(project, scan_collect, CONFIGURATION)

    m = found["6:9"][0]
    assert members(m) == ["save", "load", "price"]
    assert member(m, "save")["returns"] == []
    assert member(m, "price")["returns"] == []
    assert [(item["name"], item["api"]) for item in found["9:14"][0]["members"]] == [("added", "assigned")]
    pinned = found["13:14"][0]
    assert [(item["name"], [pin["type"] for pin in item["returns"]]) for item in pinned["members"]] == [
        ("save", ["int"]),
        ("load", ["unknown"]),
        ("price", ["unknown"]),
    ]
    assert pinned["invocations"] == 1
    assert found["19:22"][0]["invocations"] == 1


def test_a_relative_import_past_the_top_names_nothing(project, scan_collect) -> None:
    source = "from ... import mock\n\n\ndef test_it():\n    mock.Mock()\n"
    root = project({"tests/test_it.py": source})

    assert doubles(scan_collect(root, ["tests/test_it.py"]), "tests/test_it.py") == {}
