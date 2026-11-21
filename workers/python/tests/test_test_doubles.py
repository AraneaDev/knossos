"""A test file's unittest.mock, pytest-mock and monkeypatch doubles, as `test_double` nodes with their targets."""

from __future__ import annotations

import json
import os
import subprocess
import sys
from pathlib import Path
from typing import Any

import pytest

FIXTURES = Path(__file__).resolve().parents[3] / "tests" / "Fixtures" / "test-fidelity" / "python"

REPO = """\
class Repo:
    def save(self, item: int) -> int:
        return item

    def load(self, key: str) -> str:
        return key

    def price(self) -> int:
        return 1


def helper(value: int) -> int:
    return value
"""


SPECD = """\
from unittest.mock import Mock, patch
from repo import Repo


def test_it():
    specd = Mock(spec=Repo)
    specd.price.return_value = 3
    plain = Mock()
    plain.price.return_value = 3
    with patch("repo.Repo") as cls:
        cls.return_value.save.return_value = 1
    with patch("repo.Repo", autospec=True) as auto:
        auto.return_value.save.return_value = 1
    loose = Mock(spec=Repo)
    loose.extra = 1
    strict = Mock(spec_set=Repo)
    strict.extra = 1
"""


UNKNOWN_TARGETS = """\
from unittest.mock import patch
import repo
from repo import Repo


def test_it(monkeypatch):
    with patch.object(Repo, "added", create=True), patch("repo.open"), patch.object(repo, "print"):
        monkeypatch.setattr(Repo, "missing", 1, raising=False)
    with patch("repo.input", create=False):
        pass
"""


MATCHERS = """\
from unittest.mock import Mock, call
from repo import Repo


def test_it():
    m = Mock(spec=Repo)
    args = (1,)
    m.save.assert_called_once_with(*args)
    m.load.assert_any_call(**{"key": "k"})
    m.save.assert_has_calls([call(1), call(1, 2)])
    m.assert_has_calls([call.price(), call.load("k", key="k")])
"""


RECEIVERS = """\
from unittest.mock import create_autospec, patch
import repo
from repo import Repo


def test_it(mocker):
    instance = Repo()
    with patch.object(Repo, "save", autospec=True) as on_class:
        on_class.assert_called_once_with(instance, 1)
    with patch.object(instance, "save", autospec=True) as on_instance:
        on_instance.assert_called_once_with(1)
    spy = mocker.spy(Repo, "load")
    spy.assert_called_with(instance, "k")
    function = mocker.spy(repo, "helper")
    function.assert_called_with(1)
    create_autospec(Repo).save.assert_called_with(1)
"""


REPLACEMENTS = """\
from unittest.mock import patch
import repo
from repo import Repo


def fake(item: int) -> int:
    return 2


def test_it(monkeypatch):
    monkeypatch.setattr(Repo, "save", fake)
    monkeypatch.setattr(repo, "helper", fake)
    monkeypatch.setattr("repo.helper", lambda value, extra: 3)
    with patch.object(Repo, "load", autospec=True, side_effect=lambda self, key: "k"):
        pass
"""


PINNED = """\
from unittest.mock import MagicMock, patch
from repo import Repo


def test_it(x):
    with (
        patch("repo.helper", return_value='nope'),
        patch("repo.helper", return_value=42),
        patch("repo.helper", return_value=True),
        patch("repo.helper", return_value=None),
        patch("repo.helper", return_value=[1]),
        patch("repo.helper", return_value=Repo()),
        patch("repo.helper", return_value=x),
        patch("repo.helper", return_value=MagicMock()),
        patch("repo.helper", side_effect=[1, "a", ValueError("x")]),
        patch("repo.helper", side_effect=KeyError),
        patch("repo.helper", side_effect=lambda value: -1.5),
    ):
        pass
"""


DYNAMIC = """\
from unittest.mock import Mock, patch
from repo import Repo

MODULE = "repo"


def test_it(name, kw):
    with patch.object(Repo, name), patch(f"{MODULE}.helper"), patch.object(Repo, "__eq__"):
        pass
    m = Mock(spec=Repo)
    m.return_value = 1
    m.assert_called_once()
    m.call_count
    m.__enter__.return_value = m
    m.save.reset_mock()
    opened = Mock(spec=Repo)
    setattr(opened, name, 1)
    opened.price.return_value = 1
    configured = Mock(spec=Repo)
    configured.configure_mock(**kw)
    configured.load
"""


NAMED_PATCH = """\
from helpers import patch


def test_it():
    with patch("repo.Repo.save"):
        pass


def test_local():
    def Mock(spec):
        return spec

    Mock(spec=1)


def test_a_local_called_mocker():
    mocker = object()
    monkeypatch = object()
    mocker.patch("repo.helper")
    monkeypatch.setattr("repo.helper", 1)
"""


