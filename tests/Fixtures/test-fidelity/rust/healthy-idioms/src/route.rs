//! A route by leg, which the tests mock with mockall and match by case.

/// How a shipment travels.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Leg {
    /// By air.
    Air,
    /// By sea.
    Sea,
}

/// Where shipments are sent.
#[cfg_attr(test, mockall::automock)]
pub trait Router {
    /// Send one leg; whether it was taken.
    fn reroute(&self, to: Leg) -> bool;
}

/// Send a shipment by the given leg.
pub fn send(router: &dyn Router, to: Leg) -> bool {
    router.reroute(to)
}

#[cfg(test)]
mod tests {
    use super::*;
    use mockall::predicate::eq;

    /// Healthy (G3-19): an enum's case pinned in a matcher.
    #[test]
    fn a_case_pinned_in_a_matcher() {
        let mut router = MockRouter::new();
        router.expect_reroute().with(eq(Leg::Sea)).times(1).return_const(true);
        assert!(send(&router, Leg::Sea));
    }
}
