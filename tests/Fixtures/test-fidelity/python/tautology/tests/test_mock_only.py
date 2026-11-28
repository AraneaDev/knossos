from unittest import mock

from ledger import Ledger, deposit


def test_a_planted_mock_only_assertion():
    ledger = mock.Mock(spec=Ledger)
    ledger.add(3)
    ledger.add.assert_called_once_with(3)


def test_a_double_production_calls():
    ledger = mock.Mock(spec=Ledger)
    deposit(ledger, 3)
    ledger.add.assert_called_once_with(3)
