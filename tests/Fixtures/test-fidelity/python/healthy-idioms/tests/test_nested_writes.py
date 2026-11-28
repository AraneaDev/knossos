"""Doubles of a nested class whose names are written outside its body (W-3, G4-05)."""

from unittest import mock

import nested_writer
from nested_writes import Alias, Far, Outer

Inner = Outer.Inner


def test_patch_object_on_the_dotted_class():
    with mock.patch.object(Outer.Inner, "extra"):
        assert Outer.Inner().run(1, 2) == 3


def test_string_target_through_the_outer_class():
    with mock.patch("nested_writes.Outer.Inner.extra"):
        assert Outer.Inner().run(1, 2) == 3


def test_a_test_alias_of_the_nested_class():
    with mock.patch.object(Inner, "extra"):
        assert Inner().run(1, 2) == 3


def test_a_module_alias_of_the_nested_class():
    with mock.patch.object(Alias, "aliased"):
        assert Alias().run(1, 2) == 3


def test_written_from_another_file():
    nested_writer.enable()
    with mock.patch.object(Far.Inner, "remote"):
        assert Far.Inner() is not None


def test_string_target_written_from_another_file():
    with mock.patch("nested_writes.Far.Inner.remote"):
        assert Far.Inner() is not None


def test_a_rewritten_method_called_with_two():
    with mock.patch.object(Outer.Inner, "run") as run:
        Outer.Inner().run(1, 2)
        run.assert_called_with(1, 2)


def test_a_spec_of_the_nested_class():
    double = mock.create_autospec(Outer.Inner, instance=True)
    double.extra = 2
    assert double.extra == 2
