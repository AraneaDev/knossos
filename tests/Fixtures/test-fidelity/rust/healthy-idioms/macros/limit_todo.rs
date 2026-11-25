//! Macros `src/lib.rs` loads by `#[path]`, from outside `src/`.

/// Declares the production limit where it is invoked, under the name of
/// `std::todo!`.
macro_rules! todo {
    () => {
        const LOCAL_LIMIT: u32 = crate::limits::MAX;
    };
}
