//! Scanning tests driving the worker against real files on disk:
//! files the worker refuses or cannot parse, and what one request counts.

mod common;

use std::io::Cursor;

use knossos_rust_worker::server::run;
use serde_json::Value;

use common::*;

#[test]
fn a_file_becomes_a_module_node_at_its_real_first_line() {
    let contributions = scan_fixture("module-node", &[("src/greeting.rs", "\n\nfn hello() {}\n")]);

    assert_eq!(1, contributions.len());
    let contribution = &contributions[0];
    assert_eq!(
        "knossos.rust:file:src/greeting.rs",
        contribution["owner_key"]
    );
    let module = &contribution["nodes"][0];
    assert_eq!("module", module["kind"]);
    assert_eq!("crate::greeting", module["canonical_name"]);
    assert_eq!("greeting", module["display_name"]);
    assert_eq!("ast", module["origin"]);
    assert_eq!("certain", module["confidence"]);
    assert_eq!("src/greeting.rs", module["evidence"]["path"]);
    assert_eq!(1, module["evidence"]["start_line"]);
    assert_eq!(serde_json::json!({}), module["attributes"]);
}

#[test]
fn spans_report_real_line_numbers_not_zero() {
    // Regression guard for the proc-macro2 `span-locations` feature: the pinned
    // proc-macro2 gates `Span::start()`/`end()` behind `#[cfg(span_locations)]`,
    // so dropping the feature is a compile error, not a silent line-0 span.
    // This test guards the feature staying enabled and evidence staying real.
    let source = "// one\n// two\nfn third_line() {}\n";
    let contributions = scan_fixture("span-lines", &[("src/lib.rs", source)]);
    let nodes = contributions[0]["nodes"].as_array().unwrap();
    let function = nodes
        .iter()
        .find(|node| node["display_name"] == "third_line")
        .expect("the function node is missing");

    assert_eq!(3, function["evidence"]["start_line"]);
}

#[test]
fn a_symlink_escaping_the_root_is_unscannable() {
    let root = std::env::temp_dir().join("knossos-rust-symlink-escape");
    let _ = std::fs::remove_dir_all(&root);
    std::fs::create_dir_all(root.join("src")).unwrap();

    let outside = std::env::temp_dir().join("knossos-rust-symlink-escape-target.rs");
    std::fs::write(&outside, "fn outside() {}\n").unwrap();

    let link = root.join("src/escape.rs");
    std::os::unix::fs::symlink(&outside, &link).unwrap();

    let request = serde_json::json!({
        "jsonrpc": "2.0",
        "id": 1,
        "method": "scan",
        "params": {
            "limits": {"max_files": 100000, "max_file_bytes": 2000000}, "root": std::fs::canonicalize(&root).unwrap().to_str().unwrap(),
            "files": ["src/escape.rs"],
        },
    });
    let mut output: Vec<u8> = Vec::new();
    run(Cursor::new(request.to_string().into_bytes()), &mut output).unwrap();
    let replies: Vec<Value> = String::from_utf8(output)
        .unwrap()
        .lines()
        .filter(|line| !line.is_empty())
        .map(|line| serde_json::from_str(line).unwrap())
        .collect();
    let _ = std::fs::remove_dir_all(&root);
    let _ = std::fs::remove_file(&outside);

    let contributions: Vec<Value> = replies
        .into_iter()
        .filter(|reply| reply["method"] == "scan/contribution")
        .map(|reply| reply["params"].clone())
        .collect();

    assert_eq!(1, contributions.len());
    let contribution = &contributions[0];
    assert_eq!(0, contribution["nodes"].as_array().unwrap().len());
    let diagnostics = contribution["diagnostics"].as_array().unwrap();
    assert!(diagnostics
        .iter()
        .any(|diagnostic| diagnostic["code"] == "RS_UNSCANNABLE_FILE"));
}

