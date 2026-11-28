from unittest import mock

from ledger import Ledger, report


# The healthy twin of `test_zero_reach.py`, in a file of its own: a double
# answers to every test of its file, so a test that runs production beside
# the planted stub would silence it.
def test_a_stub_production_reaches():
    ledger = mock.Mock(spec=Ledger)
    ledger.total.return_value = 7
    assert report(ledger) == "total 7"
