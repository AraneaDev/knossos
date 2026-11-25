//! Scanning tests of the same-file constants an operand may name: how a
//! name is followed through imports and globs, and every declaration that
//! leaves it unknown (a gated or twice-declared module or constant, a `cfg`
//! import, an item-position macro, a project macro named like a standard
//! one).

mod common;

use common::*;
#[test]
fn a_constant_in_the_case_module_wins_over_a_glob() {
    // An ungated module's own constant wins over the glob; under a `cfg`
    // (here the usual `cfg(test)`) a module may have another build's twin,
    // so its constants hold no literal (re-review I3).
    let lib = "const LIMIT: u32 = 10;\nmod checks {\n    use super::*;\n    const LIMIT: u32 = 20;\n    #[test]\n    fn own() {\n        assert_eq!(LIMIT, 20);\n    }\n}\n#[cfg(test)]\nmod tests {\n    use super::*;\n    const LIMIT: u32 = 20;\n    #[test]\n    fn gated() {\n        assert_eq!(LIMIT, 20);\n    }\n}\n";
    let found = first_operands("literals-own-module", &[("src/lib.rs", lib)]);

    assert_eq!(
        vec![
            ("checks::own".to_owned(), literal("20")),
            ("tests::gated".to_owned(), unknown()),
        ],
        found
    );
}

#[test]
fn constants_of_a_gated_or_twice_declared_module_hold_no_literal() {
    let lib = "pub mod limits;\n#[cfg(not(test))]\nmod caps {\n    pub const CAP: u32 = 1;\n}\n#[cfg(test)]\nmod caps {\n    pub use crate::limits::*;\n}\nmod twice {\n    pub const TWICE: u32 = 1;\n}\nmod twice {}\nmod plain {\n    pub const PLAIN: u32 = 3;\n    #[cfg(unix)]\n    pub mod deep {\n        pub mod deeper {\n            pub const DEEP: u32 = 4;\n        }\n    }\n}\npub mod host {\n    #[cfg(not(test))]\n    pub mod inner {\n        pub const CAP: u32 = 1;\n    }\n    #[cfg(test)]\n    pub mod inner {\n        pub use crate::limits::*;\n    }\n    #[cfg(test)]\n    mod tests {\n        use super::inner::*;\n        #[test]\n        fn sibling_via_super() {\n            assert_eq!(CAP, 20);\n        }\n    }\n}\n#[cfg(test)]\nmod sib_explicit {\n    use crate::caps::CAP;\n    #[test]\n    fn gated_glob_module() {\n        assert_eq!(CAP, 20);\n    }\n}\n#[cfg(test)]\nmod sib_glob {\n    use super::caps::*;\n    #[test]\n    fn gated_glob_module_by_glob() {\n        assert_eq!(CAP, 20);\n    }\n}\n#[cfg(test)]\nmod others {\n    use crate::plain::PLAIN;\n    use crate::twice::TWICE;\n    use crate::plain::deep::deeper::DEEP;\n    #[test]\n    fn plain() {\n        assert_eq!(PLAIN, 3);\n    }\n    #[test]\n    fn twice() {\n        assert_eq!(TWICE, 1);\n    }\n    #[test]\n    fn deep() {\n        assert_eq!(DEEP, 4);\n    }\n}\n";
    let found = first_operands(
        "literals-gated-modules",
        &[
            ("src/lib.rs", lib),
            ("src/limits.rs", "pub const LIMIT: u32 = 20;\n"),
        ],
    );

    assert_eq!(
        vec![
            ("host::tests::sibling_via_super".to_owned(), unknown()),
            ("others::deep".to_owned(), unknown()),
            ("others::plain".to_owned(), literal("3")),
            ("others::twice".to_owned(), unknown()),
            ("sib_explicit::gated_glob_module".to_owned(), unknown()),
            ("sib_glob::gated_glob_module_by_glob".to_owned(), unknown()),
        ],
        found
    );
}

#[test]
fn constants_of_a_gated_file_hold_no_literal() {
    let lib = "#[cfg(not(test))]\nmod caps;\n#[cfg(test)]\nmod tests;\n";
    let caps = "pub const CAP: u32 = 1;\n#[test]\nfn capped() {\n    assert_eq!(CAP, 1);\n}\n";
    let tests = "const OWN: u32 = 2;\n#[test]\nfn own() {\n    assert_eq!(OWN, 2);\n}\n";
    let found = first_operands(
        "literals-gated-file",
        &[
            ("src/lib.rs", lib),
            ("src/caps.rs", caps),
            ("src/tests.rs", tests),
        ],
    );

    assert_eq!(
        vec![
            ("capped".to_owned(), unknown()),
            ("own".to_owned(), unknown())
        ],
        found
    );
}

