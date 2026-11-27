//! A package whose module tree holds another package's manifest, reached
//! by plain `#[macro_use] mod`s (G5-04): the outer crate compiles
//! `fixtures/inner/mod.rs`, whose `eprint!` declares `LIMIT`. Which
//! package that file is of cannot be shown, so its macros shadow the
//! standard ones for every package.

#[macro_use]
mod fixtures;

/// A limit the inner package's macro shadows.
pub const LIMIT: u32 = 10;

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_macro_of_a_nested_package() {
        eprint!();
        assert_eq!(LIMIT, 20);
    }
}
