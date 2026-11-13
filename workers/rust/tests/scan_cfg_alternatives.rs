//! Scanning tests driving the worker against real files on disk:
//! `cfg` alternatives of one module, alias, binding or field.

mod common;

use common::*;

#[test]
fn cfg_alternative_re_exports_in_one_file_reach_every_alternative() {
    // Two `#[cfg]` alternatives re-export `run` from different modules.
    // Exactly one compiles in any build, and a call through `x::run` reaches
    // whichever does, so each takes the edge; neither is guessed alone.
    let files = [
        ("src/lib.rs", "pub mod user;\npub mod x;\n"),
        (
            "src/x/mod.rs",
            "mod a;\nmod b;\n#[cfg(unix)]\npub use a::run;\n#[cfg(not(unix))]\npub use b::run;\n",
        ),
        ("src/x/a.rs", "pub fn run() {}\n"),
        ("src/x/b.rs", "pub fn run() {}\n"),
        ("src/user.rs", "pub fn go() {\n    crate::x::run();\n}\n"),
    ];
    let contributions = scan_fixture("re-export-cfg-alternatives", &files);
    let calls = targets_of(&contributions, "calls", "rust:function:crate::user::go");

    assert_eq!(
        vec![
            "rust:function:crate::x::a::run".to_owned(),
            "rust:function:crate::x::b::run".to_owned()
        ],
        calls
    );
}

#[test]
fn cfg_alternative_structs_with_different_fields_type_no_field() {
    // `#[cfg(unix)] struct Holder { store: Store }` beside a fieldless
    // `#[cfg(not(unix))] struct Holder;`: which one compiles is unknown.
    let files = [
        ("src/lib.rs", "pub mod a;\npub mod b;\npub mod c;\n"),
        ("src/b.rs", "pub struct Store;\nimpl Store {\n    pub fn put(&self) {}\n}\n"),
        (
            "src/a.rs",
            "use crate::b::Store;\n#[cfg(unix)]\npub struct Holder {\n    pub(crate) store: Store,\n}\n#[cfg(not(unix))]\npub struct Holder;\n",
        ),
        (
            "src/c.rs",
            "use crate::a::Holder;\nimpl Holder {\n    pub fn save(&self) {\n        self.store.put();\n    }\n}\n",
        ),
    ];
    let contributions = scan_fixture("cfg-alternative-struct-fields", &files);

    assert_eq!(
        Vec::<(String, bool)>::new(),
        method_calls(&contributions, "rust:method:crate::a::Holder::save")
    );
}

#[test]
fn a_call_through_a_cfg_gated_module_alias_reaches_every_alternative() {
    // `use unix as platform;` and `use windows as platform;` under exclusive
    // `cfg`s bind one name twice; the name was poisoned, so every platform
    // function read as unreferenced. Each alternative now takes the edge,
    // through calls, re-exports, a body-level alias and a method used as a
    // value, and a crate-path alias reaches a workspace member's module.
    let fs = r#"
#[cfg(unix)]
mod unix;
#[cfg(windows)]
mod windows;
#[cfg(unix)]
use unix as platform;
#[cfg(windows)]
use windows as platform;

pub use platform::fingerprint;

pub fn flush() {
    platform::flush_file();
}

pub fn listen() -> Vec<u8> {
    #[cfg(unix)]
    use self::unix::Stream as Native;
    #[cfg(windows)]
    use self::windows::Stream as Native;
    vec![Native::open()].into_iter().map(platform::Stream::identity).collect()
}
"#;
    let platform = r#"
pub struct Stream;
impl Stream {
    pub fn open() -> Self { Stream }
    pub fn identity(self) -> u8 { 0 }
}
pub fn flush_file() {}
pub fn fingerprint() -> u8 { 0 }
"#;
    let caller = "pub fn go() -> u8 { crate::fs::fingerprint() }\n";
    let scm = r#"
use other::services::scm as host;
pub fn check() { host::verify(); }
"#;
    let contributions = scan_fixture_with(
        "cfg-module-alias",
        &[
            (
                "Cargo.toml",
                "[package]\nname = \"demo\"\n[workspace]\nmembers = [\"other\"]\n",
            ),
            ("src/lib.rs", "pub mod caller;\npub mod fs;\npub mod scm;\n"),
            ("src/fs/mod.rs", fs),
            ("src/fs/unix.rs", platform),
            ("src/fs/windows.rs", platform),
            ("src/caller.rs", caller),
            ("src/scm.rs", scm),
            ("other/Cargo.toml", "[package]\nname = \"other\"\n"),
            ("other/src/lib.rs", "pub mod services;\n"),
            ("other/src/services.rs", "pub mod scm;\n"),
            ("other/src/services/scm.rs", "pub fn verify() {}\n"),
        ],
        &serde_json::json!({"config_files": ["Cargo.toml", "other/Cargo.toml"]}),
    );
    let mut missing = Vec::new();
    for os in ["unix", "windows"] {
        for (from, to) in [
            (
                "rust:function:crate::fs::flush",
                format!("rust:function:crate::fs::{os}::flush_file"),
            ),
            (
                "rust:function:crate::caller::go",
                format!("rust:function:crate::fs::{os}::fingerprint"),
            ),
            (
                "rust:function:crate::fs::listen",
                format!("rust:method:crate::fs::{os}::Stream::open"),
            ),
        ] {
            if !targets_of(&contributions, "calls", from).contains(&to) {
                missing.push(format!(
                    "calls {from} -> {to}: {:?}",
                    targets_of(&contributions, "calls", from)
                ));
            }
        }
        let to = format!("rust:method:crate::fs::{os}::Stream::identity");
        if !targets_of(
            &contributions,
            "references",
            "rust:function:crate::fs::listen",
        )
        .contains(&to)
        {
            missing.push(format!("references listen -> {to}"));
        }
    }
    let scm_calls = targets_of(&contributions, "calls", "rust:function:crate::scm::check");
    if !scm_calls.contains(&"rust:function:other::services::scm::verify".to_owned()) {
        missing.push(format!(
            "calls check -> other::services::scm::verify: {scm_calls:?}"
        ));
    }
    assert!(missing.is_empty(), "{missing:#?}");
}

