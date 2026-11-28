"""Names another file writes on a module or class (G4-03), beside a module nothing writes."""

from unittest import mock

import config
from config import Settings


def test_a_flag_another_file_sets():
    with mock.patch("config.FLAG", 2):
        assert config.flag() == 2


def test_a_module_global_only_another_file_writes():
    with mock.patch("config.DEBUG", False):
        assert config.flag() == 1


def test_a_class_attribute_another_file_writes():
    with mock.patch.object(Settings, "verbose", False):
        assert config.flag() == 1


def test_a_module_nothing_writes_keeps_its_ghost():
    with mock.patch("quiet.DEBUG", True):
        assert config.flag() == 1
