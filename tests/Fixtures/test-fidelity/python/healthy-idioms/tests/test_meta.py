from unittest.mock import patch

import inherits
import samemod
import subhook
import subhook_other
import wrapmeta
from inherits import C
from samemod import Local
from subhook import D
from subhook_other import E


def test_inherited_metaclass_other_module():
    with patch.object(C, "save") as m:
        inherits.use()
        m.assert_called_with(1)


def test_inherited_metaclass_same_module():
    with patch.object(Local, "save") as m:
        samemod.use()
        m.assert_called_with(1)


def test_direct_metaclass_control():
    with patch.object(wrapmeta.Base, "load") as m:
        wrapmeta.Base().load(1)
        m.assert_called_with(1)


def test_init_subclass_same_module():
    with patch.object(D, "save") as m:
        subhook.use()
        m.assert_called_with(1)


def test_init_subclass_other_module():
    with patch.object(E, "save") as m:
        subhook_other.use()
        m.assert_called_with(1)


def test_unpatched_behaviour_is_real():
    assert C().save(5) == 5
    assert D().save(5) == 5
    assert E().save(5) == 5
    assert Local().save(5) == 5
