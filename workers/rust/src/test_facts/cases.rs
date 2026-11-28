//! Which functions of a file are test cases (Decision 6).
//!
//! A case is a `fn` with an attribute whose path ends in `test`
//! (`#[test]`, `#[tokio::test]`, `#[sqlx::test]`…), or with `#[rstest]`,
//! `#[test_case(…)]` (one case per `fn`, not per row) or
//! `#[wasm_bindgen_test]`. Only items the file declares count: a test
//! inside a macro invocation (`proptest! {}`) is tokens, never expanded,
//! and `#[bench]` and doctests are not read.

use crate::resolve::ident_name;
use crate::visit::{is_cfg_test, is_test_attribute};

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

/// A function of test code that is no case: a helper a double may be made
/// in, with the module it is declared in.
pub(super) struct Helper<'f> {
    /// Its signature.
    pub(super) signature: &'f syn::Signature,
    /// Its body.
    pub(super) block: &'f syn::Block,
    /// The canonical path of the module it is declared in.
    pub(super) container: String,
}

/// The helpers of `items`, in `container`, inline modules and `impl`
/// blocks included: every function no case is, when `test` (the file is
/// test code) or a `cfg` requiring `test` gates it or a module around it.
pub(super) fn helpers<'f>(items: &'f [syn::Item], container: &str, test: bool) -> Vec<Helper<'f>> {
    let mut found = Vec::new();
    for item in items {
        match item {
            syn::Item::Fn(function) if !is_case(&function.attrs) => {
                if test || is_cfg_test(&function.attrs) {
                    found.push(Helper {
                        signature: &function.sig,
                        block: &function.block,
                        container: container.to_owned(),
                    });
                }
            }
            syn::Item::Impl(block) => {
                let gated = test || is_cfg_test(&block.attrs);
                for member in &block.items {
                    if let syn::ImplItem::Fn(method) = member {
                        if gated || is_cfg_test(&method.attrs) {
                            found.push(Helper {
                                signature: &method.sig,
                                block: &method.block,
                                container: container.to_owned(),
                            });
                        }
                    }
                }
            }
            syn::Item::Mod(module) => {
                if let Some((_, inner)) = &module.content {
                    let nested = format!("{container}::{}", ident_name(&module.ident));
                    let gated = test || is_cfg_test(&module.attrs);
                    found.extend(helpers(inner, &nested, gated));
                }
            }
            _ => {}
        }
    }
    found
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
