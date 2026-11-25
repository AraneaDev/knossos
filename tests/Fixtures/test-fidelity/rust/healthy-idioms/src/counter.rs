//! A counter the tests drive.

/// The value after `n`.
pub fn next(n: u32) -> u32 {
    n + 1
}

/// How many items `items` holds.
pub fn count(items: &[u32]) -> u32 {
    items.len() as u32
}
