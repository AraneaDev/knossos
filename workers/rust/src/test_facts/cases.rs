//! Which functions of a file are test cases (Decision 6).
//!
//! A case is a `fn` with an attribute whose path ends in `test`
//! (`#[test]`, `#[tokio::test]`, `#[sqlx::test]`…), or with `#[rstest]`,
//! `#[test_case(…)]` (one case per `fn`, not per row) or
//! `#[wasm_bindgen_test]`. Only items the file declares count: a test
//! inside a macro invocation (`proptest! {}`) is tokens, never expanded,
//! and `#[bench]` and doctests are not read.

use crate::resolve::ident_name;
use crate::visit::is_test_attribute;

/// The attribute names besides a `…::test` path that make a case.
const CASE_ATTRIBUTES: [&str; 3] = ["rstest", "test_case", "wasm_bindgen_test"];

/// One test function, where it sits, and what its attributes say.
pub(super) struct TestFunction<'f> {
    /// The function.
    pub(super) function: &'f syn::ItemFn,
    /// The module path from the file's module to the function:
    /// `tests::adds`, or `smoke` at the file's top.
    pub(super) name: String,
    /// The canonical path of the module the function is declared in.
    pub(super) container: String,
    /// Whether it carries `#[should_panic]`.
    pub(super) should_panic: bool,
}

/// The test functions of `items`, the items of the file placed in `module`,
/// inline modules included, in source order.
pub(super) fn test_functions<'f>(items: &'f [syn::Item], module: &str) -> Vec<TestFunction<'f>> {
    let mut found = Vec::new();
    collect(items, module, "", &mut found);
    found
}

/// [`test_functions`] for the items of `container`, `prefix` its path below
/// the file's module (empty at the top, else ending in `::`).
fn collect<'f>(
    items: &'f [syn::Item],
    container: &str,
    prefix: &str,
    found: &mut Vec<TestFunction<'f>>,
) {
    for item in items {
        match item {
            syn::Item::Fn(function) if is_case(&function.attrs) => {
                found.push(TestFunction {
                    function,
                    name: format!("{prefix}{}", ident_name(&function.sig.ident)),
                    container: container.to_owned(),
                    should_panic: function
                        .attrs
                        .iter()
                        .any(|attr| attr.path().is_ident("should_panic")),
                });
            }
            syn::Item::Mod(module) => {
                if let Some((_, inner)) = &module.content {
                    let name = ident_name(&module.ident);
                    collect(
                        inner,
                        &format!("{container}::{name}"),
                        &format!("{prefix}{name}::"),
                        found,
                    );
                }
            }
            _ => {}
        }
    }
}

/// Whether a function's attributes make it a test case.
fn is_case(attrs: &[syn::Attribute]) -> bool {
    is_test_attribute(attrs)
        || attrs.iter().any(|attr| {
            attr.path()
                .segments
                .last()
                .is_some_and(|segment| CASE_ATTRIBUTES.iter().any(|name| segment.ident == name))
        })
}
