from unittest.mock import patch

import allowed
from allowed import Color, Point, Port, Ver


def test_dataclass_method():
    with patch.object(Point, "shifted") as m:
        allowed.use(Point(1), Ver(1), Color.RED)
        m.assert_called_with(3)


def test_total_ordering_method():
    with patch.object(Ver, "bump") as m:
        allowed.use(Point(1), Ver(1), Color.RED)
        m.assert_called_with(1)
    assert Ver(1) <= Ver(2)


def test_enum_method():
    with patch.object(Color, "describe") as m:
        allowed.use(Point(1), Ver(1), Color.RED)
        m.assert_called_with("x")


def test_dataclass_constructor_matcher():
    with patch("allowed.Point") as P:
        allowed.make()
        P.assert_called_with(1, y=2)


def test_dataclass_init_autospec():
    with patch.object(Point, "__init__", autospec=True, return_value=None) as init:
        Point(1, 2)
        init.assert_called_with(init.call_args.args[0], 1, 2)


def test_abc_method():
    class Impl(Port):
        def send(self, data):
            return data

    with patch.object(Port, "wrap") as m:
        Impl().wrap(1)
        m.assert_called_with(1)
    assert allowed.make() == Point(1, 2)
