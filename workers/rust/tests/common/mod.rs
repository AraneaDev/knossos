//! Helpers the scanning tests share: scan a fixture tree, and read the
//! edges, nodes and routes a scan answered with.
#![allow(dead_code)]

use std::collections::BTreeMap;
use std::io::Cursor;

use knossos_rust_worker::server::run;
use serde_json::Value;

/// Write `files` (raw bytes) into a fresh temporary root and run a scan
/// request with `params` merged over the base (`root`, `files`), returning
/// contributions. The byte-oriented base every other fixture helper here
/// builds on, so a fixture that needs non-UTF-8 content does not have to
/// duplicate the request/response plumbing.
pub fn scan_fixture_with_bytes(name: &str, files: &[(&str, &[u8])], params: &Value) -> Vec<Value> {
    let root = std::env::temp_dir().join(format!("knossos-rust-{name}"));
    let _ = std::fs::remove_dir_all(&root);
    for (relative, bytes) in files {
        let path = root.join(relative);
        std::fs::create_dir_all(path.parent().unwrap()).unwrap();
        std::fs::write(&path, bytes).unwrap();
    }
    let base = serde_json::json!({
        "limits": {"max_files": 100000, "max_file_bytes": 2000000}, "root": std::fs::canonicalize(&root).unwrap().to_str().unwrap(),
        "files": files.iter().map(|(relative, _)| *relative).collect::<Vec<_>>(),
    });
    let mut merged = base.as_object().unwrap().clone();
    for (key, value) in params.as_object().unwrap() {
        merged.insert(key.clone(), value.clone());
    }
    let request = serde_json::json!({
        "jsonrpc": "2.0",
        "id": 1,
        "method": "scan",
        "params": merged,
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

    replies
        .into_iter()
        .filter(|reply| reply["method"] == "scan/contribution")
        .map(|reply| reply["params"].clone())
        .collect()
}

/// Like [`scan_fixture_with_bytes`], but returns the request's final `result`
/// object (where `input_hashes` lives) instead of its contributions.
pub fn scan_result_with_bytes(name: &str, files: &[(&str, &[u8])], params: &Value) -> Value {
    let root = std::env::temp_dir().join(format!("knossos-rust-{name}"));
    let _ = std::fs::remove_dir_all(&root);
    for (relative, bytes) in files {
        let path = root.join(relative);
        std::fs::create_dir_all(path.parent().unwrap()).unwrap();
        std::fs::write(&path, bytes).unwrap();
    }
    let base = serde_json::json!({
        "limits": {"max_files": 100000, "max_file_bytes": 2000000}, "root": std::fs::canonicalize(&root).unwrap().to_str().unwrap(),
        "files": files.iter().map(|(relative, _)| *relative).collect::<Vec<_>>(),
    });
    let mut merged = base.as_object().unwrap().clone();
    for (key, value) in params.as_object().unwrap() {
        merged.insert(key.clone(), value.clone());
    }
    let request = serde_json::json!({
        "jsonrpc": "2.0",
        "id": 1,
        "method": "scan",
        "params": merged,
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

    replies
        .into_iter()
        .find(|reply| reply.get("result").is_some())
        .map(|reply| reply["result"].clone())
        .expect("scan request produced no result")
}

/// Write `files` into a fresh temporary root and run a scan request with
/// `params` merged over the base (`root`, `files`), returning contributions.
pub fn scan_fixture_with(name: &str, files: &[(&str, &str)], params: &Value) -> Vec<Value> {
    let byte_files: Vec<(&str, &[u8])> = files
        .iter()
        .map(|(relative, source)| (*relative, source.as_bytes()))
        .collect();
    scan_fixture_with_bytes(name, &byte_files, params)
}

/// Write `files` into a fresh temporary root and scan them, returning contributions.
pub fn scan_fixture(name: &str, files: &[(&str, &str)]) -> Vec<Value> {
    scan_fixture_with(name, files, &serde_json::json!({}))
}

/// The `calls` edges from `source` into a `::<method>` target, as
/// `(target, speculative)` pairs.
pub fn method_calls(contributions: &[Value], source: &str) -> Vec<(String, bool)> {
    let mut calls: Vec<(String, bool)> = contributions
        .iter()
        .flat_map(|contribution| contribution["edges"].as_array().unwrap().clone())
        .filter(|edge| edge["kind"] == "calls" && edge["source"] == source)
        .map(|edge| {
            (
                edge["target"].as_str().unwrap().to_owned(),
                edge["attributes"]["speculative"] == Value::Bool(true),
            )
        })
        .collect();
    calls.sort();
    calls
}

pub fn sha256_hex(bytes: &[u8]) -> String {
    use sha2::{Digest, Sha256};
    Sha256::digest(bytes)
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

/// Scan `requested` under an existing `root`, returning the contributions and
/// the final result. Unlike the fixture helpers, the requested paths need not
/// be files the test wrote, so a test can ask for what is missing.
pub fn scan_existing_root(
    root: &std::path::Path,
    requested: &[&str],
    params: &Value,
) -> (Vec<Value>, Value) {
    let mut merged = serde_json::json!({
        "limits": {"max_files": 100000, "max_file_bytes": 2000000}, "root": std::fs::canonicalize(root).unwrap().to_str().unwrap(),
        "files": requested,
    });
    for (key, value) in params.as_object().unwrap() {
        merged[key] = value.clone();
    }
    let request =
        serde_json::json!({"jsonrpc": "2.0", "id": 1, "method": "scan", "params": merged});
    let mut output: Vec<u8> = Vec::new();
    run(Cursor::new(request.to_string().into_bytes()), &mut output).unwrap();
    let replies: Vec<Value> = String::from_utf8(output)
        .unwrap()
        .lines()
        .filter(|line| !line.is_empty())
        .map(|line| serde_json::from_str(line).unwrap())
        .collect();
    let contributions = replies
        .iter()
        .filter(|reply| reply["method"] == "scan/contribution")
        .map(|reply| reply["params"].clone())
        .collect();
    let result = replies
        .iter()
        .find(|reply| reply.get("result").is_some())
        .map(|reply| reply["result"].clone())
        .expect("scan request produced no result");

    (contributions, result)
}

/// A fresh, empty temporary root named for the test.
pub fn fresh_root(name: &str) -> std::path::PathBuf {
    let root = std::env::temp_dir().join(format!("knossos-rust-{name}"));
    let _ = std::fs::remove_dir_all(&root);
    std::fs::create_dir_all(&root).unwrap();
    root
}

/// Every edge of `kind` as a `(source, target)` pair, sorted.
pub fn edges_of(contributions: &[Value], kind: &str) -> Vec<(String, String)> {
    let mut edges: Vec<(String, String)> = contributions
        .iter()
        .flat_map(|contribution| contribution["edges"].as_array().unwrap().clone())
        .filter(|edge| edge["kind"] == kind)
        .map(|edge| {
            (
                edge["source"].as_str().unwrap().to_owned(),
                edge["target"].as_str().unwrap().to_owned(),
            )
        })
        .collect();
    edges.sort();
    edges
}

/// Every `calls` edge target of the contributions, from any source.
pub fn all_call_targets(contributions: &[Value]) -> Vec<String> {
    contributions
        .iter()
        .flat_map(|contribution| contribution["edges"].as_array().unwrap().clone())
        .filter(|edge| edge["kind"] == "calls")
        .map(|edge| edge["target"].as_str().unwrap().to_owned())
        .collect()
}

/// Every edge of `kind` leaving `source`, by target.
pub fn targets_of(contributions: &[Value], kind: &str, source: &str) -> Vec<String> {
    let mut targets: Vec<String> = contributions
        .iter()
        .flat_map(|contribution| contribution["edges"].as_array().unwrap().clone())
        .filter(|edge| edge["kind"] == kind && edge["source"] == source)
        .map(|edge| edge["target"].as_str().unwrap().to_owned())
        .collect();
    targets.sort();
    targets
}

/// The route nodes of one contribution, by canonical name.
pub fn routes_of(contribution: &serde_json::Value) -> BTreeMap<String, serde_json::Value> {
    contribution["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .filter(|node| node["kind"] == "route")
        .map(|node| {
            (
                node["canonical_name"].as_str().unwrap().to_owned(),
                node.clone(),
            )
        })
        .collect()
}

/// Every node's canonical name across the contributions.
pub fn canonical_names(contributions: &[Value]) -> Vec<String> {
    contributions
        .iter()
        .flat_map(|contribution| contribution["nodes"].as_array().unwrap().clone())
        .map(|node| node["canonical_name"].as_str().unwrap().to_owned())
        .collect()
}

/// The node of `canonical` in the contribution of `file`.
pub fn node_in(contributions: &[Value], file: &str, canonical: &str) -> Option<Value> {
    contributions
        .iter()
        .find(|contribution| contribution["owner_key"] == format!("knossos.rust:file:{file}"))
        .and_then(|contribution| {
            contribution["nodes"]
                .as_array()
                .unwrap()
                .iter()
                .find(|node| node["canonical_name"] == canonical)
                .cloned()
        })
}

/// The `calls` edges from `source` to a method, without the ones to
/// functions and constructors a fixture also makes.
pub fn method_targets(contributions: &[Value], source: &str) -> Vec<String> {
    method_calls(contributions, source)
        .into_iter()
        .map(|(target, _)| target)
        .filter(|target| target.starts_with("rust:method:"))
        .collect()
}
