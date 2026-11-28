//! The inner package's root, which the outer crate also compiles.

/// Declares `LIMIT` where it is invoked.
#[allow(unused_macros)]
macro_rules! eprint {
    ($($t:tt)*) => {
        const LIMIT: u32 = 20;
    };
}