#[test]
fn a_cfg_alias_in_a_method_body_reaches_every_alternative_inside_a_closure() {
    // `#[cfg(unix)] use unix as platform;` beside `#[cfg(windows)] use
    // windows::pipe as platform;` in a method body, called from a closure
    // in it, and a child module importing the parent's private alias with
    // `use super::platform;`.
    let process = r#"
#[cfg(unix)]
mod unix;
#[cfg(windows)]
mod windows;
#[cfg(unix)]
use unix as platform;
#[cfg(windows)]
use windows::pipe as platform;
mod ledger;
pub struct Child;
impl Child {
    pub fn acquire(&self) -> u8 {
        #[cfg(unix)]
        use unix as platform;
        #[cfg(windows)]
        use windows::pipe as platform;
        let result = (|| platform::reader())();
        result
    }
}
"#;
    let contributions = scan_fixture(
        "cfg-alias-method-body",
        &[
            ("src/lib.rs", "pub mod process;\n"),
            ("src/process/mod.rs", process),
            (
                "src/process/unix.rs",
                "pub fn reader() -> u8 { 0 }\npub fn open() {}\n",
            ),
            ("src/process/windows/mod.rs", "pub mod pipe;\n"),
            (
                "src/process/windows/pipe.rs",
                "pub fn reader() -> u8 { 0 }\npub fn open() {}\n",
            ),
            (
                "src/process/ledger.rs",
                "use super::platform;\npub fn go() { platform::open(); }\n",
            ),
        ],
    );
    let calls = targets_of(
        &contributions,
        "calls",
        "rust:method:crate::process::Child::acquire",
    );
    assert!(
        calls.contains(&"rust:function:crate::process::unix::reader".to_owned()),
        "{calls:?}"
    );
    assert!(
        calls.contains(&"rust:function:crate::process::windows::pipe::reader".to_owned()),
        "{calls:?}"
    );
    let calls = targets_of(
        &contributions,
        "calls",
        "rust:function:crate::process::ledger::go",
    );
    assert!(
        calls.contains(&"rust:function:crate::process::unix::open".to_owned()),
        "{calls:?}"
    );
    assert!(
        calls.contains(&"rust:function:crate::process::windows::pipe::open".to_owned()),
        "{calls:?}"
    );
}

#[test]
fn an_impl_of_a_type_re_exported_under_exclusive_cfgs_adds_to_each_alternative() {
    // `impl OwnedChild` where `OwnedChild` is a gated re-export of a Unix and
    // a Windows type named nothing, so the block was skipped and every call
    // its methods make was lost.
    let process = r#"
#[cfg(unix)]
mod unix;
#[cfg(windows)]
mod windows;
#[cfg(unix)]
pub use unix::OwnedChild;
#[cfg(windows)]
pub use windows::OwnedChild;
impl OwnedChild {
    pub fn spawn() -> u8 {
        #[cfg(unix)]
        use unix as platform;
        #[cfg(windows)]
        use windows as platform;
        (|| platform::reader())()
    }
}
"#;
    let native = "pub struct OwnedChild;\npub fn reader() -> u8 { 0 }\n";
    let contributions = scan_fixture(
        "impl-of-cfg-alternative-type",
        &[
            ("src/lib.rs", "pub mod process;\n"),
            ("src/process/mod.rs", process),
            ("src/process/unix.rs", native),
            ("src/process/windows.rs", native),
        ],
    );
    for os in ["unix", "windows"] {
        let method = format!("rust:method:crate::process::{os}::OwnedChild::spawn");
        let calls = targets_of(&contributions, "calls", &method);
        assert!(
            calls.contains(&format!("rust:function:crate::process::{os}::reader")),
            "{method}: {calls:?}"
        );
    }
}

