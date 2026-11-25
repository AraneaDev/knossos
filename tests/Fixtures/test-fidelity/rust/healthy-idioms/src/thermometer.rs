//! A thermometer and the code that reads it, which the tests fake by hand.

/// Where a temperature comes from.
pub trait Thermometer {
    /// The temperature now.
    fn read(&self) -> i32;
}

/// Whether it is freezing.
pub fn freezing(thermometer: &dyn Thermometer) -> bool {
    thermometer.read() <= 0
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::cell::Cell;

    /// A fixed temperature.
    struct Fixed;

    impl Thermometer for Fixed {
        fn read(&self) -> i32 {
            -5
        }
    }

    /// A thermometer that warms on every read.
    struct Warming {
        next: Cell<i32>,
    }

    impl Thermometer for Warming {
        fn read(&self) -> i32 {
            let now = self.next.get();
            self.next.set(now + 1);
            now
        }
    }

    #[test]
    fn a_fake_handed_to_production() {
        assert!(freezing(&Fixed));
    }

    #[test]
    fn a_stateful_fake() {
        let thermometer = Warming { next: Cell::new(1) };
        assert_eq!(thermometer.read(), 1);
        assert_eq!(thermometer.read(), 2);
    }

    /// Planted: the fake's pinned value compared with itself (`mock_echo`),
    /// the twin of `a_fake_handed_to_production`.
    #[test]
    fn planted_a_fake_echo() {
        assert_eq!(Fixed.read(), -5);
    }
}