#[test]
fn a_project_macro_named_like_a_frozen_one_may_declare_items() {
    let lib = "const LIMIT: u32 = 10;\n#[cfg(test)]\nmod frozen {\n    use super::*;\n    macro_rules! dbg {\n        () => {\n            const LIMIT: u32 = 20;\n        };\n    }\n    macro_rules! todo {\n        () => {\n            const LIMIT: u32 = 20;\n        };\n    }\n    #[test]\n    fn dbg_named() {\n        dbg!();\n        assert_eq!(LIMIT, 20);\n    }\n    #[test]\n    fn todo_named() {\n        todo!();\n        assert_eq!(LIMIT, 20);\n    }\n}\nmod frozen_module {\n    use super::*;\n    macro_rules! dbg {\n        () => {\n            const LIMIT: u32 = 20;\n        };\n    }\n    dbg!();\n    #[test]\n    fn module_dbg_named() {\n        assert_eq!(LIMIT, 20);\n    }\n}\nmod paths {\n    use super::*;\n    #[test]\n    fn std_path() {\n        let x = 5;\n        std::println!(\"{}\", 1);\n        core::panic!(\"never\");\n        assert_eq!(x, 5);\n    }\n    #[test]\n    fn other_crate_path() {\n        let x = 5;\n        other::write!(out);\n        assert_eq!(x, 5);\n    }\n    #[test]\n    fn rooted_bare() {\n        let x = 5;\n        ::println!(\"{}\", 1);\n        assert_eq!(x, 5);\n    }\n    #[test]\n    fn deep_std_path() {\n        let x = 5;\n        std::io::println!(\"{}\", 1);\n        assert_eq!(x, 5);\n    }\n}\n";
    let elsewhere = "#[macro_export]\nmacro_rules! eprintln {\n    () => {\n        const LIMIT: u32 = 20;\n    };\n}\n";
    let user = "use crate::LIMIT;\n#[test]\nfn eprintln_named_elsewhere() {\n    eprintln!();\n    assert_eq!(LIMIT, 20);\n}\n";
    let found = first_operands(
        "literals-frozen-shadow",
        &[
            (
                "src/lib.rs",
                &(lib.to_owned() + "pub mod macros;\npub mod user;\n"),
            ),
            ("src/macros.rs", elsewhere),
            ("src/user.rs", user),
        ],
    );

    assert_eq!(
        vec![
            ("eprintln_named_elsewhere".to_owned(), unknown()),
            ("frozen::dbg_named".to_owned(), unknown()),
            ("frozen::todo_named".to_owned(), unknown()),
            ("frozen_module::module_dbg_named".to_owned(), unknown()),
            ("paths::deep_std_path".to_owned(), unknown()),
            ("paths::other_crate_path".to_owned(), unknown()),
            ("paths::rooted_bare".to_owned(), unknown()),
            ("paths::std_path".to_owned(), literal("5")),
        ],
        found
    );
}

#[test]
fn a_function_of_the_case_module_hides_a_glob_constant() {
    let lib = "const LIMIT: u32 = 10;\n#[cfg(test)]\nmod tests {\n    use super::*;\n    #[allow(non_snake_case)]\n    fn LIMIT() -> u32 { 10 }\n    #[test]\n    fn hidden() {\n        assert_eq!(LIMIT, 10);\n    }\n}\n";
    let found = first_operands("literals-hidden", &[("src/lib.rs", lib)]);

    assert_eq!(vec![("tests::hidden".to_owned(), unknown())], found);
}

#[test]
fn a_constant_is_followed_through_at_most_eight_imports() {
    let eight = first_operands("literals-chain-eight", &[("src/lib.rs", &glob_chain(8))]);
    let nine = first_operands("literals-chain-nine", &[("src/lib.rs", &glob_chain(9))]);

    assert_eq!(vec![("m0::chained".to_owned(), literal("1"))], eight);
    assert_eq!(vec![("m0::chained".to_owned(), unknown())], nine);
}