#[test]
fn a_binding_under_exclusive_cfgs_holds_every_alternatives_type() {
    // `#[cfg(linux)] let loaded = load_stock(..); #[cfg(not(linux))] let
    // loaded = load(..);` compiles one or the other, so what is called on
    // `loaded` (and on what a `match` takes out of it) is called on both.
    let source = r#"
pub struct Stock;
impl Stock { pub fn eof(&self) -> bool { true } }
pub struct Plain;
impl Plain { pub fn eof(&self) -> bool { true } }
pub struct Config;
impl Config {
    pub fn load(_: &str) -> std::io::Result<Plain> { Ok(Plain) }
    pub fn load_stock(_: &str) -> std::io::Result<Stock> { Ok(Stock) }
}
pub fn run(path: &str) -> i32 {
    #[cfg(target_os = "linux")]
    let loaded = Config::load_stock(path);
    #[cfg(not(target_os = "linux"))]
    let loaded = Config::load(path);
    let config = match loaded {
        Ok(config) => config,
        Err(_error) => return 2,
    };
    let _ = config.eof();
    0
}
pub fn plain(path: &str) {
    let first = Config::load_stock(path);
    let first = Config::load(path);
    if let Ok(config) = first {
        config.eof();
    }
}
"#;
    let contributions = scan_fixture("cfg-binding-alternatives", &[("src/lib.rs", source)]);

    let eofs = |source: &str| -> Vec<String> {
        let mut found: Vec<String> = method_targets(&contributions, source)
            .into_iter()
            .filter(|target| target.ends_with("::eof"))
            .collect();
        found.dedup();
        found
    };
    assert_eq!(
        vec![
            "rust:method:crate::Plain::eof".to_owned(),
            "rust:method:crate::Stock::eof".to_owned()
        ],
        eofs("rust:function:crate::run")
    );
    // Without `cfg`, the second `let` shadows the first.
    assert_eq!(
        vec!["rust:method:crate::Plain::eof".to_owned()],
        eofs("rust:function:crate::plain")
    );
}

#[test]
fn a_cfg_gated_option_field_of_self_taken_through_as_mut_and_unwrap_holds_its_type() {
    // The field is declared under `cfg(windows)` with a re-exported type.
    let lib = r#"
pub mod scm;
pub struct Service {
    #[cfg(windows)]
    manager: Option<crate::scm::ScmManager>,
}
impl Service {
    pub fn query(&mut self) -> u8 {
        self.manager.as_mut().unwrap().inspect()
    }
}
"#;
    let scm = r#"
mod native;
pub use native::ScmManager;
"#;
    let native = r#"
pub struct ScmManager;
impl ScmManager {
    pub fn inspect(&mut self) -> u8 { 0 }
}
"#;
    let contributions = scan_fixture(
        "self-option-field",
        &[
            ("src/lib.rs", lib),
            ("src/scm/mod.rs", scm),
            ("src/scm/native.rs", native),
        ],
    );

    assert_eq!(
        vec!["rust:method:crate::scm::native::ScmManager::inspect".to_owned()],
        method_targets(&contributions, "rust:method:crate::Service::query")
    );
}

#[test]
fn a_field_declared_once_per_cfg_holds_every_alternatives_type() {
    // `manager` is a `SystemdManager` on Linux, a `LaunchdManager` on macOS
    // and an `ScmManager` on Windows: a call through it is on each.
    let source = r#"
pub struct SystemdManager;
impl SystemdManager { pub fn revoke_context(&mut self) {} }
pub struct LaunchdManager;
impl LaunchdManager { pub fn revoke_context(&mut self) {} }
pub struct ScmManager;
impl ScmManager { pub fn revoke_context(&mut self) {} }
pub struct Service {
    #[cfg(target_os = "linux")]
    manager: Option<SystemdManager>,
    #[cfg(target_os = "macos")]
    manager: Option<LaunchdManager>,
    #[cfg(windows)]
    manager: Option<ScmManager>,
    #[cfg(unix)]
    mixed: Option<ScmManager>,
    #[cfg(windows)]
    mixed: Vec<ScmManager>,
}
impl Service {
    pub fn revoke(&mut self) {
        self.manager.as_mut().unwrap().revoke_context();
    }
    pub fn revoke_mixed(&mut self) {
        self.mixed.as_mut().unwrap().revoke_context();
    }
}
"#;
    let contributions = scan_fixture("cfg-field-alternatives", &[("src/lib.rs", source)]);

    assert_eq!(
        vec![
            "rust:method:crate::LaunchdManager::revoke_context".to_owned(),
            "rust:method:crate::ScmManager::revoke_context".to_owned(),
            "rust:method:crate::SystemdManager::revoke_context".to_owned()
        ],
        method_targets(&contributions, "rust:method:crate::Service::revoke")
    );
    // An `Option` on one target and a `Vec` on another agree on nothing.
    assert!(method_targets(&contributions, "rust:method:crate::Service::revoke_mixed").is_empty());
}