def doubles(contributions: list[dict], file: str) -> dict[str, tuple[dict[str, Any], str | None]]:
    """The doubles of ``file`` by `<line>:<col>`, each with the target of its `doubles` edge."""
    [contribution] = [item for item in contributions if item["owner_key"] == f"knossos.python:file:{file}"]
    edges = {edge["source"]: edge["target"] for edge in contribution["edges"] if edge["kind"] == "doubles"}
    found = {}
    for node in contribution["nodes"]:
        if node["kind"] == "test_double":
            position = node["canonical_name"].split("#double:")[1]
            found[position] = (node["attributes"], edges.get(node["local_id"]))
    return found


def scan_fixture(scan_collect, name: str) -> list[dict]:
    root = FIXTURES / name
    files = sorted(path.relative_to(root).as_posix() for path in root.rglob("*.py"))
    return scan_collect(root, files)


def scan_test(project, scan_collect, source: str, extra: dict[str, str] | None = None) -> dict:
    root = project({"repo.py": REPO, "tests/test_it.py": source, **(extra or {})})
    return doubles(scan_collect(root, ["repo.py", "tests/test_it.py", *(extra or {})]), "tests/test_it.py")


def members(attributes: dict[str, Any]) -> list[str]:
    return [member["name"] for member in attributes["members"]]


def member(attributes: dict[str, Any], name: str) -> dict[str, Any]:
    [found] = [item for item in attributes["members"] if item["name"] == name]
    return found


def test_patch_object_records_member_pin_and_edge(scan_collect) -> None:
    attributes, edge = doubles(scan_fixture(scan_collect, "nemesis"), "tests/test_auth.py")["9:10"]

    assert attributes["framework"] == "unittest"
    assert attributes["pattern"] == "unittest.mock.patch.object"
    # Ruling P2: `src/` is a source root, so the class is `services.AuthClient`.
    assert edge == "py:class:services.AuthClient"
    assert attributes["target"] == {"kind": "class", "export": "services.AuthClient", "member": "logn"}
    assert attributes["members"] == [
        {
            "name": "logn",
            "line": 9,
            "api": "patch.object",
            "returns": [
                {"api": "return_value", "line": 9, "once": False, "assignable": "unknown", "typed": True, "type": "str"}
            ],
        }
    ]


def test_a_module_attribute_is_judged_where_it_is_declared(scan_collect) -> None:
    found = doubles(scan_fixture(scan_collect, "nemesis-experiments/module-drift"), "tests/test_mailer.py")

    imported, edge = found["10:5"]
    assert edge == "py:module:postal.sender"
    assert imported["target"] == {"kind": "module", "specifier": "postal.mailer", "member": "deliver"}
    assert members(imported) == ["deliver"]
    assert imported["framework"] == "pytest-mock"
    assert imported["pattern"] == "pytest_mock.mocker.patch"
    missing, edge = found["6:5"]
    assert edge == "py:module:postal.mailer"
    assert members(missing) == ["dispatch"]


def test_src_layout_resolves(scan_collect) -> None:
    attributes, edge = doubles(scan_fixture(scan_collect, "momus-src-layout"), "tests/test_patch_missing.py")["5:10"]

    assert edge == "py:module:prod_missing"
    assert members(attributes) == ["nope"]


STACKED = """\
from unittest import mock
from unittest.mock import patch

REPLACEMENT = mock.Mock()


class TestStack:
    @patch("repo.Repo.save")
    @patch("repo.Repo.load", new=REPLACEMENT)
    @patch("repo.Repo.price")
    def test_stack(self, price, save):
        price.return_value = 1
        save.return_value = "s"
        price()
        save()
        REPLACEMENT()

    def test_with(self):
        with patch("repo.helper") as helper:
            helper()

    def test_start(self):
        patcher = patch.object(__import__("repo").Repo, "save")
        started = patcher.start()
        started()
        patcher.stop()


def test_mocker(mocker):
    made = mocker.patch("repo.helper")
    made()


@patch("repo.Repo.load")
class TestDecorated:
    def test_one(self, load):
        load()

    def helper(self):
        pass
"""


