//! An `extern crate … as pretty_assertions` puts another crate under the
//! name (G5-07): its comparisons are not read as assertions.

#[cfg(test)]
extern crate other_assertions as pretty_assertions;

#[cfg(test)]
mod tests {
    use pretty_assertions::{assert_eq, assert_ne};

    #[test]
    fn a_crate_under_another_name() {
        assert_ne!(1, 2);
        assert_eq!(3, 3);
    }
}
