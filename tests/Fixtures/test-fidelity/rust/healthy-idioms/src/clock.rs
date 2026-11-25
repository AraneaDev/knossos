//! A clock and the code that reads it, which the tests mock with mockall.

use std::sync::{Arc, Mutex};

/// Where the time comes from.
#[cfg_attr(test, mockall::automock)]
pub trait Clock {
    /// The time now.
    fn now(&self) -> u64;
    /// The offset of a zone.
    fn offset(&self, zone: u32) -> u64;
    /// The zone used when none is given.
    fn default_zone() -> u32;
}

/// A clock that must be waited on.
#[cfg_attr(test, mockall::automock)]
pub trait Ticker {
    /// The next tick.
    async fn tick(&self) -> u64;
}

/// How long since `since`.
pub fn elapsed(clock: &dyn Clock, since: u64) -> u64 {
    clock.now() - since
}

/// The time in `zone`.
pub fn shifted(clock: &dyn Clock, zone: u32) -> u64 {
    clock.now() + clock.offset(zone)
}

/// The zone a clock type uses by default.
pub fn zone_of<C: Clock>() -> u32 {
    C::default_zone()
}

/// The time a shared clock reads.
pub fn read_shared<C: Clock>(clock: &Arc<Mutex<C>>) -> u64 {
    clock.lock().map(|clock| clock.now()).unwrap_or(0)
}

/// The next tick, waited on.
pub async fn wait_tick(ticker: &dyn Ticker) -> u64 {
    ticker.tick().await
}

/// A status code fetched from `url`.
pub fn fetch_status(url: &str) -> u16 {
    if url.is_empty() {
        0
    } else {
        200
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use mockall::predicate::eq;

    #[test]
    fn an_expectation_with_matchers_and_a_computed_value() {
        let mut clock = MockClock::new();
        clock.expect_now().return_const(0u64);
        clock
            .expect_offset()
            .with(eq(1))
            .times(1)
            .returning(|zone| u64::from(zone) * 60);
        assert_eq!(shifted(&clock, 1), 60);
    }

    #[test]
    fn a_mock_handed_to_production() {
        let mut clock = MockClock::new();
        clock.expect_now().return_const(5u64);
        assert_eq!(elapsed(&clock, 2), 3);
    }

    #[test]
    fn two_expectations_on_one_member() {
        let mut clock = MockClock::new();
        clock.expect_now().times(1).return_const(5u64);
        clock.expect_now().return_const(6u64);
        assert_eq!(elapsed(&clock, 0) + elapsed(&clock, 0), 11);
    }

    #[test]
    fn a_static_context() {
        let context = MockClock::default_zone_context();
        context.expect().return_const(2u32);
        assert_eq!(zone_of::<MockClock>(), 2);
        assert_eq!(MockClock::default_zone(), 2);
    }

    #[test]
    fn a_mock_shared_behind_a_mutex() {
        let mut clock = MockClock::new();
        clock.expect_now().return_const(9u64);
        let shared = Arc::new(Mutex::new(clock));
        assert_eq!(read_shared(&shared), 9);
    }

    #[tokio::test]
    async fn an_async_member_awaited() {
        let mut ticker = MockTicker::new();
        ticker.expect_tick().return_const(4u64);
        assert_eq!(wait_tick(&ticker).await, 4);
    }

    #[test]
    #[should_panic(expected = "no clock")]
    fn a_pinned_mock_a_panicking_test_never_calls() {
        let mut clock = MockClock::new();
        clock.expect_now().return_const(1u64);
        panic!("no clock");
    }

    // Drift the compiler rejects (E0599, E0061, E0308): no finding.

    #[test]
    fn a_ghost_member() {
        let mut clock = MockClock::new();
        clock.expect_gone().return_const(1u64);
        assert_eq!(elapsed(&clock, 0), 1);
    }

    #[test]
    fn a_wrong_matcher_count() {
        let mut clock = MockClock::new();
        clock.expect_offset().with(eq(1), eq(2)).return_const(1u64);
        assert_eq!(shifted(&clock, 1), 1);
    }

    #[test]
    fn a_wrong_return_type() {
        let mut clock = MockClock::new();
        clock.expect_now().return_const("soon");
        assert_eq!(elapsed(&clock, 0), 1);
    }

    #[test]
    fn a_server_mock_never_asserted() {
        let mut server = mockito::Server::new();
        let _mock = server.mock("GET", "/").with_body("ok").create();
        assert_eq!(fetch_status(&server.url()), 200);
    }
}

/// Each planted finding beside its healthy twin.
#[cfg(test)]
mod planted {
    use super::*;

    #[test]
    fn a_mock_echo() {
        let mut clock = MockClock::new();
        clock.expect_now().return_const(5u64);
        assert_eq!(clock.now(), 5);
    }

    #[test]
    fn a_mock_echo_twin() {
        let mut clock = MockClock::new();
        clock.expect_now().return_const(5u64);
        assert_eq!(elapsed(&clock, 0), 5);
    }

    #[test]
    fn mock_only() {
        let mut clock = MockClock::new();
        clock.expect_now().return_const(4u64);
        assert!(clock.now().is_power_of_two());
    }

    #[test]
    fn mock_only_twin() {
        let mut clock = MockClock::new();
        clock.expect_now().return_const(4u64);
        assert!(elapsed(&clock, 0).is_power_of_two());
    }

    #[test]
    fn zero_reach() {
        let mut clock = MockClock::new();
        clock.expect_now().return_const(4u64);
    }

    #[test]
    fn zero_reach_twin() {
        let mut clock = MockClock::new();
        clock.expect_now().return_const(4u64);
        elapsed(&clock, 0);
    }
}
