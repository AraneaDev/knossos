"""Decision 5's single-binding rule: a local is a literal operand only when nothing may write it but its one binding.

Each write below once gave a definite "this assertion cannot fail" on a
healthy PHP test (phase 3): every one of them must leave the operand
unknown, and the core then silent.
"""

from __future__ import annotations

from typing import Any

import pytest

REPO = """\
class Repo:
    def save(self, item: int) -> int:
        return item
"""

CONSTANTS = "LIMIT = 5\n"

HEADER = """\
import inspect
import json
import sys
import types
from unittest.mock import Mock

import pytest

from app import constants
from app.constants import LIMIT
from app.repo import Repo

"""


def scan_cases(project, scan_collect, source: str) -> dict[str, dict[str, Any]]:
    """The test cases of ``tests/test_it.py``, by name, scanned beside a small ``src/app``."""
    files = {
        "src/app/__init__.py": "",
        "src/app/repo.py": REPO,
        "src/app/constants.py": CONSTANTS,
        "tests/test_it.py": HEADER + source,
    }
    root = project(files)
    found: dict[str, dict[str, Any]] = {}
    for contribution in scan_collect(root, sorted(files)):
        for node in contribution["nodes"]:
            if node["kind"] == "test_case":
                found[node["attributes"]["name"]] = node["attributes"]
        assert not [d for d in contribution.get("diagnostics", []) if d["code"] == "PY_TEST_FACTS_FAILED"]
    return found


def last_operands(cases: dict[str, dict[str, Any]], name: str = "test_it") -> list[dict[str, Any]]:
    return cases[name]["assertions"][-1]["operands"]


def test_a_single_immutable_binding_is_literal(project, scan_collect) -> None:
    source = """\
def test_it():
    expected = 5
    pair = (1, -2.5, "a", None)
    assert expected == 5
    assert pair == (1, -2.5, "a", None)
    with open("f"):
        inside = "x"
        assert inside == "x"

    def nested():
        assert expected == 5

    nested()
"""
    assertions = scan_cases(project, scan_collect, source)["test_it"]["assertions"]

    assert [assertion["operands"] for assertion in assertions] == [
        [{"source": "literal", "text": "5"}, {"source": "literal", "text": "5"}],
        [
            {"source": "literal", "text": "(1, -2.5, 'a', None)"},
            {"source": "literal", "text": "(1, -2.5, 'a', None)"},
        ],
        [{"source": "literal", "text": "'x'"}, {"source": "literal", "text": "'x'"}],
        [{"source": "literal", "text": "5"}, {"source": "literal", "text": "5"}],
    ]


WRITES = {
    "bound twice": "    x = 5\n    x = 5\n",
    "bound under a branch": "    if sys.argv:\n        x = 5\n",
    "bound in a try body read after it": "    try:\n        x = 5\n    finally:\n        pass\n",
    "nonlocal in a nested function": (
        "    x = 5\n\n    def bump():\n        nonlocal x\n        x = 6\n\n    bump()\n"
    ),
    "nonlocal two scopes down": (
        "    x = 5\n\n    def outer():\n        def inner():\n            nonlocal x\n            x = 6\n\n"
        "        inner()\n\n    outer()\n"
    ),
    "global in the test": "    global x\n    x = 5\n",
    "global in a nested function": "    x = 5\n\n    def bump():\n        global x\n        x = 6\n\n    bump()\n",
    "nonlocal in a side_effect closure": (
        "    x = 5\n\n    def bump(*_):\n        nonlocal x\n        x += 1\n\n    Mock(side_effect=bump)()\n"
    ),
    "nonlocal in a wraps function": (
        "    x = 5\n\n    def bump(*_):\n        nonlocal x\n        x = 6\n\n    Mock(wraps=bump)()\n"
    ),
    "augmented assignment": "    x = 5\n    x += 1\n",
    "walrus": "    x = 5\n    if (x := 6):\n        pass\n",
    "walrus inside a comprehension": "    x = 5\n    [x := item for item in (1, 2)]\n",
    "for target": "    x = 5\n    for x in (6,):\n        pass\n",
    "with as": "    x = 5\n    with open('f') as x:\n        pass\n",
    "except as": "    x = 5\n    try:\n        pass\n    except ValueError as x:\n        pass\n",
    "match capture": "    x = 5\n    match 6:\n        case x:\n            pass\n",
    "match star capture": "    x = 5\n    match [6]:\n        case [*x]:\n            pass\n",
    "comprehension target": "    x = 5\n    [x for x in (6,)]\n",
    "tuple unpacking": "    x = 5\n    x, y = 6, 7\n",
    "star unpacking": "    x = 5\n    *x, y = 6, 7\n",
    "nested unpacking": "    x = 5\n    (a, (x, b)) = (1, (6, 2))\n",
    "del": "    x = 5\n    del x\n    x = 5\n",
    "an import": "    x = 5\n    import json as x\n",
    "exec": "    x = 5\n    exec('x = 6')\n",
    "eval": "    x = 5\n    eval('print(x)')\n",
    "locals()": "    x = 5\n    locals()['x'] = 6\n",
    "globals()": "    x = 5\n    globals()\n",
    "vars()": "    x = 5\n    vars()\n",
    "exec in a nested function": "    x = 5\n\n    def run():\n        exec('pass')\n\n    run()\n",
    "sys._getframe": "    x = 5\n    sys._getframe().f_locals['x'] = 6\n",
    "inspect.currentframe": "    x = 5\n    inspect.currentframe().f_locals['x'] = 6\n",
    "inspect.stack": "    x = 5\n    inspect.stack()\n",
    "a frame function imported by name": "    from sys import _getframe\n\n    x = 5\n    Repo().save(_getframe())\n",
    "a list": "    x = [5]\n",
    "append": "    x = [5]\n    x.append(6)\n",
    "extend": "    x = [5]\n    x.extend([6])\n",
    "update": "    x = {'a': 5}\n    x.update(b=6)\n",
    "pop": "    x = {'a': 5}\n    x.pop('a')\n",
    "setdefault": "    x = {'a': 5}\n    x.setdefault('b', 6)\n",
    "clear": "    x = {5}\n    x.clear()\n",
    "plus-equals on a list": "    x = [5]\n    x += [6]\n",
    "del an item": "    x = {'a': 5}\n    del x['a']\n",
    "a list handed to a call": "    x = []\n    Repo().save(x)\n",
    "a dict filled from json": "    x = {}\n    x.update(json.loads('{}'))\n",
    "a container counter in a lambda": "    x = [0]\n    Mock(side_effect=lambda: x.__setitem__(0, x[0] + 1))()\n",
    "a side_effect collector": "    x = []\n    Mock(side_effect=x.append)(1)\n",
    "a type alias": "    x = 5\n    type x = int\n",
    "a call result": "    x = Repo().save(5)\n",
    "a name bound to another name": "    y = 5\n    x = y\n",
}


