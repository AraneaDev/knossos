use super::{module_files, modules_above, Layout};
use crate::resolve::module_path_in_crate;

/// A project whose root package `demo` has a library, a binary and a
/// `[[bin]]` declared by its path, with a workspace member `core-lib`.
fn layout() -> Layout {
    Layout {
        crates: vec![
            ("src/lib.rs".to_owned(), "demo".to_owned()),
            ("src/main.rs".to_owned(), "demo".to_owned()),
            (
                "crates/core-lib/src/lib.rs".to_owned(),
                "core-lib".to_owned(),
            ),
        ],
        packages: vec![
            (String::new(), "demo".to_owned()),
            ("crates/core-lib/".to_owned(), "core_lib".to_owned()),
        ],
        libraries: [
            ("demo".to_owned(), "crate".to_owned()),
            ("core_lib".to_owned(), "core_lib".to_owned()),
        ]
        .into_iter()
        .collect(),
        targets: ["src/build_dict.rs".to_owned()].into_iter().collect(),
        ..Layout::default()
    }
}

#[test]
fn the_modules_above_a_name_run_from_its_crate_root_down() {
    assert_eq!(vec!["crate", "crate::a"], modules_above("crate::a::b"));
    assert!(modules_above("crate").is_empty());
}

#[test]
fn every_listed_file_of_the_root_package_is_placed_in_the_module() {
    for module in ["crate", "crate::engine", "crate::engine::sign"] {
        let files = module_files(module, &[]);
        assert!(files.contains(&"src/engine/sign.rs".to_owned()) == module.ends_with("sign"));
        for file in &files {
            assert_eq!(module, module_path_in_crate(file, "crate", false), "{file}");
        }
    }
    assert!(module_files("crate", &[]).contains(&"src/lib.rs".to_owned()));
    assert_eq!(
        vec!["src/engine.rs", "src/engine/mod.rs"],
        module_files("crate::engine", &[])
    );
}

#[test]
fn a_target_directory_keeps_its_directory_chain() {
    let files = module_files("tests::smoke", &[]);
    assert_eq!(vec!["tests/smoke.rs", "tests/smoke/mod.rs"], files);
    for file in files {
        assert_eq!("tests::smoke", module_path_in_crate(&file, "crate", false));
    }
}

#[test]
fn a_chain_outside_a_target_directory_names_no_files() {
    assert!(module_files("proc_macro2", &[]).is_empty());
    assert!(module_files("proc_macro2::Span", &[]).is_empty());
    let layout = layout();
    assert!(!layout.is_indexed("crate/engine.rs", &layout.module_of("crate/engine.rs")));
    assert!(!layout.is_indexed("build.rs", &layout.module_of("build.rs")));
    assert!(layout.is_indexed(
        "tests/common/mod.rs",
        &layout.module_of("tests/common/mod.rs")
    ));
    assert!(layout.is_indexed("src/net/lib.rs", &layout.module_of("src/net/lib.rs")));
    assert!(layout.is_indexed("src/main.rs", &layout.module_of("src/main.rs")));
}

#[test]
fn a_member_crate_name_reads_as_that_members_src() {
    let members = layout().packages;
    assert!(module_files("core_lib", &members).contains(&"crates/core-lib/src/lib.rs".to_owned()));
    assert_eq!(
        vec!["crates/core-lib/src/x.rs", "crates/core-lib/src/x/mod.rs"],
        module_files("core_lib::x", &members)
    );
    // The root package is `crate`, whatever its manifest names it.
    assert!(module_files("demo", &members).is_empty());
}

#[test]
fn a_module_no_path_can_spell_has_no_files() {
    assert!(module_files("crate::", &[]).is_empty());
    assert!(module_files("a::..::b", &[]).is_empty());
    assert!(module_files("a/b", &[]).is_empty());
}

