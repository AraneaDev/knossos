//! Another crate's macro brought in by a glob under the name of an
//! assertion macro the standard prelude does not give, so no ambiguity
//! (E0659) stops it, and it declares the name the assertion reads
//! (re-review 5, I7).

/// A limit only this package declares, which the imported macro shadows.
pub const LIMIT: u32 = 3;

#[cfg(test)]
mod tests {
    use super::*;
    use healthy_idioms::standins::*;

    #[test]
    fn a_glob_imported_assertion_name() {
        prop_assert!();
        assert_eq!(LIMIT, 10);
    }
}
