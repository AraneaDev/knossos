from unittest.mock import patch

import rebinding
import rewriting
from rewriting import Store


def test_a_class_decorator_rewrites_the_method():
    with patch.object(Store, "save") as save:
        rewriting.use()
        save.assert_called_with(1)


def test_a_globals_write_rebinds_the_function():
    with patch("rewriting.g") as g:
        rewriting.use()
        g.assert_called_with(2)


def test_an_import_its_module_binds_again():
    with patch("rebinding.count") as count:
        rebinding.count()
        count.assert_called_with()
