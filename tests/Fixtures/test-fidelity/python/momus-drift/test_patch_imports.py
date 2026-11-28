from unittest.mock import patch


def test_imported_attr():
    with patch("prod_imports.idna"):
        pass


def test_from_import_attr():
    with patch("prod_imports.proxy_bypass"):
        pass


def test_aliased_import_attr():
    with patch("prod_imports.osp"):
        pass


def test_dotted_import_binds_first_segment():
    with patch("prod_imports.os"):
        pass


def test_function_local_import_is_not_an_attr():
    def f():
        from datetime import timedelta
        return timedelta

    with patch("prod_imports.timedelta"):
        pass
