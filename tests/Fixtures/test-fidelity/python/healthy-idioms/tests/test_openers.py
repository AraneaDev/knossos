from unittest import mock

import dynamic_module
from openers import Bag, Box, Failure, Lazy, Settings


def test_a_name_getattr_answers_can_be_stubbed():
    lazy = Lazy()
    with mock.patch.object(lazy, "anything", "stubbed"):
        assert lazy.anything == "stubbed"


def test_names_set_by_a_loop_can_be_stubbed():
    settings = Settings(colour="red")
    bag = Bag(size=1)
    with mock.patch.object(settings, "colour", "blue"), mock.patch.object(bag, "size", 2):
        assert settings.colour == "blue"


def test_inherited_and_generic_members_can_be_stubbed():
    with mock.patch.object(Failure, "with_traceback"), mock.patch.object(Box, "__class_getitem__"):
        pass


def test_a_module_getattr_name_can_be_patched():
    with mock.patch("dynamic_module.computed", "value"):
        assert dynamic_module.computed == "value"
