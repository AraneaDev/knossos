use tautology::clock::{elapsed, MockClock};

#[test]
fn a_planted_stub_nothing_reaches() {
    let mut clock = MockClock::new();
    clock.expect_now().return_const(4u64);
}

#[test]
fn a_stub_production_reaches() {
    let mut clock = MockClock::new();
    clock.expect_now().return_const(4u64);
    elapsed(&clock, 0);
}
