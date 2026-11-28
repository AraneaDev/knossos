from unittest import mock

import bases
from bases import Colour, Handler, Point, Shape, Square


def test_an_enum_answers_its_own_names():
    with mock.patch.object(Colour, "name", "BLUE"), mock.patch.object(Colour, "value", 2):
        assert bases.Colour.RED is not None


def test_a_named_tuple_answers_its_helpers():
    with mock.patch.object(Point, "_replace"), mock.patch.object(Point, "_asdict", return_value={}):
        assert Point(1).x == 1


def test_an_abstract_base_class_keeps_its_members_and_its_metaclass_names():
    with mock.patch.object(Shape, "area", return_value=2.0), mock.patch.object(Shape, "register"):
        assert Square().area() == 2.0
    with mock.patch.object(Square, "area", return_value=3):
        assert Square().area() == 3


def test_a_function_its_module_rebinds_takes_the_rebound_signature():
    with mock.patch("bases.total") as total:
        bases.total(2)
        total.assert_called_once_with(2)


def test_a_function_an_import_may_replace():
    with mock.patch("bases.pick") as pick:
        bases.pick(1, 2)
        pick.assert_called_once_with(1, 2)


def test_rest_parameters_take_any_further_argument():
    with mock.patch.object(Handler, "handle", return_value="ok") as handle:
        Handler().handle("r", 1, 2, timeout=3)
        handle.assert_called_once_with("r", 1, 2, timeout=3)


def test_a_method_its_class_rebinds_takes_the_rebound_signature():
    with mock.patch.object(Handler, "run") as run:
        Handler().run()
        run.assert_called_once_with()


def test_a_class_double_of_a_module_the_test_exercises_names_no_test():
    handler = mock.create_autospec(Handler, instance=True)
    handler.handle.return_value = "ok"
    assert bases.Square().area() == 1.0
    assert handler.handle("r") == "ok"
