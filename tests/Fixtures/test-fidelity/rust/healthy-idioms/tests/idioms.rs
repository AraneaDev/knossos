//! Healthy test idioms whose assertions only look as if they cannot fail:
//! each operand is written where a literal does not show it.

use std::cell::{Cell, RefCell};
use std::sync::atomic::{AtomicU32, Ordering};
use std::sync::Mutex;

use healthy_idioms::counter;
use healthy_idioms::limits::MAX;
use healthy_idioms::version::Version;
use rstest::rstest;

/// Bumped by the tests that count their calls.
static mut HITS: u32 = 0;

/// A constant of this file, compared with production.
const EXPECTED_NEXT: u32 = 2;

/// Rebinds the name it is handed.
macro_rules! bump {
    ($name:ident) => {
        let $name = $name + 1;
    };
}

#[test]
#[should_panic]
fn nan_is_not_equal_to_itself() {
    let x = f64::NAN;
    assert_eq!(x, x);
}

#[test]
fn equality_under_test_is_reflexive() {
    let v = Version::new(1);
    assert_eq!(v, v);
}

#[test]
fn a_mutable_counter() {
    let mut count = 0;
    for _ in 0..3 {
        count += 1;
    }
    assert_eq!(count, 3);
}

#[test]
fn shadowed_at_the_top() {
    let n = 1;
    let n = counter::next(n);
    assert_eq!(n, 2);
}

#[test]
fn shadowed_in_a_nested_block() {
    let n = 1;
    {
        let n = counter::next(n);
        assert_eq!(n, 2);
    }
}

#[test]
fn shadowed_by_a_closure_parameter() {
    let n = 1;
    let check = |n: u32| assert_eq!(n, 2);
    check(counter::next(n));
}

#[test]
fn shadowed_by_a_match_arm() {
    let n = 1;
    match counter::next(n) {
        n => assert_eq!(n, 2),
    }
}

#[test]
fn a_macro_handed_the_name() {
    let n = 1;
    bump!(n);
    assert_eq!(n, 2);
}

#[test]
fn written_through_unsafe() {
    let n = 1;
    unsafe {
        std::ptr::write(&n as *const u32 as *mut u32, 2);
    }
    assert_eq!(n, 2);
}

#[test]
fn interior_mutability() {
    let cell = Cell::new(1);
    cell.set(2);
    assert_eq!(cell.get(), 2);
    let shared = RefCell::new(1);
    *shared.borrow_mut() = 2;
    assert_eq!(*shared.borrow(), 2);
    let guarded = Mutex::new(1);
    *guarded.lock().unwrap() = 2;
    assert_eq!(*guarded.lock().unwrap(), 2);
    let atomic = AtomicU32::new(1);
    atomic.store(2, Ordering::SeqCst);
    assert_eq!(atomic.load(Ordering::SeqCst), 2);
}

#[test]
fn a_static_mut() {
    unsafe {
        HITS += 1;
        assert_eq!(HITS, 1);
    }
}

#[test]
fn a_constant_of_another_file() {
    assert_eq!(MAX, 10);
}

#[test]
fn a_constant_compared_with_production() {
    assert_eq!(EXPECTED_NEXT, counter::next(1));
}

#[test]
fn asserting_true() {
    assert!(true);
}

#[test]
fn arithmetic_on_literals() {
    assert_eq!(1 + 1, 2);
}

proptest::proptest! {
    #[test]
    fn inside_proptest(n in 0u32..10) {
        assert_eq!(1, 1);
        assert!(counter::next(n) > n);
    }
}

#[rstest]
#[case(1, 2)]
#[case(2, 3)]
fn a_case_parameter(#[case] n: u32, #[case] expected: u32) {
    assert_eq!(expected, 2);
    assert_eq!(counter::next(n), expected);
}

#[test]
fn a_let_after_the_assertion() {
    let total = counter::count(&[1, 2, 3]);
    assert_eq!(total, 3);
    let total = 3;
    assert_eq!(total, counter::count(&[4, 5, 6]));
}

#[tokio::test]
async fn an_async_test() {
    let n = counter::next(0);
    assert_eq!(n, 1);
}
