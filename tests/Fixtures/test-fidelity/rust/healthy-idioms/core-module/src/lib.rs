//! A local module named `core` that re-exports another crate's macros by a
//! glob: `core::unimplemented!` is then that crate's, and a path-qualified
//! macro is no glob import, so no ambiguity (E0659) stops it (re-review 5,
//! I7).

/// Stands in for the standard `core`.
mod core {
    pub use healthy_idioms::standins::*;
}

/// A limit only this package declares, which the imported macro shadows.
pub const LIMIT: u32 = 3;

#[cfg(test)]
mod tests {
    use super::core;
    use super::LIMIT;

    #[test]
    fn a_local_module_named_core() {
        core::unimplemented!();
        assert_eq!(LIMIT, 10);
    }
}
