from unittest.mock import patch

from probes.aliases import Typed
from probes.lookup import Single
from probes.selfwrites import Kept

# The planted twins of the final review's probes: each must still give a definite finding.


def test_a_planted_arity_mismatch_under_one_base():
    # Beside `test_probe_shapes.py`: one base and nothing shadowing it, so `Base.save(key)` answers for certain.
    with patch.object(Single, "save") as s:
        Single().save("k")
    s.assert_called_once_with("k", "v")


def test_a_planted_arity_mismatch_beside_other_self_writes():
    # Beside `test_probe_selfwrites.py`: `__init__` writes other names, so `save(k)` keeps its signature.
    kept = Kept()
    with patch.object(kept, "save") as s:
        kept.save("k")
    s.assert_called_once_with("k", "v")


def test_a_planted_ghost_on_a_class_no_alias_holds():
    # Beside `test_probe_aliases.py`: annotations, an instance and `isinstance` hold no alias, so the list stays closed.
    with patch.object(Typed, "rnu"):
        assert Typed().run() == 1