#[test]
fn import_cycles_end_unknown() {
    let globs = "mod a {\n    pub use super::b::*;\n    #[test]\n    fn cycled() {\n        assert_eq!(C, 1);\n    }\n}\nmod b {\n    pub use super::a::*;\n}\n";
    let aliases = "mod a {\n    pub use super::b::C;\n}\nmod b {\n    pub use super::a::C;\n}\n#[cfg(test)]\nmod tests {\n    use super::a::C;\n    #[test]\n    fn aliased() {\n        assert_eq!(C, 1);\n    }\n}\n";

    assert_eq!(
        vec![("a::cycled".to_owned(), unknown())],
        first_operands("literals-glob-cycle", &[("src/lib.rs", globs)])
    );
    assert_eq!(
        vec![("tests::aliased".to_owned(), unknown())],
        first_operands("literals-alias-cycle", &[("src/lib.rs", aliases)])
    );
}

#[test]
fn a_constant_declared_twice_holds_no_one_literal() {
    let lib = "#[cfg(not(test))]\nconst BATCH: u32 = 1;\n#[cfg(test)]\nconst BATCH: u32 = crate::other::FAR;\n#[cfg(test)]\nconst RETRIES: u32 = 1;\n#[cfg(not(test))]\nconst RETRIES: u32 = 5;\nconst ONCE: u32 = 7;\n".to_owned()
        + &tests_module(&[
            ("batch", "assert_eq!(BATCH, 3);"),
            ("retries", "assert_eq!(RETRIES, 1);"),
            ("once", "assert_eq!(ONCE, 7);"),
        ]);
    let found = first_operands("literals-repeated", &[("src/lib.rs", &lib)]);

    assert_eq!(
        vec![
            ("tests::batch".to_owned(), unknown()),
            ("tests::once".to_owned(), literal("7")),
            ("tests::retries".to_owned(), unknown()),
        ],
        found
    );
}

#[test]
fn an_item_position_macro_hides_every_constant_of_its_scope() {
    // `macro_rules!` items are unhygienic: `define_twenty!()` declares
    // `LIMIT` where it is invoked, handed the name or not.
    let lib = "const LIMIT: u32 = 10;\nconst OTHER: u32 = 4;\nmacro_rules! define_twenty {\n    () => {\n        const LIMIT: u32 = 20;\n    };\n}\n#[cfg(test)]\nmod tests {\n    use super::*;\n    define_limit!(LIMIT);\n    #[test]\n    fn handed() {\n        assert_eq!(LIMIT, 10);\n    }\n    #[test]\n    fn beside() {\n        assert_eq!(OTHER, 4);\n    }\n}\n#[cfg(test)]\nmod module_macro {\n    use super::*;\n    define_twenty!();\n    #[test]\n    fn unhanded() {\n        assert_eq!(LIMIT, 20);\n    }\n}\n#[cfg(test)]\nmod body_macro {\n    use super::*;\n    macro_rules! read_other {\n        () => { OTHER };\n    }\n    #[test]\n    fn unhanded() {\n        define_twenty!();\n        assert_eq!(LIMIT, 20);\n    }\n    #[test]\n    fn local() {\n        let x = 5;\n        define_twenty!();\n        assert_eq!(x, 5);\n    }\n    #[test]\n    fn itemless() {\n        let x = 5;\n        println!(\"{}\", 1);\n        dbg!(2);\n        assert_eq!(x, 5);\n    }\n    #[test]\n    fn untouched() {\n        assert_eq!(OTHER, 4);\n    }\n}\n";
    let found = first_operands("literals-item-macros", &[("src/lib.rs", lib)]);

    assert_eq!(
        vec![
            ("body_macro::itemless".to_owned(), literal("5")),
            ("body_macro::local".to_owned(), unknown()),
            ("body_macro::unhanded".to_owned(), unknown()),
            ("body_macro::untouched".to_owned(), literal("4")),
            ("module_macro::unhanded".to_owned(), unknown()),
            ("tests::beside".to_owned(), unknown()),
            ("tests::handed".to_owned(), unknown()),
        ],
        found
    );
}

