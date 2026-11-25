//! Macros loaded by `#[path]` from outside the project root, which the scan
//! never reads: one of them may be a `macro_rules! dbg` that declares the
//! name the assertion reads (re-review 5, deferred note 1).

#[macro_use]
#[path = "../../../shared-macros/limit_dbg.rs"]
mod beyond;

/// A limit only this package declares, which an unseen macro may shadow.
pub const LIMIT: u32 = 3;

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_path_file_outside_the_project() {
        dbg!();
        assert_eq!(LIMIT, 10);
    }
}
