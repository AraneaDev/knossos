from ledger import Ledger


def test_a_planted_identity_self_comparison():
    ledger = Ledger()
    assert ledger is ledger


def test_an_identity_comparison_with_production():
    ledger = Ledger()
    assert ledger.itself() is ledger
