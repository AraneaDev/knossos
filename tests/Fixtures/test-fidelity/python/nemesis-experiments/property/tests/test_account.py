from unittest.mock import patch

from src.account import Account


def test_patch_that_predates_the_property():
    with patch.object(Account, 'balance', return_value=10):
        pass