def test_patch_forms_bind_their_handle(project, scan_collect) -> None:
    found = scan_test(project, scan_collect, STACKED)

    # Decorators inject bottom-up, and the one given `new=` injects nothing.
    price, save = found["10:6"][0], found["8:6"][0]
    assert [pin["type"] for pin in member(price, "price")["returns"]] == ["int"]
    assert [pin["type"] for pin in member(save, "save")["returns"]] == ["str"]
    # The Mock passed as `new=` is the double of `load`; the patch itself is no second double.
    assert "9:6" not in found
    replacement, edge = found["4:15"]
    assert replacement["target"] == {"kind": "class", "export": "repo.Repo", "specifier": "repo", "member": "load"}
    assert edge == "py:class:repo.Repo"
    assert members(replacement) == ["load"]
    for position in ["10:6", "8:6", "4:15", "19:14", "30:12", "34:2"]:
        assert (position, found[position][0]["invocations"]) == (position, 1)
    # `patch.object(<call>, ...)` names no object this worker can resolve.
    assert found["23:19"][0]["target"] == {"kind": "unknown"}
    assert found["23:19"][0]["invocations"] == 1


def test_only_specd_handles_record_members(project, scan_collect) -> None:
    source = SPECD
    found = scan_test(project, scan_collect, source)

    assert members(found["6:13"][0]) == ["price"]
    assert member(found["6:13"][0], "price")["api"] == "attribute"
    assert found["8:13"][0]["target"] == {"kind": "unknown"}
    assert found["8:13"][0]["members"] == []
    # The patched name is a member; what its unspec'd mock hands back has none.
    assert members(found["10:10"][0]) == ["Repo"]
    assert (found["12:10"][0]["target"], found["12:10"][1]) == (
        {"kind": "class", "export": "repo.Repo", "specifier": "repo"},
        "py:class:repo.Repo",
    )
    assert members(found["12:10"][0]) == ["save"]
    assert found["12:10"][0]["automock"] is True
    assert members(found["14:13"][0]) == []
    assert member(found["16:14"][0], "extra")["api"] == "assigned"


def test_create_raising_and_builtins_give_no_target(project, scan_collect) -> None:
    source = UNKNOWN_TARGETS
    found = scan_test(project, scan_collect, source)

    for position in ["7:10", "7:52", "7:72", "8:9"]:
        assert (position, found[position][0]["target"], found[position][1]) == (position, {"kind": "unknown"}, None)
    assert members(found["7:10"][0]) == ["added"]
    assert found["8:9"][0]["framework"] == "pytest"
    assert found["8:9"][0]["pattern"] == "pytest.monkeypatch.setattr"
    assert found["9:10"][1] is None


def test_call_matchers_with_keywords_and_spread(project, scan_collect) -> None:
    kwargs = doubles(scan_fixture(scan_collect, "nemesis-experiments/kwargs"), "tests/test_session.py")["7:15"][0]
    assert member(kwargs, "open")["called_with"] == [
        {"api": "assert_called_with", "line": 8, "count": 2, "spread": False, "keywords": ["user", "token"]}
    ]

    source = MATCHERS
    found = scan_test(project, scan_collect, source)["6:9"][0]

    assert [(matcher["count"], matcher["spread"]) for matcher in member(found, "save")["called_with"]] == [
        (1, True),
        (1, False),
        (2, False),
    ]
    assert member(found, "load")["called_with"][0]["spread"] is True
    assert member(found, "price")["called_with"][0]["count"] == 0
    assert member(found, "load")["called_with"][1] == {
        "api": "assert_has_calls",
        "line": 11,
        "count": 2,
        "spread": False,
        "keywords": ["key"],
    }


def test_receiver_recording_forms_are_spread(project, scan_collect) -> None:
    source = RECEIVERS
    found = scan_test(project, scan_collect, source)

    def spread(position: str, name: str) -> bool:
        return member(found[position][0], name)["called_with"][0]["spread"]

    assert spread("8:10", "save") is True
    assert spread("10:10", "save") is False
    assert spread("12:11", "load") is True
    assert spread("14:16", "helper") is False
    assert spread("16:5", "save") is False
    assert found["12:11"][0]["automock"] is True


def test_side_effect_params(project, scan_collect) -> None:
    mailer = doubles(scan_fixture(scan_collect, "nemesis-experiments/side-effect"), "tests/test_mailer.py")["7:5"][0]
    assert member(mailer, "send")["params"] == [{"name": "to"}, {"name": "subject"}]

    source = REPLACEMENTS
    found = scan_test(project, scan_collect, source)

    assert "params" not in member(found["11:5"][0], "save")
    assert member(found["12:5"][0], "helper")["params"] == [{"name": "item", "type": "int"}]
    assert member(found["13:5"][0], "helper")["params"] == [{"name": "value"}, {"name": "extra"}]
    assert "params" not in member(found["14:10"][0], "load")
    # Each replacement callable pins the literal it returns.
    assert [pin["type"] for pin in member(found["11:5"][0], "save")["returns"]] == ["int"]
    assert [pin["type"] for pin in member(found["14:10"][0], "load")["returns"]] == ["str"]


