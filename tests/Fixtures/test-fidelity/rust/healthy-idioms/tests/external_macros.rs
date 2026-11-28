//! A standard macro's name given to another crate's macro, which declares
//! the name the assertion reads where it is invoked (ruling R3, widened).

use healthy_idioms::declare_max as todo;

/// A limit only this file declares, which the imported macro shadows.
const LIMIT: u32 = 3;

#[test]
fn a_standard_name_another_crates_macro_is_imported_under() {
    todo!();
    assert_eq!(LIMIT, 10);
}
