//! A trait mocked for the library's own tests only: the integration test
//! in `tests/` is another crate, where `MockPort` does not exist, so it
//! gives no double (Task 3 review, I4). The package is named like its
//! directory, as the review's probe was.

/// Where a value is read from.
#[cfg_attr(test, mockall::automock)]
pub trait Port {
    /// The value.
    fn read(&self) -> u32;
}
