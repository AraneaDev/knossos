//! Macros `src/lib.rs` loads by `#[path]`, from outside `src/`.
//!
//! No package can be shown to own a file loaded this way, so this
//! `macro_rules! todo` shadows `todo!` in every member package too (phase 5
//! Task 3 review, M6). A member's twin of a shadowing macro must use another
//! name (`unimplemented!`, `eprint!`), or it passes vacuously.

/// Declares the production limit where it is invoked, under the name of
/// `std::todo!`.
macro_rules! todo {
    () => {
        const LOCAL_LIMIT: u32 = crate::limits::MAX;
    };
}
