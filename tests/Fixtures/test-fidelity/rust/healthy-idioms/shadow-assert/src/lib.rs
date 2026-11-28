//! A project macro named `assert_eq`, which asserts on production rather
//! than on its arguments: neither of its uses is an assertion the worker
//! reads (Task 3 review, I2).

/// Where the time comes from.
#[cfg_attr(test, mockall::automock)]
pub trait Clock {
    /// The time now.
    fn now(&self) -> u64;
}

/// Twice the time.
pub fn twice(clock: &dyn Clock) -> u64 {
    clock.now() * 2
}

#[cfg(test)]
mod tests {
    use super::*;

    macro_rules! assert_eq {
        ($a:expr, $b:expr) => {
            ::std::assert_eq!(twice(&clock_for($a)), $b * 2)
        };
    }

    fn clock_for(value: u64) -> MockClock {
        let mut clock = MockClock::new();
        clock.expect_now().return_const(value);
        clock
    }

    #[test]
    fn a_shadowed_assertion_on_a_mock() {
        let mut clock = MockClock::new();
        clock.expect_now().return_const(42u64);
        assert_eq!(clock.now(), 42);
    }

    #[test]
    fn a_shadowed_assertion_on_literals() {
        assert_eq!(5, 5);
    }
}
