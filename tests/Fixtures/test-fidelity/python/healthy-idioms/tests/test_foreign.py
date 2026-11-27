"""A module global another file sets, patched as a test of its reader does (G4-03)."""

import importlib
from unittest import mock

import flags


def test_a_flag_another_module_sets():
    importlib.import_module("boot").configure()
    with mock.patch("flags.VERBOSE", False):
        assert flags.level() == 0
