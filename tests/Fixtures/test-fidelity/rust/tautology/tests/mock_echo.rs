use tautology::clock::{elapsed, Clock, MockClock};

#[test]
fn a_planted_mock_echo() {
    let mut clock = MockClock::new();
    clock.expect_now().return_const(7u64);
    assert_eq!(clock.now(), 7);
}

#[test]
fn a_pin_read_back_through_production() {
    let mut clock = MockClock::new();
    clock.expect_now().return_const(7u64);
    assert_eq!(elapsed(&clock, 0), 7);
}
