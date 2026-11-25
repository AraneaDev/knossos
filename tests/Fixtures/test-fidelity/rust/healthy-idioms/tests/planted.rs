//! Each planted finding beside its healthy twin, which compares with
//! production instead.

use healthy_idioms::counter;

#[test]
fn two_constants() {
    assert_ne!(1, 2);
}

#[test]
fn a_constant_against_production() {
    assert_ne!(counter::next(1), 2 + 1);
}

#[test]
fn a_literal_compared_with_itself() {
    assert_eq!(1, 1);
}

#[test]
fn a_literal_against_production() {
    assert_eq!(counter::next(0), 1);
}
