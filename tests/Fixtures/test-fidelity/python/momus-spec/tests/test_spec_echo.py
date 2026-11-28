from unittest import mock

from ledger import Ledger, report


def test_a_specced_mock_echo():
    ledger = mock.Mock(spec=Ledger)
    ledger.total.return_value = 7
    assert ledger.total() == 7


def test_an_autospecced_mock_echo():
    ledger = mock.create_autospec(Ledger)
    ledger.total.return_value = 7
    assert ledger.total() == 7


def test_a_specced_pin_read_back_through_production():
    ledger = mock.Mock(spec=Ledger)
    ledger.total.return_value = 7
    assert report(ledger) == "total 7"
