"""A test file's tests as `test_case` nodes: each test's name, its assertions, and its operands' sources."""

from __future__ import annotations

import sys

from case_scans import operands, scan_cases, scan_nodes


def test_finds_pytest_and_unittest_cases(project, scan_collect) -> None:
    source = """\
import pytest
from unittest import TestCase


def test_a():
    pass


@pytest.fixture
def test_fixture():
    return 1


async def test_async():
    pass


def helper():
    pass


class TestPlain:
    def test_b(self):
        pass

    def helper(self):
        pass

    class TestNested:
        def test_c(self):
            pass


class TestWithInit:
    def __init__(self):
        pass

    def test_not_collected(self):
        pass


class Checks(TestCase):
    def testCamel(self):
        pass


class Base(TestCase):
    pass


class Derived(Base):
    def test_inherited_base(self):
        pass


class Helper:
    def test_not_a_test_class(self):
        pass


def build():
    class TestInside:
        def test_inside_a_function(self):
            pass
"""
    nodes = scan_nodes(project, scan_collect, {"tests/test_cases.py": source}, "tests/test_cases.py")
    names = {node["canonical_name"]: node["attributes"]["name"] for node in nodes}

    assert names == {
        "tests/test_cases.py#test:5": "test_a",
        "tests/test_cases.py#test:14": "test_async",
        "tests/test_cases.py#test:23": "TestPlain.test_b",
        "tests/test_cases.py#test:30": "TestPlain.TestNested.test_c",
        "tests/test_cases.py#test:43": "Checks.testCamel",
        "tests/test_cases.py#test:52": "Derived.test_inherited_base",
    }
    [first] = [node for node in nodes if node["canonical_name"].endswith("#test:5")]
    assert first["local_id"] == "py:test_case:tests/test_cases.py#test:5"
    assert first["display_name"] == "test_a"
    assert first["evidence"] == {"path": "tests/test_cases.py", "start_line": 5, "end_line": 6}
    assert first["attributes"] == {"name": "test_a", "production_calls": 0, "subjects": [], "assertions": []}


def test_each_case_is_contained_by_its_module(project, scan_collect) -> None:
    root = project({"tests/test_it.py": "def test_a():\n    pass\n"})
    [contribution] = scan_collect(root, ["tests/test_it.py"])
    [edge] = [edge for edge in contribution["edges"] if edge["target"].startswith("py:test_case:")]

    assert (edge["kind"], edge["source"]) == ("contains", "py:module:tests.test_it")


def test_reads_assertion_forms(project, scan_collect) -> None:
    source = """\
def test_it(a, b, x):
    assert a == b
    assert a != b, "message"
    assert x
    assert not x
    assert a < b < x
    assert a is not b
    assert a not in b
    assert a <= b
    with pytest.raises(ValueError, match="no"):
        pass
    pytest.warns(UserWarning, print)


class TestUnit(TestCase):
    def test_unit(self):
        self.assertEqual(1, 2, "msg")
        self.assertEqual(first=1, second=2, msg="msg")
        self.assertTrue(True, "msg")
        self.assertFalse(False)
        self.assertIsNot(self, None)
        self.assertNotIn(1, [2])
        self.assertAlmostEqual(1.0, 1.0, places=2)
        self.assertEqual(*(1, 1))
        self.assertCustom(1, 1)
        with self.assertRaises(KeyError):
            pass
"""
    cases = scan_cases(project, scan_collect, source)
    pytest_case = cases["test_it"]
    unit = cases["TestUnit.test_unit"]

    assert [(item["api"], item.get("negated", False), len(item["operands"])) for item in pytest_case["assertions"]] == [
        ("assert ==", False, 2),
        ("assert !=", True, 2),
        ("assert", False, 1),
        ("assert", False, 1),
        ("assert is not", True, 2),
        ("assert not in", True, 2),
        ("assert <=", False, 2),
        ("pytest.raises", False, 0),
        ("pytest.warns", False, 0),
    ]
    assert pytest_case["should_panic"] is True
    assert [(item["api"], item.get("negated", False), len(item["operands"])) for item in unit["assertions"]] == [
        ("assertEqual", False, 2),
        ("assertEqual", False, 2),
        ("assertTrue", False, 1),
        ("assertFalse", True, 1),
        ("assertIsNot", True, 2),
        ("assertNotIn", True, 2),
        ("assertAlmostEqual", False, 2),
        ("assertEqual", False, 0),
        ("assertRaises", False, 0),
    ]
    assert unit["should_panic"] is True
    assert unit["assertions"][0]["line"] == 23
    assert "should_panic" not in scan_cases(project, scan_collect, "def test_calm():\n    assert 1\n")["test_calm"]


