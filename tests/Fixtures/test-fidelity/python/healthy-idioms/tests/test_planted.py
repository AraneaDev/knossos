from unittest import mock

from members import Repo


def test_a_planted_ghost_still_gives_a_definite():
    with mock.patch.object(Repo, "methd"):
        pass


# Each planted finding below sits beside its healthy twin, which must stay silent.


def test_a_real_name_is_no_ghost():
    with mock.patch.object(Repo, "method"):
        pass


def test_a_mangled_name_spelled_as_python_stores_it():
    with mock.patch.object(Repo, "_Repo__hidden"):
        Repo()


def test_a_planted_mangled_name_spelled_as_written():
    with mock.patch.object(Repo, "__hidden"):
        Repo()


def test_a_matcher_whose_arguments_bind():
    with mock.patch.object(Repo, "method") as method:
        Repo().method("k")
        method.assert_called_once_with("k")
        method.assert_called_once_with(key="k")


def test_a_planted_matcher_with_an_unknown_keyword():
    with mock.patch.object(Repo, "method") as method:
        Repo().method("k")
        method.assert_called_once_with(kee="k")


def test_a_pinned_mock_handed_to_production():
    from values import apply

    supplier = mock.Mock()
    supplier.return_value = 5
    assert apply(supplier) == 5


def test_a_planted_mock_echo():
    supplier = mock.Mock()
    supplier.return_value = 5
    assert supplier() == 5


def test_a_constant_compared_with_production():
    from values import Money

    expected = 5
    assert Money(5).cents == expected


def test_a_planted_constant():
    expected = 5
    assert expected < 6


def test_a_public_member():
    with mock.patch.object(Repo, "method"):
        Repo()


def test_a_planted_protected_member():
    with mock.patch.object(Repo, "_protected"):
        Repo()


def test_a_pin_the_annotation_admits():
    with mock.patch("values.count", return_value=3):
        Repo()


def test_a_planted_pin_the_annotation_refuses():
    with mock.patch("values.count", return_value="many"):
        Repo()


def test_a_planted_ghost_on_an_abstract_base_class():
    # Beside `test_bases.py`'s healthy `Shape.area`: `abc.ABC` keeps the list complete.
    from bases import Shape

    with mock.patch.object(Shape, "areaa"):
        pass
