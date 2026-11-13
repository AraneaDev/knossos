//! Scanning tests driving the worker against real files on disk:
//! `use` items inside function bodies and blocks.

mod common;

use common::*;

#[test]
fn a_use_in_a_function_body_applies_to_its_block_only() {
    // A `use` inside a body brings its names into that block: it shadows
    // the module's own `use` of the same name there, and nothing after the
    // block sees it.
    let declares = "pub struct Y;\nimpl Y {\n    pub fn new() -> Self {\n        Y\n    }\n    pub fn go(&self) {}\n}\npub fn helper() {}\n";
    let files = [
        ("src/lib.rs", "pub mod a;\npub mod b;\npub mod user;\n"),
        ("src/a.rs", declares),
        ("src/b.rs", declares),
        (
            "src/user.rs",
            "use crate::b::Y;\npub fn scoped() {\n    {\n        use crate::a::{helper, Y};\n        let y = Y::new();\n        y.go();\n        helper();\n    }\n    let z = Y::new();\n    z.go();\n}\npub fn top() {\n    use crate::a::helper;\n    helper();\n}\npub fn after() {\n    helper();\n}\n",
        ),
    ];
    let contributions = scan_fixture("body-use-scope", &files);

    assert_eq!(
        vec![
            ("rust:function:crate::a::helper".to_owned(), true),
            ("rust:method:crate::a::Y::go".to_owned(), true),
            ("rust:method:crate::a::Y::new".to_owned(), true),
            ("rust:method:crate::b::Y::go".to_owned(), true),
            ("rust:method:crate::b::Y::new".to_owned(), true),
        ],
        method_calls(&contributions, "rust:function:crate::user::scoped")
    );
    assert_eq!(
        vec![("rust:function:crate::a::helper".to_owned(), true)],
        method_calls(&contributions, "rust:function:crate::user::top")
    );
    assert_eq!(
        Vec::<(String, bool)>::new(),
        method_calls(&contributions, "rust:function:crate::user::after")
    );
}

#[test]
fn a_use_in_a_function_body_starts_from_a_name_in_scope() {
    // `use Kind::{Big, Small};` in a body names the `Kind` the module
    // imports, as any path may since the 2018 edition, not a crate `Kind`.
    let files = [
        ("src/lib.rs", "pub mod model;\npub mod user;\n"),
        (
            "src/model.rs",
            "pub enum Kind {\n    Big(u8),\n    Small(u8),\n}\npub struct Store;\nimpl Store {\n    pub fn put(&self) {}\n}\n",
        ),
        (
            "src/user.rs",
            "use crate::model;\nuse crate::model::Kind;\npub fn pick(kind: Kind) -> u8 {\n    use model::Store;\n    use Kind::{Big, Small};\n    let store: Store = Store;\n    store.put();\n    match kind {\n        Big(n) | Small(n) => n,\n    }\n}\n",
        ),
    ];
    let contributions = scan_fixture("body-use-in-scope-head", &files);
    let targets: Vec<String> = contributions
        .iter()
        .flat_map(|contribution| contribution["edges"].as_array().unwrap().clone())
        .filter(|edge| edge["source"] == "rust:function:crate::user::pick")
        .map(|edge| edge["target"].as_str().unwrap().to_owned())
        .collect();

    assert!(
        targets.contains(&"rust:class:crate::model::Kind::Big".to_owned()),
        "{targets:?}"
    );
    assert!(
        targets.contains(&"rust:method:crate::model::Store::put".to_owned()),
        "{targets:?}"
    );
    assert!(
        !targets
            .iter()
            .any(|target| target.contains(":Kind::") && !target.contains("crate::model::Kind")),
        "{targets:?}"
    );
}