#[test]
fn a_mod_declaration_loads_the_file_rust_would() {
    let layout = layout();
    let child = |declaring: &str, inline: &[&str], name: &str, path: Option<&str>| {
        let inline: Vec<String> = inline.iter().map(|s| (*s).to_owned()).collect();
        layout.child_file(declaring, &inline, name, path)
    };
    assert_eq!(
        Some("src/cli.rs".to_owned()),
        child("src/main.rs", &[], "cli", None)
    );
    assert_eq!(
        Some("src/net/http.rs".to_owned()),
        child("src/net.rs", &[], "http", None)
    );
    assert_eq!(
        Some("src/net/http.rs".to_owned()),
        child("src/net/mod.rs", &[], "http", None)
    );
    assert_eq!(
        Some("tests/common.rs".to_owned()),
        child("tests/it.rs", &[], "common", None)
    );
    assert_eq!(
        Some("src/bin/aid.rs".to_owned()),
        child("src/bin/tool.rs", &[], "aid", None)
    );
    assert_eq!(
        Some("src/bin/tool/aid.rs".to_owned()),
        child("src/bin/tool/main.rs", &[], "aid", None)
    );
    assert_eq!(
        Some("crates/core-lib/src/x.rs".to_owned()),
        child("crates/core-lib/src/lib.rs", &[], "x", None)
    );
    assert_eq!(
        Some("src/net/inner/deep.rs".to_owned()),
        child("src/net.rs", &["inner"], "deep", None)
    );
    assert_eq!(
        Some("src/impl_x.rs".to_owned()),
        child("src/net.rs", &[], "x", Some("impl_x.rs"))
    );
    assert_eq!(
        Some("src/net/inner/x.rs".to_owned()),
        child("src/net.rs", &["inner"], "x", Some("x.rs"))
    );
    assert_eq!(
        Some("shared/x.rs".to_owned()),
        child("src/lib.rs", &[], "x", Some("../shared/x.rs"))
    );
    assert_eq!(None, child("src/lib.rs", &[], "x", Some("../../x.rs")));
    assert_eq!(None, child("src/lib.rs", &[], "x", Some("/etc/x.rs")));
}

#[test]
fn a_child_module_is_named_as_its_file_is() {
    let layout = layout();
    let module = |declaring: &str, name: &str| {
        layout
            .child_file(declaring, &[], name, None)
            .map(|file| layout.module_of(&file))
    };
    assert_eq!(Some("crate::cli".to_owned()), module("src/main.rs", "cli"));
    assert_eq!(
        Some("tests::common".to_owned()),
        module("tests/it.rs", "common")
    );
    assert_eq!(
        Some("crate::nested::lib".to_owned()),
        module("src/nested.rs", "lib")
    );
    assert_eq!(
        Some("core_lib::x".to_owned()),
        module("crates/core-lib/src/lib.rs", "x")
    );
}

#[test]
fn a_library_is_named_by_its_graph_root_from_outside() {
    let layout = layout();
    assert_eq!(
        Some("crate::run".to_owned()),
        layout.library_path("demo::run")
    );
    assert_eq!(Some("crate".to_owned()), layout.library_path("demo"));
    assert_eq!(
        Some("core_lib::x".to_owned()),
        layout.library_path("core_lib::x")
    );
    assert_eq!(None, layout.library_path("serde::Serialize"));
    assert!(layout.is_project_root("crate"));
    assert!(layout.is_project_root("core_lib"));
    assert!(!layout.is_project_root("demo"));
    assert!(!layout.is_project_root("serde"));
}

#[test]
fn a_target_root_is_told_apart_from_the_package_roots() {
    let layout = layout();
    for target in [
        "src/bin/tool.rs",
        "src/build_dict.rs",
        "src/bin/deep/main.rs",
        "tests/it.rs",
        "examples/ex.rs",
        "build.rs",
    ] {
        assert!(layout.is_target_root(target), "{target}");
    }
    for not_target in [
        "src/lib.rs",
        "src/main.rs",
        "src/bin/deep/part.rs",
        "tests/common/mod.rs",
    ] {
        assert!(!layout.is_target_root(not_target), "{not_target}");
    }
    assert!(layout.is_package_root("crates/core-lib/src/lib.rs"));
}
