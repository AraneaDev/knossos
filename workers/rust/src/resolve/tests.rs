#[test]
fn glob_leaves_yield_the_modules_they_import_from() {
    let tree: syn::UseTree = syn::parse_str("a::{b::*, c, d::e::*}").expect("parses");
    let mut out = Vec::new();
    super::glob_prefixes(&tree, "", &mut out);
    assert_eq!(vec!["a::b".to_owned(), "a::d::e".to_owned()], out);

    let bare: syn::UseTree = syn::parse_str("*").expect("parses");
    let mut none = Vec::new();
    super::glob_prefixes(&bare, "", &mut none);
    assert!(none.is_empty());
}

/// The path a file at the project root's package declares.
fn module_path(relative: &str) -> String {
    super::module_path_in_crate(relative, "crate", false)
}

#[test]
fn lib_and_main_collapse_to_the_crate_root() {
    assert_eq!("crate", module_path("src/lib.rs"));
    assert_eq!("crate", module_path("src/main.rs"));
}

#[test]
fn a_binary_root_can_be_disambiguated_from_a_library_root() {
    assert_eq!(
        "crate::main",
        super::module_path_in_crate("src/main.rs", "crate", true)
    );
}

#[test]
fn only_the_crate_roots_lib_and_main_collapse() {
    assert_eq!("crate::net::lib", module_path("src/net/lib.rs"));
    assert_eq!("crate::bin::main", module_path("src/bin/main.rs"));
    assert_eq!(
        "crate::bin::tool::main",
        module_path("src/bin/tool/main.rs")
    );
    assert_eq!("crate::lib", module_path("src/lib/mod.rs"));
    assert_eq!("crate", module_path("src/mod.rs"));
}

#[test]
fn a_plain_file_becomes_a_child_of_the_crate() {
    assert_eq!("crate::greeting", module_path("src/greeting.rs"));
    assert_eq!("crate::net::http", module_path("src/net/http.rs"));
}

#[test]
fn mod_rs_collapses_into_its_directory() {
    assert_eq!("crate::net", module_path("src/net/mod.rs"));
}

#[test]
fn a_path_outside_src_keeps_its_directory_chain() {
    assert_eq!("tests::integration", module_path("tests/integration.rs"));
}

#[test]
fn a_raw_identifier_is_named_without_its_prefix() {
    let tree: syn::UseTree = syn::parse_str("crate::r#async::{self, r#try as attempt}").unwrap();
    let mut out = Vec::new();
    super::flatten_use(&tree, "", &mut out);

    assert_eq!(
        vec![("async", "crate::async"), ("attempt", "crate::async::try"),],
        out.iter()
            .map(|leaf| (leaf.alias.as_str(), leaf.full.as_str()))
            .collect::<Vec<_>>()
    );
}

#[test]
fn an_alias_resolves_to_its_full_path() {
    let mut aliases = super::Aliases::default();
    aliases.insert("De".to_owned(), "serde::Deserialize".to_owned());

    assert_eq!(Some("serde::Deserialize"), aliases.resolve("De").as_deref());
    assert_eq!(None, aliases.resolve("Unknown"));
}

#[test]
fn a_leading_alias_segment_is_expanded_in_place() {
    let mut aliases = super::Aliases::default();
    aliases.insert("http".to_owned(), "crate::net::http".to_owned());

    assert_eq!(
        Some("crate::net::http::get"),
        aliases.expand("http::get").as_deref()
    );
}

#[test]
fn a_brace_group_flattens_to_one_entry_per_leaf() {
    let tree: syn::UseTree = syn::parse_str("serde::{Serialize, Deserialize as De}").unwrap();
    let mut out = Vec::new();
    super::flatten_use(&tree, "", &mut out);

    assert_eq!(
        vec![
            super::UseLeaf {
                alias: "Serialize".to_owned(),
                full: "serde::Serialize".to_owned(),
                names_module: false,
            },
            super::UseLeaf {
                alias: "De".to_owned(),
                full: "serde::Deserialize".to_owned(),
                names_module: false,
            },
        ],
        out
    );
}

#[test]
fn a_self_leaf_names_its_prefix_under_the_prefixes_last_segment() {
    let tree: syn::UseTree = syn::parse_str("crate::foo::{self, bar}").unwrap();
    let mut out = Vec::new();
    super::flatten_use(&tree, "", &mut out);

    assert_eq!(
        vec![
            super::UseLeaf {
                alias: "foo".to_owned(),
                full: "crate::foo".to_owned(),
                names_module: true,
            },
            super::UseLeaf {
                alias: "bar".to_owned(),
                full: "crate::foo::bar".to_owned(),
                names_module: false,
            },
        ],
        out
    );
}

#[test]
fn a_bare_use_self_with_no_prefix_names_nothing() {
    let tree: syn::UseTree = syn::parse_str("self").unwrap();
    let mut out = Vec::new();
    super::flatten_use(&tree, "", &mut out);

    assert!(out.is_empty());
}

#[test]
fn colliding_aliases_for_different_paths_become_unresolvable() {
    let mut aliases = super::Aliases::default();
    aliases.insert("Widget".to_owned(), "foo::Widget".to_owned());
    aliases.insert("Widget".to_owned(), "bar::Widget".to_owned());

    assert_eq!(None, aliases.resolve("Widget"));
}

#[test]
fn repeated_inserts_of_the_same_path_stay_resolvable() {
    let mut aliases = super::Aliases::default();
    aliases.insert("Widget".to_owned(), "foo::Widget".to_owned());
    aliases.insert("Widget".to_owned(), "foo::Widget".to_owned());

    assert_eq!(Some("foo::Widget"), aliases.resolve("Widget").as_deref());
}

#[test]
fn a_symbol_path_yields_the_module_that_declares_it() {
    assert_eq!("a::b", super::parent_module("a::b::C"));
    assert_eq!("crate::net", super::parent_module("crate::net::http"));
}

#[test]
fn a_single_segment_path_is_its_own_module() {
    assert_eq!("serde", super::parent_module("serde"));
}

#[test]
fn a_path_with_neither_prefix_is_returned_unchanged() {
    assert_eq!(
        Some("crate::net::Engine".to_owned()),
        super::rebase("crate::net", "crate::net::Engine")
    );
}

#[test]
fn a_self_prefix_rebases_against_its_own_module() {
    assert_eq!(
        Some("crate::net::inner::Thing".to_owned()),
        super::rebase("crate::net", "self::inner::Thing")
    );
}

#[test]
fn a_super_prefix_rebases_against_the_parent_module() {
    assert_eq!(
        Some("crate::net::Parent".to_owned()),
        super::rebase("crate::net::http", "super::Parent")
    );
}

#[test]
fn a_super_chain_strips_one_segment_per_super() {
    assert_eq!(
        Some("crate::Parent".to_owned()),
        super::rebase("crate::net::http", "super::super::Parent")
    );
}

#[test]
fn a_super_chain_escaping_the_crate_root_resolves_to_nothing() {
    assert_eq!(
        None,
        super::rebase("crate::net", "super::super::Unreachable")
    );
}

#[test]
fn super_from_the_crate_root_itself_resolves_to_nothing() {
    assert_eq!(None, super::rebase("crate", "super::Unreachable"));
}