#[test]
fn a_use_starting_from_a_name_another_use_imports_expands_through_it() {
    // `use m::W;` under `use crate::m;` names `crate::m::W`, whichever line
    // comes first, at module level and in a block; `use Kind::A;` beside
    // `enum Kind` names this module's `Kind`. None of them is a crate.
    let files = [
        ("src/lib.rs", "pub mod body;\npub mod k;\npub mod m;\npub mod r;\n"),
        (
            "src/m.rs",
            "pub struct W;\nimpl W {\n    pub fn new() -> W {\n        W\n    }\n}\n",
        ),
        (
            "src/k.rs",
            "use crate::m;\nuse m::W;\nuse std::collections;\nuse collections::HashMap;\npub enum Kind {\n    A,\n    B,\n}\nuse Kind::A;\npub fn f() {\n    W::new();\n    let _ = A;\n}\n",
        ),
        (
            "src/r.rs",
            "use m::W;\nuse crate::m;\npub fn f() {\n    W::new();\n}\n",
        ),
        (
            "src/body.rs",
            "pub fn chain() {\n    use crate::m;\n    use m::W;\n    W::new();\n}\npub fn chain_std() {\n    use collections::HashMap;\n    use std::collections;\n    let _h: HashMap<u8, u8> = HashMap::new();\n}\npub fn local_enum() {\n    enum Kind {\n        A,\n    }\n    use Kind::A;\n    let _ = A;\n}\n",
        ),
    ];
    let contributions = scan_fixture("use-head-bound-by-use", &files);

    for (caller, module) in [
        ("rust:function:crate::k::f", "rust:module:crate::k"),
        ("rust:function:crate::r::f", "rust:module:crate::r"),
        (
            "rust:function:crate::body::chain",
            "rust:module:crate::body",
        ),
    ] {
        assert_eq!(
            vec![("rust:method:crate::m::W::new".to_owned(), true)],
            method_calls(&contributions, caller),
            "{caller}"
        );
        let imports = targets_of(&contributions, "imports", module);
        assert!(
            imports
                .iter()
                .all(|target| target.starts_with("rust:module:crate")
                    || target.starts_with("rust:module:std")),
            "{module}: {imports:?}"
        );
    }
    assert!(
        targets_of(
            &contributions,
            "calls",
            "rust:function:crate::body::chain_std"
        )
        .contains(&"rust:method:std::collections::HashMap::new".to_owned()),
        "{:?}",
        targets_of(
            &contributions,
            "calls",
            "rust:function:crate::body::chain_std"
        )
    );
    assert!(
        targets_of(&contributions, "references", "rust:module:crate::k")
            .contains(&"rust:class:crate::k::Kind".to_owned()),
        "{:?}",
        targets_of(&contributions, "references", "rust:module:crate::k")
    );
    let local = targets_of(
        &contributions,
        "references",
        "rust:function:crate::body::local_enum",
    );
    assert!(
        local.iter().all(|target| !target.contains(":Kind")),
        "{local:?}"
    );
}

#[test]
fn a_body_use_reaches_nested_functions_closures_and_earlier_statements() {
    // A `use` in a block is in scope in the whole block: in a function item
    // nested there, in a closure, and before the `use` itself.
    let files = [
        ("src/lib.rs", "pub mod body;\npub mod m;\npub mod n;\n"),
        ("src/m.rs", "pub fn hi() {}\n"),
        ("src/n.rs", "pub fn hi() {}\n"),
        (
            "src/body.rs",
            "use crate::n::hi;\npub fn nested_fn() {\n    use crate::m::hi;\n    fn inner() {\n        hi();\n    }\n    inner();\n}\npub fn closure() {\n    let c = || {\n        use crate::m::hi;\n        hi();\n    };\n    c();\n    hi();\n}\npub fn late() {\n    hi();\n    use crate::m::hi;\n}\n",
        ),
    ];
    let contributions = scan_fixture("body-use-reach", &files);
    let m_hi = ("rust:function:crate::m::hi".to_owned(), true);
    let n_hi = ("rust:function:crate::n::hi".to_owned(), true);

    assert_eq!(
        vec![m_hi.clone()],
        method_calls(&contributions, "rust:function:crate::body::nested_fn")
            .into_iter()
            .filter(|(target, _)| target.ends_with("::hi"))
            .collect::<Vec<_>>()
    );
    assert_eq!(
        vec![m_hi.clone(), n_hi],
        method_calls(&contributions, "rust:function:crate::body::closure")
    );
    assert_eq!(
        vec![m_hi],
        method_calls(&contributions, "rust:function:crate::body::late")
    );
}

