use tautology::ledger::Ledger;

#[test]
fn a_planted_constant() {
    assert_ne!(3, 10);
}

#[test]
fn a_constant_compared_with_production() {
    assert_ne!(Ledger::default().add(3), 10);
}
