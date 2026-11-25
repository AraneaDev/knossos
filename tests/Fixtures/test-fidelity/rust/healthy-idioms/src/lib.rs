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

/// A batch size the tests take from production instead.
#[cfg(not(test))]
const BATCH: u32 = 1;
/// A batch size the tests take from production instead.
#[cfg(test)]
const BATCH: u32 = limits::MAX;

/// A cap the tests import from production instead.
#[cfg(not(test))]
const CAP: u32 = 1;
#[cfg(test)]
use limits::MAX as CAP;

/// Declares the production limit where it is invoked: `macro_rules!` items
/// are unhygienic, so it shadows the file's own constant.
macro_rules! production_limit {
    () => {
        const LOCAL_LIMIT: u32 = crate::limits::MAX;
    };
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_same_file_constant_compared_with_production() {
        assert_eq!(LOCAL_LIMIT, add(1, 2));
    }

    #[test]
    fn a_block_import_shadows_the_file_constant() {
        use crate::limits::MAX as LOCAL_LIMIT;
        assert_eq!(LOCAL_LIMIT, 10);
    }

    #[test]
    fn a_block_constant_shadows_the_file_constant() {
        const LOCAL_LIMIT: u32 = crate::limits::MAX;
        assert_eq!(LOCAL_LIMIT, 10);
    }

    #[test]
    fn a_cfg_alternative_constant() {
        assert_eq!(BATCH, 10);
    }

    #[test]
    fn a_cfg_alternative_import() {
        assert_eq!(CAP, 10);
    }

    #[test]
    fn a_macro_declares_the_name_unhanded() {
        production_limit!();
        assert_eq!(LOCAL_LIMIT, 10);
    }

    #[test]
    fn a_literal_compared_with_production() {
        assert_eq!(add(1, 1), 2);
    }
}
