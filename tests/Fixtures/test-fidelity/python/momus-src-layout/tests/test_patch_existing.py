from unittest.mock import patch


def test_it():
    with patch("prod_missing.existing_attr"):
        pass