from unittest.mock import patch

from probes.selfwrites import Inst, Pair, Same, ViaSetattr

# Final review I2: each matcher binds what `__init__` rebinds the name to, not the def.


def test_same_shadow():
    s = Same("eu")
    with patch.object(s, "save") as m:
        s.save("k", "v")
    m.assert_called_once_with("k", "v")


def test_setattr_shadow():
    v = ViaSetattr()
    with patch.object(v, "load") as m:
        v.load(1, 2)
    m.assert_called_once_with(1, 2)


def test_tuple_shadow():
    p = Pair()
    with patch.object(p, "save") as s:
        p.save("k", "v")
    s.assert_called_once_with("k", "v")


def test_attrs():
    i = Inst()
    with patch.object(i, "a", 5), patch.object(i, "c", 5), patch.object(i, "d", 5), patch.object(i, "fh"):
        assert i.run() == 5
    with patch.object(i, "last", 9), patch.object(i, "inner", 3), patch.object(i, "lam", 4):
        assert i.run() == 1
