from unittest.mock import patch

from probes.aliases import Aliased, Comprehended, Installed, LoopA, LoopB, Paired, Registered, Viewish

# Final review I3 (Ruling P23): each name is written through a same-file alias of the class.


def test_loop_alias():
    with patch.object(LoopA, "extra", 2), patch.object(LoopB, "extra", 3):
        assert LoopA.extra == 2


def test_setattr_loop():
    with patch.object(Viewish, "get", return_value="g"):
        assert Viewish().get() == "g"


def test_install():
    with patch.object(Installed, "hook", return_value=5):
        assert Installed().hook() == 5


def test_registry():
    with patch.object(Registered, "registered", False):
        assert Registered.registered is False


def test_alias_display_and_comprehension():
    with patch.object(Aliased, "x", 2), patch.object(Paired, "y", 3), patch.object(Comprehended, "z", 4):
        assert Aliased.x == 2
