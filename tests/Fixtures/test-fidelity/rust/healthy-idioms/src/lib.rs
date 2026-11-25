//! Production code the healthy test idioms exercise.

pub mod counter;
pub mod limits;
pub mod version;

/// The sum of two numbers.
pub fn add(a: u32, b: u32) -> u32 {
    a + b
}

/// A limit only this file declares.
const LOCAL_LIMIT: u32 = 3;

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_same_file_constant_compared_with_production() {
        assert_eq!(LOCAL_LIMIT, add(1, 2));
    }

    #[test]
    fn a_literal_compared_with_production() {
        assert_eq!(add(1, 1), 2);
    }
}