#[test]
fn a_syntax_error_costs_only_its_own_file() {
    let contributions = scan_fixture(
        "syntax-error",
        &[
            ("src/broken.rs", "pub fn ( {\n"),
            ("src/good.rs", "pub fn fine() {}\n"),
        ],
    );

    assert_eq!(2, contributions.len(), "every file gets a contribution");
    let broken = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/broken.rs")
        .unwrap();
    assert_eq!("RS_SYNTAX_ERROR", broken["diagnostics"][0]["code"]);
    assert_eq!("error", broken["diagnostics"][0]["severity"]);
    assert!(
        broken["diagnostics"][0]["evidence"]["start_line"]
            .as_u64()
            .unwrap()
            >= 1
    );

    let good = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/good.rs")
        .unwrap();
    assert!(good["diagnostics"].as_array().unwrap().is_empty());
    assert!(!good["nodes"].as_array().unwrap().is_empty());
}

#[test]
fn a_deeply_nested_file_is_a_diagnostic_and_costs_only_itself() {
    let deep = format!(
        "fn f() {{ let x = {}1{}; }}",
        "(".repeat(4000),
        ")".repeat(4000)
    );
    let contributions = scan_fixture(
        "too-deep",
        &[("src/deep.rs", &deep), ("src/ok.rs", "pub fn ok() {}\n")],
    );

    let deep = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/deep.rs")
        .unwrap();
    assert_eq!("RS_TOO_DEEP", deep["diagnostics"][0]["code"]);
    assert_eq!("error", deep["diagnostics"][0]["severity"]);
    assert_eq!(1, deep["diagnostics"][0]["evidence"]["start_line"]);

    let ok = contributions
        .iter()
        .find(|c| c["owner_key"] == "knossos.rust:file:src/ok.rs")
        .unwrap();
    assert!(ok["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .any(|n| n["kind"] == "function"));
}

#[test]
fn nesting_of_exactly_the_limit_still_parses() {
    // One block plus 255 parentheses is 256 levels. The test thread's stack is
    // small, so the scan runs on one as large as the worker's own.
    std::thread::Builder::new()
        .stack_size(64 * 1024 * 1024)
        .spawn(|| {
            let source = format!(
                "fn f() {{ let x = {}1{}; }}",
                "(".repeat(255),
                ")".repeat(255)
            );
            let contributions = scan_fixture("exactly-deep", &[("src/edge.rs", &source)]);

            assert!(contributions[0]["diagnostics"]
                .as_array()
                .unwrap()
                .is_empty());
            assert!(contributions[0]["nodes"]
                .as_array()
                .unwrap()
                .iter()
                .any(|n| n["kind"] == "function"));
        })
        .unwrap()
        .join()
        .unwrap();
}

#[test]
fn a_missing_file_is_a_diagnostic_not_a_failed_request() {
    let contributions = scan_fixture("missing", &[("src/present.rs", "pub fn here() {}\n")]);
    assert_eq!(1, contributions.len());

    // Scan a path the fixture never wrote, through the same request shape.
    let root = std::env::temp_dir().join("knossos-rust-missing-only");
    std::fs::create_dir_all(&root).unwrap();
    let request = serde_json::json!({
        "jsonrpc": "2.0",
        "id": 1,
        "method": "scan",
        "params": {
            "limits": {"max_files": 100000, "max_file_bytes": 2000000}, "root": std::fs::canonicalize(&root).unwrap().to_str().unwrap(),
            "files": ["src/absent.rs"],
        },
    });
    let mut output: Vec<u8> = Vec::new();
    knossos_rust_worker::server::run(Cursor::new(request.to_string().into_bytes()), &mut output)
        .unwrap();
    let text = String::from_utf8(output).unwrap();
    let _ = std::fs::remove_dir_all(&root);

    assert!(text.contains("RS_UNSCANNABLE_FILE"));
    // Parse each reply rather than substring-matching: the diagnostic itself
    // legitimately carries `"severity":"error"`, so a blind search for the
    // word "error" would fire on that and could never pass. What actually
    // matters is that no reply is a JSON-RPC error object.
    let replies: Vec<Value> = text
        .lines()
        .filter(|line| !line.is_empty())
        .map(|line| serde_json::from_str(line).expect("worker wrote non-JSON stdout"))
        .collect();
    assert!(
        replies.iter().all(|reply| reply.get("error").is_none()),
        "a missing file must not fail the request",
    );
}

#[test]
fn an_oversized_file_is_refused_by_the_byte_limit() {
    let root = std::env::temp_dir().join("knossos-rust-oversized");
    let _ = std::fs::remove_dir_all(&root);
    std::fs::create_dir_all(root.join("src")).unwrap();
    std::fs::write(root.join("src/big.rs"), "pub fn big() {}\n".repeat(100)).unwrap();
    let request = serde_json::json!({
        "jsonrpc": "2.0",
        "id": 1,
        "method": "scan",
        "params": {
            "root": std::fs::canonicalize(&root).unwrap().to_str().unwrap(),
            "files": ["src/big.rs"],
            "limits": {"max_files": 100000, "max_file_bytes": 10},
        },
    });
    let mut output: Vec<u8> = Vec::new();
    knossos_rust_worker::server::run(Cursor::new(request.to_string().into_bytes()), &mut output)
        .unwrap();
    let text = String::from_utf8(output).unwrap();
    let _ = std::fs::remove_dir_all(&root);

    assert!(text.contains("File exceeds the scan byte limit."));
}

#[test]
fn files_scanned_counts_this_request_only() {
    // The core splits one language's work into batches and sends them as
    // separate `scan` requests over the *same* session, summing each
    // request's `files_scanned` into a running total. A worker that reset
    // its counter per process instead of per request (a cumulative total)
    // would only be exposed by a second request in that same session — a
    // single-request test cannot distinguish "counts this request" from
    // "counts everything the process has ever scanned". Two files in the
    // first request and one in the second also rules out a coincidental
    // match against a hardcoded or otherwise-wrong constant.
    let root = std::env::temp_dir().join("knossos-rust-counting-two-requests");
    let _ = std::fs::remove_dir_all(&root);
    std::fs::create_dir_all(root.join("src")).unwrap();
    std::fs::write(root.join("src/a.rs"), "pub fn a() {}\n").unwrap();
    std::fs::write(root.join("src/b.rs"), "pub fn b() {}\n").unwrap();
    std::fs::write(root.join("src/c.rs"), "pub fn c() {}\n").unwrap();
    let canonical_root = std::fs::canonicalize(&root).unwrap();
    let canonical_root = canonical_root.to_str().unwrap();

    let first = serde_json::json!({
        "jsonrpc": "2.0",
        "id": 1,
        "method": "scan",
        "params": {
            "limits": {"max_files": 100000, "max_file_bytes": 2000000}, "root": canonical_root,
            "files": ["src/a.rs", "src/b.rs"],
        },
    });
    let second = serde_json::json!({
        "jsonrpc": "2.0",
        "id": 2,
        "method": "scan",
        "params": {
            "limits": {"max_files": 100000, "max_file_bytes": 2000000}, "root": canonical_root,
            "files": ["src/c.rs"],
        },
    });
    let mut input = first.to_string();
    input.push('\n');
    input.push_str(&second.to_string());
    input.push('\n');

    let mut output: Vec<u8> = Vec::new();
    knossos_rust_worker::server::run(Cursor::new(input.into_bytes()), &mut output).unwrap();
    let text = String::from_utf8(output).unwrap();
    let _ = std::fs::remove_dir_all(&root);

    let results: Vec<Value> = text
        .lines()
        .filter(|line| !line.is_empty())
        .map(|line| serde_json::from_str::<Value>(line).unwrap())
        .filter(|reply| reply.get("result").is_some())
        .collect();
    assert_eq!(2, results.len(), "one result reply per request");
    assert_eq!(
        2, results[0]["result"]["files_scanned"],
        "first request scans two files"
    );
    assert_eq!(
        1, results[1]["result"]["files_scanned"],
        "second request scans one file; a cumulative counter would report 3"
    );
}
