from unittest import mock

from ledger import Ledger


# Every test of this file runs no production, so nothing can reach the pin.
# The double is spec'd: an unspec'd `Mock` records no members (Ruling P10).
def test_a_planted_stub_nothing_reaches():
    ledger = mock.Mock(spec=Ledger)
    ledger.total.return_value = 7