#[test]
fn a_cfg_alternative_import_holds_no_literal() {
    let lib = "pub mod limits;\n#[cfg(not(test))]\nconst CAP: u32 = 1;\n#[cfg(test)]\nuse crate::limits::LIMIT as CAP;\nconst PLAIN: u32 = 3;\n#[cfg(test)]\nmod tests {\n    use super::*;\n    #[test]\n    fn cfg_alt_use() {\n        assert_eq!(CAP, 20);\n    }\n    #[test]\n    fn plain() {\n        assert_eq!(PLAIN, 3);\n    }\n}\npub mod alt2 {\n    #[cfg(not(test))]\n    const SIZE: u32 = 1;\n    #[cfg(test)]\n    use crate::limits::LIMIT as SIZE;\n    #[cfg(not(test))]\n    const DEPTH: u32 = 1;\n    #[cfg(test)]\n    use crate::limits::*;\n    #[cfg(test)]\n    use LIMIT as DEPTH;\n    #[cfg(test)]\n    mod tests {\n        use super::*;\n        #[test]\n        fn cfg_alt_use_in_module() {\n            assert_eq!(SIZE, 20);\n        }\n        #[test]\n        fn cfg_alt_glob_rename() {\n            assert_eq!(DEPTH, 20);\n        }\n    }\n}\n";
    let found = first_operands(
        "literals-cfg-use",
        &[
            ("src/lib.rs", lib),
            ("src/limits.rs", "pub const LIMIT: u32 = 20;\n"),
        ],
    );

    assert_eq!(
        vec![
            ("alt2::tests::cfg_alt_glob_rename".to_owned(), unknown()),
            ("alt2::tests::cfg_alt_use_in_module".to_owned(), unknown()),
            ("tests::cfg_alt_use".to_owned(), unknown()),
            ("tests::plain".to_owned(), literal("3")),
        ],
        found
    );
}

#[test]
fn an_import_beside_an_ungated_constant_of_its_name_holds_no_literal() {
    // Rust refuses the pair in a test build; the scan reads it as written.
    let lib = "pub mod limits;\nconst CAP: u32 = 1;\n#[cfg(test)]\nuse crate::limits::LIMIT as CAP;\n#[cfg(test)]\nmod tests {\n    use super::*;\n    #[test]\n    fn capped() {\n        assert_eq!(CAP, 20);\n    }\n}\n";
    let found = first_operands(
        "literals-ungated-import",
        &[
            ("src/lib.rs", lib),
            ("src/limits.rs", "pub const LIMIT: u32 = 20;\n"),
        ],
    );

    assert_eq!(vec![("tests::capped".to_owned(), unknown())], found);
}

#[test]
fn an_ungated_import_beside_no_constant_is_followed() {
    // `PLAIN` is imported, not declared: the import is followed, as before.
    let lib = "mod inner {\n    pub const PLAIN: u32 = 3;\n    #[cfg(test)]\n    pub static GATED: u32 = 4;\n}\nuse inner::PLAIN;\nuse inner::GATED;\n#[cfg(test)]\nmod tests {\n    use super::*;\n    #[test]\n    fn imported() {\n        assert_eq!(PLAIN, 3);\n    }\n    #[test]\n    fn gated_static() {\n        assert_eq!(GATED, 4);\n    }\n}\n";
    let found = first_operands("literals-import", &[("src/lib.rs", lib)]);

    assert_eq!(
        vec![
            ("tests::gated_static".to_owned(), unknown()),
            ("tests::imported".to_owned(), literal("3")),
        ],
        found
    );
}

#[test]
fn two_globs_that_disagree_leave_the_name_unknown() {
    let lib = "const LIMIT: u32 = 10;\nmod inner {\n    pub(crate) const LIMIT: u32 = 99;\n}\n#[cfg(test)]\nmod tests {\n    use super::*;\n    use super::inner::*;\n    #[test]\n    fn ambiguous() {\n        assert_eq!(LIMIT, 99);\n    }\n}\n";
    let found = first_operands("literals-two-globs", &[("src/lib.rs", lib)]);

    assert_eq!(vec![("tests::ambiguous".to_owned(), unknown())], found);
}

/// `depth` inline modules `m0`…, each glob-importing the next, the last
/// declaring `C`, with a case in `m0` comparing `C`.
fn glob_chain(depth: usize) -> String {
    let mut source = String::new();
    for level in 0..depth {
        source.push_str(&format!(
            "mod m{level} {{\n    use super::m{}::*;\n",
            level + 1
        ));
        if level == 0 {
            source.push_str("    #[test]\n    fn chained() {\n        assert_eq!(C, 1);\n    }\n");
        }
        source.push_str("}\n");
    }
    source.push_str(&format!("mod m{depth} {{\n    pub const C: u32 = 1;\n}}\n"));
    source
}
