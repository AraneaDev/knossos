import logging
from unittest import mock
from unittest.mock import patch

import pytest

from probes import exports, frozen, private
from probes.lookup import D, Diamondish, InstanceShadow, Late, Mixed, Shadow, Tracker

# Final review I1 (Ruling P24): each matcher binds the def Python's lookup answers with.


def test_mro_diamond():
    with patch.object(Diamondish, "handle") as h:
        Diamondish().handle(1, 2)
    h.assert_called_once_with(1, 2)


def test_mro_divergent():
    # `D(B, C)`, `B(A)`: the MRO takes A's `run(x)`, not C's `run(x, y)`.
    with patch.object(D, "run") as r:
        D().run(1)
    r.assert_called_once_with(1)


def test_shadow_assignment():
    with patch.object(Shadow, "save") as s:
        Shadow().save("k", "v", extra=1)
    s.assert_called_once_with("k", "v", extra=1)


def test_external_first_in_mro():
    rec = logging.makeLogRecord({"msg": "x"})
    with patch.object(Tracker, "emit") as e:
        Tracker().emit(rec)
    e.assert_called_once_with(rec)


def test_instance_shadow():
    obj = InstanceShadow("eu")
    with patch.object(obj, "save") as s:
        obj.save("k", "v")
    s.assert_called_once_with("k", "v")


def test_late_rebind():
    with patch.object(Late, "handle") as h:
        Late().handle(1, 2, 3)
    h.assert_called_once_with(1, 2, 3)


def test_mixed_object():
    with patch.object(Mixed, "save") as s:
        Mixed().save("k")
    s.assert_called_once_with("k")


@pytest.mark.parametrize(
    "name",
    ["toml_reader", "CONFIG", "LOOPED", "IN_WHILE", "WALRUS", "PLATFORM", "COUNTER", "a", "b", "c", "d", "e", "COMP"]
    + ["os", "ET", "helper", "Local"],
)
def test_exports_exist(name):
    assert hasattr(exports, name)


def test_exports_patch_each():
    with patch("probes.exports.toml_reader"), patch("probes.exports.CONFIG", {}), patch("probes.exports.LOOPED", 9):
        pass
    with patch("probes.exports.IN_WHILE", 9), patch("probes.exports.WALRUS", 9), patch("probes.exports.PLATFORM", "x"):
        pass
    with patch("probes.exports.COUNTER", 3), patch("probes.exports.c", 9), patch("probes.exports.e", 9):
        pass
    with patch("probes.exports.COMP", 9), patch("probes.exports.os"), patch("probes.exports.ET"):
        pass


def test_late_global():
    exports.setup()
    with patch("probes.exports.LATE", 5):
        assert exports.LATE == 5


def test_class_members_patch():
    with patch.object(exports.Local, "cjson"), patch.object(exports.Local, "memo"), patch.object(exports.Local, "modern"):
        pass
    with patch.object(exports.Local, "p"), patch.object(exports.Local, "only"):
        pass


def test_instance_members():
    m = mock.Mock(spec=exports.Local)
    m.modern.return_value = 1
    assert m.modern() == 1
    assert m.only is not None


def test_frozen_setattr():
    f = frozen.Frozen("A")
    m = mock.Mock(spec=f)
    assert m.slug is not None
    assert m.show
    p = frozen.Plain()
    with patch.object(p, "token", "x"), patch.object(p, "other", 2):
        assert p.token == "x"


def test_module_private_function():
    with patch("probes.private.__helper", return_value=5) as h:
        assert private.public(1) == 5
    h.assert_called_once_with(1)


def test_module_private_object():
    with patch.object(private, "__helper", return_value=6):
        assert private.public(1) == 6
