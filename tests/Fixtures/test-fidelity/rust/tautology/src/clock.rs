use mockall::automock;

/// A clock the tests mock.
#[automock]
pub trait Clock {
    fn now(&self) -> u64;
}

/// The time since `start`, by `clock`.
pub fn elapsed(clock: &dyn Clock, start: u64) -> u64 {
    clock.now() - start
}
