from unittest.mock import AsyncMock, Mock, patch

from probes import svc

LIMIT = 10


def test_mutable_pin_list():
    m = Mock()
    m.return_value = []
    m().append(1)
    assert m() == [1]


def test_mutable_pin_dict_negated():
    m = Mock(return_value={})
    m()["k"] = 1
    assert m() != {}


def test_mutable_pin_list_member():
    m = Mock()
    m.fetch.return_value = []
    m.fetch().extend([1, 2])
    assert m.fetch() == [1, 2]


def test_pin_mutated_via_alias():
    m = Mock()
    m.fetch.return_value = []
    got = m.fetch()
    got.append(3)
    assert m.fetch() == [3]


def test_list_local_mutated():
    expected = [1]
    expected.append(2)
    assert expected == [1, 2]


def test_aug_assign():
    x = 1
    x += 1
    assert x == 2


def test_module_constant():
    assert LIMIT == 10


def test_closure_nonlocal():
    n = 0

    def bump():
        nonlocal n
        n += 1

    bump()
    assert n == 1


def test_side_effect_list_pin():
    m = Mock(side_effect=[1, 2])
    assert m() == 1
    assert m() == 2


def test_return_value_reassigned_in_branch():
    m = Mock(return_value=1)
    if LIMIT > 5:
        m.return_value = 2
    assert m() == 2


def test_return_value_set_via_attribute_chain():
    m = Mock(return_value=1)
    m.configure_mock(return_value=3)
    assert m() == 3


async def test_async_pin_reassigned():
    m = AsyncMock(return_value=1)
    m.return_value = 4
    assert await m() == 4


def test_pin_then_reset():
    m = Mock(return_value=1)
    m.reset_mock(return_value=True)
    assert m() != 1


def test_pin_side_effect_after():
    m = Mock(return_value=1)
    m.side_effect = lambda: 2
    assert m() == 2


def test_pin_via_setattr():
    m = Mock(return_value=1)
    setattr(m, "return_value", 5)
    assert m() == 5


def test_pin_via_object_setattr_on_child():
    m = Mock()
    m.f.return_value = 1
    child = m.f
    child.return_value = 2
    assert m.f() == 2


def test_pin_via_child_alias_side_effect():
    m = Mock()
    m.f.return_value = 1
    f = m.f
    f.side_effect = [7]
    assert m.f() == 7


def test_pin_via_mock_add_spec():
    m = Mock()
    m.f.return_value = 1
    m.attach_mock(Mock(return_value=9), "f")
    assert m.f() == 9


def test_pin_changed_by_patch():
    m = Mock()
    m.f.return_value = 1
    with patch.object(m, "f", return_value=2):
        assert m.f() == 2


def test_pin_with_child_in_dict():
    m = Mock()
    m.f.return_value = 1
    m.f.__dict__["_mock_return_value"] = 5
    assert m.f() == 5


def test_pin_via_vars():
    m = Mock(return_value=1)
    vars(m)["_mock_return_value"] = 3
    assert m() == 3


def test_pin_mock_children_loop():
    m = Mock()
    m.f.return_value = 1
    for child in (m.f,):
        child.return_value = 4
    assert m.f() == 4


def test_pin_via_method_calls():
    m = Mock()
    m.f.return_value = 1
    m.mock_add_spec(["f"])
    m.f.return_value = 6
    assert m.f() == 6


def test_pin_in_list_then_changed():
    m = Mock(return_value=1)
    mocks = [m]
    mocks[0].return_value = 8
    assert m() == 8


def test_pin_then_call_helper():
    m = Mock(return_value=1)
    reconfigure(m)
    assert m() == 2


def reconfigure(x):
    x.return_value = 2


def test_spec_pin_through_use():
    api = Mock(spec=svc.Api)
    api.fetch.return_value = [1, 2]
    assert svc.use(api) == 2
