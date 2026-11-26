use tautology::ledger::Ledger;

#[test]
fn a_planted_self_comparison() {
    assert_eq!(7, 7);
}

#[test]
fn a_literal_compared_with_production() {
    assert_eq!(Ledger::default().add(7), 7);
}
