//! Limits other files compare against.

/// The largest batch.
pub const MAX: u32 = 10;

/// The largest batch, computed.
pub fn max() -> u32 {
    MAX
}

/// Declares the largest batch as `LIMIT` where it is invoked, for other
/// crates to import.
#[macro_export]
macro_rules! declare_max {
    () => {
        const LIMIT: u32 = $crate::limits::MAX;
    };
}
