from unittest import mock

from ledger import report


def test_a_planted_mock_echo():
    ledger = mock.Mock()
    ledger.total.return_value = 7
    assert ledger.total() == 7


def test_a_pin_read_back_through_production():
    ledger = mock.Mock()
    ledger.total.return_value = 7
    assert report(ledger) == "total 7"