#[test]
fn a_use_binding_a_name_its_own_path_starts_from_binds_no_other_leaf() {
    // `use alloc::alloc::{alloc, dealloc};` binds the function `alloc`, but
    // its own path starts from the crate `alloc`, so the crate is what
    // `dealloc`'s path starts from too, not the function.
    let source =
        "use alloc::alloc::{alloc, dealloc};\npub fn free() {\n    dealloc();\n    alloc();\n}\n";
    let contributions = scan_fixture("use-self-binding-head", &[("src/lib.rs", source)]);

    assert_eq!(
        vec![
            ("rust:function:alloc::alloc::alloc".to_owned(), false),
            ("rust:function:alloc::alloc::dealloc".to_owned(), false),
        ],
        method_calls(&contributions, "rust:function:crate::free")
    );
}

#[test]
fn a_use_of_an_item_the_module_declares_imports_no_module() {
    // `pub use LazyCell as LazyLock;` names this module's own `LazyCell`:
    // the module does not import itself. `use std::fmt::Write as _;` in a
    // body binds no name, yet still imports `std::fmt`.
    let source = "pub struct LazyCell;\npub use LazyCell as LazyLock;\npub fn go() {\n    use std::fmt::Write as _;\n}\n";
    let contributions = scan_fixture("use-own-item-alias", &[("src/lib.rs", source)]);

    assert_eq!(
        vec!["rust:module:std::fmt".to_owned()],
        targets_of(&contributions, "imports", "rust:module:crate")
    );
}

#[test]
fn a_glob_use_inside_a_function_body_brings_its_names_in_for_that_body() {
    let lib = r#"
pub mod services;
pub struct Owner;
impl Owner {
    pub fn manager(&mut self) {
        use crate::services::systemd::*;
        let _ = SystemdManager::connect(SystemdAuthority);
    }
    pub fn elsewhere(&self) {
        let _ = SystemdManager::connect(SystemdAuthority);
    }
}
"#;
    let systemd = r#"
pub struct SystemdAuthority;
pub struct SystemdManager;
impl SystemdManager {
    pub fn connect(_: SystemdAuthority) -> Self { SystemdManager }
}
"#;
    let contributions = scan_fixture(
        "block-glob",
        &[
            ("src/lib.rs", lib),
            ("src/services/mod.rs", "pub mod systemd;\n"),
            ("src/services/systemd.rs", systemd),
        ],
    );
    let targets = |source: &str| -> Vec<String> {
        method_calls(&contributions, source)
            .into_iter()
            .map(|(target, _)| target)
            .filter(|target| target.contains("connect"))
            .collect()
    };

    assert_eq!(
        vec!["rust:method:crate::services::systemd::SystemdManager::connect".to_owned()],
        targets("rust:method:crate::Owner::manager")
    );
    // The glob ends with the body that writes it.
    assert!(!targets("rust:method:crate::Owner::elsewhere")
        .contains(&"rust:method:crate::services::systemd::SystemdManager::connect".to_owned()));
}

#[test]
fn a_block_glob_resolves_through_the_blocks_own_aliases_and_imports_its_module() {
    // The block renames `right` as `selected`, shadowing the module's
    // `selected`; its glob reads the block's, as Rust resolves it.
    let lib = r#"
pub mod left;
pub mod right;
pub mod extra;
use crate::left as selected;
pub fn other() {
    use crate::extra::*;
    let _ = Widget::make();
}
pub fn pick() {
    use crate::right as selected;
    use selected::*;
    let _ = Widget::make();
}
"#;
    let widget = "pub struct Widget;\nimpl Widget { pub fn make() -> Self { Widget } }\n";
    let contributions = scan_fixture(
        "block-glob-alias",
        &[
            ("src/lib.rs", lib),
            ("src/left.rs", widget),
            ("src/right.rs", widget),
            ("src/extra.rs", widget),
        ],
    );
    let makes: Vec<String> = method_calls(&contributions, "rust:function:crate::pick")
        .into_iter()
        .map(|(target, _)| target)
        .filter(|target| target.ends_with("::make"))
        .collect();
    assert_eq!(
        vec!["rust:method:crate::right::Widget::make".to_owned()],
        makes
    );

    let imports: Vec<String> = contributions
        .iter()
        .flat_map(|contribution| contribution["edges"].as_array().unwrap().clone())
        .filter(|edge| edge["kind"] == "imports" && edge["source"] == "rust:module:crate")
        .map(|edge| edge["target"].as_str().unwrap().to_owned())
        .collect();
    // Only the block's glob names `extra`.
    assert!(
        imports.contains(&"rust:module:crate::extra".to_owned()),
        "{imports:?}"
    );
}
