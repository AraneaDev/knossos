from ledger import Ledger


def test_a_planted_constant():
    assert 3 < 10


def test_a_constant_compared_with_production():
    assert Ledger().add(3) < 10
