use mockall::automock;

#[automock]
trait Clock {
    fn now(&self) -> u64;
}

#[test]
fn automock_experiment() {
    let mut clock = MockClock::new();
    clock.expect_now().returning(|| 1);
    assert_eq!(clock.now(), 1);
}
