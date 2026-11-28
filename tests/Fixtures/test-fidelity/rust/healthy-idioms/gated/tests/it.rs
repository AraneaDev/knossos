//! Names the library's test-only mock from an integration test.

use gated::MockPort;

#[test]
fn a_test_only_mock_from_another_crate() {
    let mut port = MockPort::new();
    port.expect_read().return_const(5u32);
    assert_eq!(port.read(), 5);
}