@pytest.mark.parametrize("write", sorted(WRITES))
def test_unseen_writes_make_it_unknown(project, scan_collect, write: str) -> None:
    source = "def test_it():\n" + WRITES[write] + "    assert x == 5\n"
    operand = last_operands(scan_cases(project, scan_collect, source))[0]

    assert operand["source"] != "literal"
    assert "text" not in operand


def test_a_use_before_the_binding_is_unknown(project, scan_collect) -> None:
    source = "def test_it():\n    for _ in (1, 2):\n        assert x == 5\n        x = 5\n"
    assert last_operands(scan_cases(project, scan_collect, source))[0] == {"source": "unknown"}


def test_a_frame_write_from_a_helper_of_the_file_makes_every_local_unknown(project, scan_collect) -> None:
    source = """\
def _raise():
    sys._getframe(1).f_locals["x"] = 6


def test_it():
    x = 5
    _raise()
    assert x == 5
"""
    assert last_operands(scan_cases(project, scan_collect, source))[0] == {"source": "unknown"}


def test_module_constants_and_self_attributes_are_never_literal(project, scan_collect) -> None:
    source = """\
EXPECTED = 5


class TestIt:
    limit = 5

    def setup_method(self):
        self.expected = 5

    def test_it(self):
        assert EXPECTED == 5
        assert LIMIT == 5
        assert constants.LIMIT == 5
        assert self.expected == 5
        assert self.limit == 5
        assert TestIt.limit == 5
"""
    assertions = scan_cases(project, scan_collect, source)["TestIt.test_it"]["assertions"]

    assert [assertion["operands"][0] for assertion in assertions] == [{"source": "unknown"}] * 6


def test_an_attribute_of_a_local_object_is_never_literal(project, scan_collect) -> None:
    source = """\
def test_it():
    obj = types.SimpleNamespace()
    obj.x = 5
    alias = obj
    alias.x = 6
    plain = object()
    assert obj.x == 5
    assert alias.x == 6
    assert plain.x == 5
"""
    assertions = scan_cases(project, scan_collect, source)["test_it"]["assertions"]

    assert [assertion["operands"][0] for assertion in assertions] == [
        {"source": "production"},
        {"source": "production"},
        {"source": "unknown"},
    ]


def test_fixtures_and_parameters_are_never_literal(project, scan_collect) -> None:
    source = """\
@pytest.fixture
def five():
    yield 5


@pytest.mark.parametrize("x", [5])
def test_it(x, five, monkeypatch):
    monkeypatch.setattr(constants, "LIMIT", 5)
    assert x == 5
    assert five == 5
    assert constants.LIMIT == 5
"""
    assertions = scan_cases(project, scan_collect, source)["test_it"]["assertions"]

    assert [assertion["operands"][0] for assertion in assertions] == [
        {"source": "parameter"},
        {"source": "unknown"},
        {"source": "unknown"},
    ]
