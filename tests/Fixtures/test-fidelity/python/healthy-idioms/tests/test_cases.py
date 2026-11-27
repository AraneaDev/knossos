"""Healthy tests whose assertions only look as if they cannot fail: each operand may be written out of sight."""

import json
import math
import os
from unittest import TestCase, mock

import pytest

from members import Repo
from values import Money, fill

TOTAL = 0


def test_a_nonlocal_counter_written_by_a_side_effect():
    calls = 0

    def bump(key):
        nonlocal calls
        calls += 1
        return key

    double = mock.Mock(side_effect=bump)
    Repo().method(double("k"))
    assert calls == 1


def _add(*_):
    global TOTAL
    TOTAL += 1


def test_a_global_counter_written_by_a_side_effect():
    global TOTAL
    TOTAL = 0
    double = mock.Mock(side_effect=_add)
    Repo().method(double())
    assert TOTAL == 1


def test_a_collector_and_a_container_counter():
    seen = []
    counter = [0]
    double = mock.Mock(side_effect=seen.append)
    other = mock.Mock(side_effect=lambda *_: counter.__setitem__(0, counter[0] + 1))
    Repo().method(double("k"))
    other()
    assert seen == ["k"]
    assert counter == [1]


def test_a_walrus_inside_a_comprehension():
    last = 0
    values = [last := item for item in (1, 2)]
    assert last == 2
    assert values == [1, 2]
    assert Repo().method("k") == "k"


def test_a_mutable_value_handed_to_a_call():
    buffer = []
    fill(buffer)
    loaded = {}
    loaded.update(json.loads('{"a": 1}'))
    assert buffer == [1]
    assert loaded == {"a": 1}


@pytest.fixture
def tally():
    box = {"n": 0}
    yield box
    box["n"] += 1


def test_a_yield_fixture_mutates_after_the_test(tally):
    tally["n"] = 1
    assert tally == {"n": 1}
    assert Repo().method("k") == "k"


def test_monkeypatch_writes_what_the_operand_reads(monkeypatch):
    monkeypatch.setenv("KNOSSOS_MODE", "fast")
    monkeypatch.setattr(Repo, "attribute", 2)
    assert os.environ["KNOSSOS_MODE"] == "fast"
    assert Repo.attribute == 2


def test_a_nan_is_unequal_to_itself():
    value = math.nan
    assert (value == value) is False
    assert value != value
    assert Repo().method("k") == "k"


def test_equality_under_test_holds_for_the_same_value():
    money = Money(5)
    assert money == money


@pytest.mark.parametrize(("left", "right"), [(1, 1), (2, 2)])
def test_parametrized_operands_compare(left, right):
    assert left == right
    assert Repo().method("k") == "k"


def test_a_conditional_and_a_finally_binding(tmp_path):
    expected = 1
    if tmp_path.exists():
        expected = 2
    try:
        settled = 3
    finally:
        settled = 4
    assert expected == 2
    assert settled == 4
    assert Repo().method("k") == "k"


def test_side_effect_overrides_return_value():
    double = mock.Mock(return_value=1)
    double.side_effect = [2]
    assert double() == 2
    assert Repo().method("k") == "k"


def test_a_raising_side_effect_answers_instead_of_the_pin():
    built = mock.Mock(return_value=1, side_effect=KeyError)
    later = mock.Mock(return_value=1)
    later.side_effect = KeyError
    with pytest.raises(KeyError):
        assert built() == 1
    with pytest.raises(KeyError):
        assert later() == 1
    assert Repo().method("k") == "k"


async def test_an_async_mock_not_awaited_is_a_coroutine():
    double = mock.AsyncMock(return_value=1)
    pending = double()
    with pytest.raises(AssertionError):
        assert double() == 1
    assert await pending == 1
    assert Repo().method("k") == "k"


def test_reset_mock_forgets_the_pin():
    double = mock.Mock(return_value=1)
    double.reset_mock(return_value=True)
    assert double() != 1
    assert Repo().method("k") == "k"


async def test_an_async_member_answers_only_once_awaited():
    double = mock.AsyncMock()
    double.method.return_value = "x"
    pending = double.method("k")
    assert pending != "x"
    assert await pending == "x"
    assert Repo().method("k") == "k"


@pytest.fixture
def repo_double():
    double = mock.Mock()
    double.method.return_value = "x"
    return double


def test_an_echo_through_a_fixture(repo_double):
    assert repo_double.method("k") == "x"
    assert Repo().method("k") == "k"


class TestHeldEcho(TestCase):
    def setUp(self):
        self.repo = mock.Mock()
        self.repo.method.return_value = "x"

    def test_an_echo_through_set_up(self):
        self.assertEqual(self.repo.method("k"), "x")
        self.assertEqual(Repo().method("k"), "k")


def test_a_nested_reader_runs_before_its_local_is_rebound():
    expected = Repo().method("a")

    def check():
        assert expected == "a"

    check()
    expected = "b"
    assert Repo().method(expected) == "b"


def test_a_binding_in_a_branch_read_after_it(tmp_path):
    if tmp_path.exists():
        found = "k"
    assert found == "k"
    assert Repo().method("k") == "k"


def test_a_pin_read_back_in_a_nested_helper():
    double = mock.Mock(return_value="k")

    def check():
        assert double() == "k"

    check()
    assert Repo().method(check) is check
