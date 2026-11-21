"""When an operand is a double's pinned value (`mock-config`), and every way the test may change what it answers."""

from __future__ import annotations

from case_scans import operands, scan_cases


def test_traces_a_local_mock_echo(project, scan_collect) -> None:
    source = """\
def test_it():
    m = Mock()
    m.return_value = 42
    assert m() == 42
    expected = "x"
    named = MagicMock(**{"save.return_value": expected})
    result = named.save(1)
    assert result == expected
"""
    case = scan_cases(project, scan_collect, source)["test_it"]

    assert operands(case) == [
        [
            {"source": "mock-config", "value": "42", "double": "tests/test_it.py#double:8:9"},
            {"source": "literal", "text": "42"},
        ],
        [
            {"source": "mock-config", "value": "'x'", "double": "tests/test_it.py#double:12:13"},
            {"source": "literal", "text": "'x'"},
        ],
    ]


def test_an_awaited_async_mock_echoes(project, scan_collect) -> None:
    source = """\
async def test_it():
    m = AsyncMock(return_value=1)
    assert await m() == 1
    assert m() == 1
    plain = Mock(return_value=1)
    assert await plain() == 1
"""
    case = scan_cases(project, scan_collect, source)["test_it"]

    assert [item[0]["source"] for item in operands(case)] == ["mock-config", "mock-call", "mock-call"]


def test_held_doubles_carry_no_pin(project, scan_collect) -> None:
    source = """\
@pytest.fixture
def repo():
    double = Mock()
    double.save.return_value = 1
    yield double


def test_fixture(repo):
    repo.save.return_value = 1
    assert repo.save() == 1


class TestHeld(TestCase):
    def setUp(self):
        self.repo = Mock()
        self.repo.save.return_value = 1

    def test_set_up(self):
        held = self.repo
        assert self.repo.save() == 1
        assert held.save() == 1
        assert self.repo is held
"""
    cases = scan_cases(project, scan_collect, source)

    assert operands(cases["test_fixture"])[0][0] == {"source": "mock-call", "double": "tests/test_it.py#double:9:14"}
    assert [item[0] for item in operands(cases["TestHeld.test_set_up"])] == [
        {"source": "mock-call", "double": "tests/test_it.py#double:21:21"}
    ] * 3


def test_a_conftest_fixture_double_is_a_mock_call(project, scan_collect) -> None:
    conftest = "import pytest\nfrom unittest.mock import Mock\n\n\n@pytest.fixture\ndef repo():\n    return Mock()\n"
    case = scan_cases(
        project, scan_collect, "def test_it(repo):\n    assert repo() == 1\n", {"tests/conftest.py": conftest}
    )

    assert operands(case["test_it"])[0][0] == {"source": "mock-call", "double": "tests/conftest.py#double:7:12"}


ECHO_DEFEATS = {
    "side_effect": "    m.side_effect = None\n",
    "side_effect on construction": "    m = Mock(return_value=1, side_effect=[1])\n",
    "wraps": "    m = Mock(return_value=1, wraps=print)\n",
    "reset_mock": "    m.reset_mock()\n",
    "configure_mock": "    m.configure_mock(return_value=2)\n",
    "mock_add_spec": "    m.mock_add_spec(['x'])\n",
    "a spec": "    m = Mock(spec=Repo, return_value=1)\n",
    "spread keywords": "    m = Mock(**options)\n    m.return_value = 1\n",
    "setattr": "    setattr(m, 'return_value', 2)\n",
    "vars": "    vars(m)\n",
    "a second pin": "    m.return_value = 2\n",
    "augmented pin": "    m.return_value += 1\n",
    "a pin by unpacking": "    m.return_value, other = 2, 3\n",
    "deleted return_value": "    del m.return_value\n",
    "handed to a helper": "    configure(m)\n",
    "handed to production": "    Repo().save(m)\n",
    "put in a list": "    holder = [m]\n",
    "stored on an attribute": "    holder.double = m\n",
    "a walrus alias": "    (alias := m)\n",
    "a side_effect in a nested scope": "    def later():\n        m.side_effect = [2]\n",
    "spread into a list": "    holder = [*m]\n",
    "a side_effect callable on construction": "    m = Mock(return_value=1, side_effect=configure)\n",
    "a positional spec": "    m = Mock(Repo, return_value=1)\n",
    "its class written": "    type(m).__call__ = None\n",
    "a spread on construction": "    m = Mock(*options, return_value=1)\n",
    "exec in the test": "    exec('pass')\n",
    "a frame write": "    import sys\n    sys._getframe().f_locals['m'] = None\n",
}


def test_reads_that_leave_the_echo(project, scan_collect) -> None:
    source = """\
def test_it(other):
    m = Mock(return_value=1, name="plain")
    alias = m
    for _ in m:
        pass
    print(m)
    isinstance(m, Mock)
    other.assert_called_with(m)
    other.assert_has_calls([mock.call(m)])
    m.save.return_value = 2
    if not m:
        pass
    assert alias() == 1
    assert m.save() == 2
"""
    case = scan_cases(project, scan_collect, source)["test_it"]

    assert [item[0].get("value") for item in operands(case)[-2:]] == ["1", "2"]


def test_side_effect_wraps_reset_and_async_defeat_the_echo(project, scan_collect) -> None:
    for name, write in sorted(ECHO_DEFEATS.items()):
        source = f"def test_it(options, configure, holder):\n    m = Mock(return_value=1)\n{write}    assert m() == 1\n"
        [operand, _] = operands(scan_cases(project, scan_collect, source)["test_it"])[-1]
        assert (name, operand["source"], "value" in operand) == (name, "mock-call", False)
    source = """\
async def test_it():
    m = AsyncMock(return_value=1)
    assert m() == 1
"""
    assert operands(scan_cases(project, scan_collect, source)["test_it"])[0][0]["source"] == "mock-call"


def test_a_pin_that_may_not_reach_the_call_gives_no_value(project, scan_collect) -> None:
    source = """\
def test_branch(flag):
    m = Mock()
    if flag:
        m.return_value = 1
    assert m() == 1


def test_after():
    m = Mock()
    assert m() == 1
    m.return_value = 1


def test_not_a_constant(value):
    m = Mock()
    m.return_value = value
    assert m() == 1


def test_once():
    m = Mock()
    m.side_effect = [1]
    assert m() == 1


def test_rebound_result():
    m = Mock(return_value=1)
    result = m()
    result = 2
    assert result == 1


def test_a_list():
    m = Mock(return_value=[1])
    assert m() == [1]


def test_a_result_read_from_a_nested_function():
    m = Mock(return_value=1)
    with open("f"):
        result = m()

    def check():
        assert result == 1


def test_a_member_of_a_patch():
    with mock.patch("app.repo.Repo") as repo:
        repo.save.return_value = 1
        assert repo.save() == 1


SHARED = Mock(return_value=1)


def test_a_module_double():
    assert SHARED() == 1
"""
    cases = scan_cases(project, scan_collect, source)

    names = ["test_branch", "test_after", "test_not_a_constant", "test_once", "test_rebound_result", "test_a_list"]
    for name in [*names, "test_a_result_read_from_a_nested_function"]:
        assert (name, "value" in operands(cases[name])[0][0]) == (name, False)
    assert operands(cases["test_a_member_of_a_patch"])[0][0]["source"] == "mock-call"
    assert operands(cases["test_a_module_double"])[0][0]["source"] == "mock-call"
