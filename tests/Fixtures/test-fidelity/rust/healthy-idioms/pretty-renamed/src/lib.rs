//! `pretty_assertions` is another crate here, renamed by Cargo (G5-07):
//! its `assert_eq` and `assert_ne` may compare anything, so neither is
//! read as an assertion. The real crate, in `pretty`, still is.

#[cfg(test)]
mod tests {
    use pretty_assertions::{assert_eq, assert_ne};

    #[test]
    fn a_renamed_crate() {
        assert_ne!(1, 2);
        assert_eq!(3, 3);
    }
}
