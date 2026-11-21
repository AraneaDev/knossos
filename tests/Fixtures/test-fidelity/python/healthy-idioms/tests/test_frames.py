"""Healthy tests whose locals a frame write or `exec` may change: every local of this file is unknown.

Kept apart from `test_cases.py`, since a frame read anywhere in a file leaves
every local of that file unknown and would hide what the other idioms test.
"""

import sys

from members import Repo


def _raise_level():
    sys._getframe(1).f_locals["level"] = 1


def test_a_frame_write():
    level = 0
    _raise_level()
    assert level == 1
    assert Repo().method("k") == "k"


def test_locals_written_by_exec():
    found = 0
    exec("found = 1", globals(), locals())
    assert found == 0
    assert Repo().method("k") == "k"
