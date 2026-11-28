//! Hand-written fakes whose tests run no production (G5-10 M4): one named
//! only as a type argument, which code out of the walk's sight makes and
//! calls, is no zero-reach fake. Planted: a pinned fake nothing names.

/// Where a reading comes from.
pub trait Gauge {
    /// The reading now.
    fn read(&self) -> i32;
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Made only by `reading::<Named>()`.
    #[derive(Default)]
    struct Named;

    impl Gauge for Named {
        fn read(&self) -> i32 {
            4
        }
    }

    /// Planted: pinned, and named by nothing (`zero_reach`).
    struct Unnamed;

    impl Gauge for Unnamed {
        fn read(&self) -> i32 {
            9
        }
    }

    /// A reading of a fresh `G`.
    fn reading<G: Gauge + Default>() -> i32 {
        G::default().read()
    }

    #[test]
    fn a_fake_named_as_a_type_argument() {
        assert!(reading::<Named>() > 0);
    }
}
