//! Tests that compare through `pretty_assertions`, whose `assert_eq` and
//! `assert_ne` compare as the standard ones do: they are still read as
//! assertions (Task 3 re-review 1, ruling), so the planted constant beside
//! the healthy test is found.

/// Twice a number.
pub fn double(value: u32) -> u32 {
    value * 2
}

#[cfg(test)]
mod tests {
    use super::*;
    use pretty_assertions::{assert_eq, assert_ne};

    #[test]
    fn compared_with_production() {
        assert_eq!(double(2), 4);
    }

    #[test]
    fn two_constants() {
        assert_ne!(1, 2);
    }
}
