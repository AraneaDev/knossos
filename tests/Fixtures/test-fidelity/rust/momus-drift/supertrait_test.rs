// Mirrors mock_derive's advanced_traits.rs (docs/11 row 80): `trait Derived : Base` inherits
// `add` from `Base`, so a mock of `Derived` stubbing `add` must NOT false-flag DRIFT-001 —
// the member is inherited, not missing.
use crate::supertrait::Derived;

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn mock_derived() {
        let mut mock = MockDerived::new();
        mock.expect_add().returning(|x| x as usize);
        mock.expect_sub().returning(|x| x as usize);
        assert_eq!(2, mock.add(2));
        assert_eq!(2, mock.sub(2));
    }
}
