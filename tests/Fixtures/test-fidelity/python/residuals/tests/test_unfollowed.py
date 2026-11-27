"""Doubles of names a write the worker does not follow adds (U1), each a warning, beside a plain class's definite."""

from unittest import mock

import framewrite
import selfwrite
from execd import Execd
from plain import Plain
from unfollowed import Aliased, Built, Framed, Held, Outer, Plugged, Record


def test_an_alias_of_self():
    aliased = Aliased()
    with mock.patch.object(aliased, "extra"):
        assert Plain().run(1) == 1


def test_an_instance_written_outside_the_class():
    with mock.patch.object(Built, "extra"):
        assert Plain().run(1) == 1


def test_a_module_that_writes_itself():
    with mock.patch("selfwrite.LATE", 2):
        assert selfwrite.late() == 0


def test_a_nested_class_written():
    with mock.patch.object(Outer.Inner, "extra"):
        assert Plain().run(1) == 1


def test_a_class_held_as_a_parameter_default():
    with mock.patch.object(Held, "extra"):
        assert Plain().run(1) == 1


def test_a_class_handed_to_a_call_named_like_a_builtin():
    with mock.patch.object(Plugged, "extra"):
        assert Plain().run(1) == 1


def test_a_class_builtins_exec_writes():
    with mock.patch.object(Execd, "extra"):
        assert Plain().run(1) == 1


def test_a_class_an_assigned_frame_hack_writes():
    with mock.patch.object(Framed, "extra"):
        assert Plain().run(1) == 1


def test_a_method_a_framework_base_may_rewrite():
    with mock.patch.object(Record, "save") as save:
        Record().save(1)
        save.assert_called_once_with(1, 2)


def test_a_local_a_frame_write_may_change():
    x = 1
    framewrite.write_caller_local()
    assert x == 1


def test_a_plain_class_keeps_its_ghost():
    with mock.patch.object(Plain, "extra"):
        assert Plain().run(1) == 1


def test_a_plain_class_keeps_its_arity():
    with mock.patch.object(Plain, "run") as run:
        Plain().run(1)
        run.assert_called_once_with(1, 2)