def test_mock_assertions_take_the_double_as_their_operand(project, scan_collect) -> None:
    source = """\
def test_it():
    m = Mock()
    m.save.assert_called_once_with(1)
    m.assert_not_called()
    unknown.assert_called()
"""
    case = scan_cases(project, scan_collect, source)["test_it"]
    double = {"source": "mock-call", "double": "tests/test_it.py#double:8:9"}

    assert [(item["api"], item["operands"]) for item in case["assertions"]] == [
        ("assert_called_once_with", [double]),
        ("assert_not_called", [double]),
        ("assert_called", [{"source": "unknown"}]),
    ]


def test_identity_only_self_comparison_text(project, scan_collect) -> None:
    source = """\
def test_it(x):
    assert x == x
    assert x is x
    assert x is not x
    assert [] is []
    assert (1, 2) is (1, 2)
    assert x.a is x.a
    assert x + 1 is x + 1
    self_named = 5
    assert self_named is self_named
    assert self_named == self_named
"""
    case = scan_cases(project, scan_collect, source)["test_it"]

    assert [[operand.get("text") for operand in item] for item in operands(case)] == [
        [None, None],
        ["x", "x"],
        ["x", "x"],
        [None, None],
        ["(1, 2)", "(1, 2)"],
        [None, None],
        [None, None],
        ["self_named", "self_named"],
        ["5", "5"],
    ]


def test_parametrize_arguments_are_parameters(project, scan_collect) -> None:
    source = """\
@pytest.mark.parametrize("a, b", [(1, 1)])
def test_it(a, b):
    assert a == b


@pytest.mark.parametrize("value", [1])
class TestMarked:
    def test_marked(self, value):
        assert value == 1
"""
    cases = scan_cases(project, scan_collect, source)

    assert operands(cases["test_it"]) == [[{"source": "parameter"}, {"source": "parameter"}]]
    assert operands(cases["TestMarked.test_marked"])[0][0] == {"source": "parameter"}
    assert cases["test_it"]["production_calls"] == 0


def test_marks_an_unconfigured_spy(project, scan_collect) -> None:
    source = """\
def test_spy(mocker):
    spy = mocker.spy(Repo, "save")
    spy.assert_called_once_with(1)


def test_stubbed_spy(mocker):
    spy = mocker.spy(Repo, "save")
    spy.return_value = 1
    spy.assert_called_once_with(1)


def test_wrapping():
    wrapper = Mock(wraps=print)
    wrapper.assert_called_once()
"""
    cases = scan_cases(project, scan_collect, source)

    assert operands(cases["test_spy"])[0][0]["unconfigured_spy"] is True
    assert "unconfigured_spy" not in operands(cases["test_stubbed_spy"])[0][0]
    assert operands(cases["test_wrapping"])[0][0]["unconfigured_spy"] is True


def test_reads_operands_through_calls_items_and_helpers(project, scan_collect) -> None:
    source = (
        """\
def helper():
    return Repo()


def literal_helper():
    return 5


def either(flag):
    if flag:
        return Repo()
    return Repo()


def test_it():
    m = Mock()
    assert m.save().x == 1
    assert m.call_args[0] == 1
    assert helper().save(1) == 1
    assert literal_helper() == 5
    assert Mock()() == 1
    assert len([]) == 0
    assert m.save.call_count == 1
    assert either(True) == 1
    chained = Repo()
"""
        + "".join(f"    link{index} = {'chained' if index == 0 else f'link{index - 1}'}\n" for index in range(14))
        + """\
    assert link13 == 1
    assert link2 == 1
"""
    )
    case = scan_cases(project, scan_collect, source)["test_it"]

    assert [item[0]["source"] for item in operands(case)] == [
        "mock-call",
        "mock-call",
        "production",
        "unknown",
        "mock-call",
        "unknown",
        "mock-call",
        "unknown",
        "unknown",
        "production",
    ]


def test_a_failed_case_reading_keeps_the_doubles(project, scan_collect, monkeypatch) -> None:
    package = sys.modules["knossos_python.test_facts"]

    def broken(*_: object) -> None:
        raise RuntimeError("boom")

    monkeypatch.setattr(package, "case_facts", broken)
    root = project({"tests/test_it.py": "from unittest.mock import Mock\n\ndef test_it():\n    Mock()\n"})
    [contribution] = scan_collect(root, ["tests/test_it.py"])

    assert sorted(node["kind"] for node in contribution["nodes"]) == ["function", "module", "test_double"]
    [diagnostic] = contribution["diagnostics"]
    assert (diagnostic["code"], diagnostic["severity"]) == ("PY_TEST_FACTS_FAILED", "warning")
    assert "test cases" in diagnostic["message"] and "boom" in diagnostic["message"]
