from unittest import mock

from ledger import Ledger, deposit

LEDGER = Ledger()


def test_a_planted_unconfigured_spy():
    spy = mock.Mock(wraps=LEDGER)
    spy.add.assert_called_once_with(3)


def test_a_spy_production_calls():
    spy = mock.Mock(wraps=LEDGER)
    deposit(spy, 3)
    spy.add.assert_called_once_with(3)
