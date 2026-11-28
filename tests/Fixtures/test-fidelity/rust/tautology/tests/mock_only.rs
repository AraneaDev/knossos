use tautology::clock::{elapsed, Clock, MockClock};

#[test]
fn a_planted_mock_only_assertion() {
    let mut clock = MockClock::new();
    clock.expect_now().return_const(4u64);
    assert!(clock.now().is_power_of_two());
}

#[test]
fn a_double_production_calls() {
    let mut clock = MockClock::new();
    clock.expect_now().return_const(4u64);
    assert!(elapsed(&clock, 0).is_power_of_two());
}