def test_types_pinned_values(project, scan_collect) -> None:
    source = PINNED
    found = scan_test(project, scan_collect, source)

    def returns(line: int) -> list[tuple[str, bool]]:
        return [(pin["type"], pin["once"]) for pin in member(found[f"{line}:9"][0], "helper")["returns"]]

    assert [returns(line) for line in range(7, 15)] == [
        [("str", False)],
        [("int", False)],
        [("bool", False)],
        [("None", False)],
        [("list", False)],
        [("class:repo.Repo", False)],
        [("unknown", False)],
        [("unknown", False)],
    ]
    assert returns(15) == [("int", True), ("str", True)]
    assert returns(16) == []
    assert returns(17) == [("float", False)]
    assert member(found["13:9"][0], "helper")["returns"][0]["typed"] is False


def test_dynamic_names_and_mock_api_record_nothing(project, scan_collect) -> None:
    source = DYNAMIC
    found = scan_test(project, scan_collect, source)

    assert members(found["8:10"][0]) == []
    assert (found["8:36"][0]["target"], members(found["8:36"][0])) == ({"kind": "unknown"}, [])
    assert members(found["8:63"][0]) == []
    assert members(found["10:9"][0]) == ["save"]
    assert members(found["16:14"][0]) == []
    assert members(found["19:18"][0]) == []


def test_a_project_function_named_patch_is_no_double(project, scan_collect) -> None:
    source = NAMED_PATCH
    found = scan_test(project, scan_collect, source, {"helpers.py": "def patch(target):\n    return target\n"})

    assert found == {}


def test_a_file_outside_the_test_conventions_reports_no_double(project, scan_collect) -> None:
    root = project({"repo.py": REPO, "app/setup.py": "from unittest.mock import Mock\n\nMock()\n"})

    assert doubles(scan_collect(root, ["repo.py", "app/setup.py"]), "app/setup.py") == {}


def test_a_failed_reading_keeps_the_files_other_facts(project, scan_collect, monkeypatch) -> None:
    package = sys.modules["knossos_python.test_facts"]

    def broken(*_: object) -> None:
        raise RuntimeError("boom")

    monkeypatch.setattr(package, "test_facts", broken)
    root = project(
        {"repo.py": REPO, "tests/test_it.py": "from unittest.mock import Mock\n\ndef test_it():\n    Mock()\n"}
    )
    [contribution] = [item for item in scan_collect(root, ["tests/test_it.py"])]

    assert [node["kind"] for node in contribution["nodes"]] == ["function", "module"]
    [diagnostic] = contribution["diagnostics"]
    assert diagnostic["code"] == "PY_TEST_FACTS_FAILED"
    assert diagnostic["severity"] == "warning"
    assert "boom" in diagnostic["message"]


def test_output_is_stable_across_hash_seeds(tmp_path: Path) -> None:
    from conftest import WORKER_PATH

    outputs = []
    for seed in ("1", "2"):
        outputs.append(_scan_in_child(WORKER_PATH, FIXTURES / "healthy-idioms", seed))
    assert outputs[0] == outputs[1]
    assert '"kind": "test_double"' in outputs[0]


def _scan_in_child(worker: Path, root: Path, seed: str) -> str:
    files = sorted(path.relative_to(root).as_posix() for path in root.rglob("*.py"))
    request = {
        "jsonrpc": "2.0",
        "id": 1,
        "method": "scan",
        "params": {"root": str(root), "limits": {"max_files": 1000, "max_file_bytes": 2000000}, "files": files},
    }
    child = subprocess.run(
        [sys.executable, "-I", "-B", str(worker)],
        input=json.dumps(request) + "\n",
        capture_output=True,
        text=True,
        timeout=60,
        check=True,
        env={**os.environ, "PYTHONHASHSEED": seed},
    )
    frames = [json.loads(line) for line in child.stdout.splitlines()]
    return json.dumps([frame for frame in frames if frame.get("method") == "scan/contribution"], sort_keys=False)


@pytest.mark.parametrize("name", ["momus-drift", "nemesis", "nemesis-experiments/patch-multiple"])
def test_every_fixture_double_has_a_framework_and_members_list(scan_collect, name: str) -> None:
    for contribution in scan_fixture(scan_collect, name):
        for node in contribution["nodes"]:
            if node["kind"] == "test_double":
                assert node["attributes"]["framework"] in {"unittest", "pytest-mock", "pytest"}
                assert isinstance(node["attributes"]["members"], list)
