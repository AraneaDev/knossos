//! Scanning tests driving the worker against real files on disk:
//! the hash of every file a request reads, and the reads that fail.

mod common;

use std::io::Cursor;

use knossos_rust_worker::server::run;
use serde_json::Value;

use common::*;

#[test]
fn every_read_file_reports_the_hash_of_its_raw_bytes() {
    let files = [
        ("src/bom.rs", "\u{feff}pub fn bom() {}\n"),
        ("src/crlf.rs", "pub fn crlf() {}\r\n"),
        ("src/broken.rs", "pub fn {\n"),
    ];
    let contributions = scan_fixture("content-hash", &files);

    for (relative, source) in files {
        let owner = format!("knossos.rust:file:{relative}");
        let contribution = contributions
            .iter()
            .find(|c| c["owner_key"] == owner.as_str())
            .unwrap_or_else(|| panic!("no contribution for {relative}"));
        assert_eq!(
            sha256_hex(source.as_bytes()),
            contribution["content_hash"],
            "{relative}"
        );
    }
}

#[test]
fn a_file_that_was_never_read_reports_no_hash() {
    let contributions = scan_fixture_with(
        "content-hash-unread",
        &[("src/big.rs", "pub fn big() {}\n")],
        &serde_json::json!({"limits": {"max_files": 100000, "max_file_bytes": 1}}),
    );

    assert_eq!(1, contributions.len());
    assert!(contributions[0].get("content_hash").is_none());
}

#[test]
fn the_result_reports_input_hashes_for_every_file_it_read() {
    // The exact requested-files map: a BOM file and a syntax-error file both
    // get read (and hashed), so both belong in `input_hashes` even though the
    // syntax-error file contributes no nodes or edges.
    let files: [(&str, &[u8]); 2] = [
        ("src/bom.rs", "\u{feff}pub fn bom() {}\n".as_bytes()),
        ("src/broken.rs", b"pub fn {\n"),
    ];
    let result = scan_result_with_bytes("input-hashes", &files, &serde_json::json!({}));

    let input_hashes = result["input_hashes"]
        .as_object()
        .expect("input_hashes must be a JSON object");
    // Beyond the two files, only the paths a module above them could have
    // been declared in, none of which exists.
    for (relative, hash) in input_hashes {
        assert!(
            hash.is_null() || relative.starts_with("src/b"),
            "{relative}"
        );
    }
    for (relative, bytes) in files {
        assert_eq!(
            Value::String(sha256_hex(bytes)),
            input_hashes[relative],
            "{relative}"
        );
    }
}

#[test]
fn a_requested_file_whose_read_fails_is_reported_as_null() {
    // Over the byte cap, gone, a directory, or a link leaving the root: the
    // filesystem refused the read, and the contribution standing in for the
    // file carries no facts. Null makes the core fail the scan for a
    // discovered path instead of keeping a graph without them.
    let root = fresh_root("input-hashes-read-failures");
    let outside = fresh_root("input-hashes-read-failures-outside");
    std::fs::create_dir_all(root.join("src/dir.rs")).unwrap();
    std::fs::write(root.join("src/big.rs"), "pub fn big() {}\n").unwrap();
    std::fs::write(outside.join("out.rs"), "pub fn out() {}\n").unwrap();
    std::os::unix::fs::symlink(outside.join("out.rs"), root.join("src/out.rs")).unwrap();

    let (contributions, result) = scan_existing_root(
        &root,
        &["src/big.rs", "src/dir.rs", "src/gone.rs", "src/out.rs"],
        &serde_json::json!({"limits": {"max_files": 100000, "max_file_bytes": 10}}),
    );
    let _ = std::fs::remove_dir_all(&root);
    let _ = std::fs::remove_dir_all(&outside);

    assert_eq!(
        serde_json::json!({"src/big.rs": null, "src/dir.rs": null, "src/gone.rs": null, "src/out.rs": null}),
        result["input_hashes"]
    );
    assert_eq!(4, contributions.len());
    for contribution in &contributions {
        assert_eq!(serde_json::json!([]), contribution["nodes"]);
        assert_eq!(
            "RS_UNSCANNABLE_FILE",
            contribution["diagnostics"][0]["code"]
        );
    }
    assert_eq!(
        "Scan path is not a regular file.",
        contributions[1]["diagnostics"][0]["message"]
    );
}

#[test]
fn every_file_below_a_packages_src_reads_both_of_its_crate_roots() {
    // Whether `src/lib.rs` exists decides where the package node attaches and
    // what `src/main.rs`'s module path is, and whether either exists decides
    // whether the files below `src/` belong to a crate. Each file there reads
    // both: an absent root is null, so a discovered root missing for that
    // moment fails the scan, and adding it reaches every one of those files.
    let root = fresh_root("input-hashes-crate-probes");
    std::fs::create_dir_all(root.join("src")).unwrap();
    std::fs::write(root.join("Cargo.toml"), "[package]\nname = \"probe\"\n").unwrap();
    std::fs::write(root.join("src/main.rs"), "fn main() {}\n").unwrap();
    std::fs::write(root.join("src/other.rs"), "pub fn other() {}\n").unwrap();

    let (contributions, result) = scan_existing_root(
        &root,
        &["src/other.rs"],
        &serde_json::json!({"config_files": ["Cargo.toml"]}),
    );
    let _ = std::fs::remove_dir_all(&root);

    let main = Value::String(sha256_hex(b"fn main() {}\n"));
    assert_eq!(Value::Null, result["input_hashes"]["src/lib.rs"]);
    assert_eq!(main, result["input_hashes"]["src/main.rs"]);
    assert_eq!(Value::Null, contributions[0]["reads"]["src/lib.rs"]);
    assert_eq!(main, contributions[0]["reads"]["src/main.rs"]);
    assert_eq!(
        serde_json::json!({"Cargo.toml": sha256_hex(b"[package]\nname = \"probe\"\n")}),
        result["reads"]
    );
}

#[test]
fn a_crate_root_that_is_not_a_regular_file_is_reported_as_null() {
    // Not a file to the probe and not readable to the request: the answer is
    // null, and a requested directory must not be mistaken for a crate root.
    let root = fresh_root("input-hashes-crate-probe-conflict");
    std::fs::create_dir_all(root.join("src/lib.rs")).unwrap();
    std::fs::write(root.join("Cargo.toml"), "[package]\nname = \"probe\"\n").unwrap();

    let (_, result) = scan_existing_root(
        &root,
        &["src/lib.rs"],
        &serde_json::json!({"config_files": ["Cargo.toml"]}),
    );
    let _ = std::fs::remove_dir_all(&root);

    // The directory scans as nothing, so it reads nothing beyond itself.
    assert_eq!(
        serde_json::json!({
            "Cargo.toml": sha256_hex(b"[package]\nname = \"probe\"\n"),
            "src/lib.rs": null,
        }),
        result["input_hashes"]
    );
}

#[test]
fn a_file_that_is_not_utf8_still_reports_the_hash_of_its_raw_bytes() {
    // `prepare_one` reads bytes before it ever tries to decode them, so a file
    // that fails the UTF-8 check has still been read: it must carry the hash
    // of exactly those bytes, the same as a file that goes on to parse
    // successfully or fails later with a syntax error.
    let bytes: &[u8] = b"pub fn go() {\xff}\n";
    let contributions = scan_fixture_with_bytes(
        "content-hash-non-utf8",
        &[("src/binary.rs", bytes)],
        &serde_json::json!({}),
    );

    assert_eq!(1, contributions.len());
    let contribution = &contributions[0];
    assert_eq!(sha256_hex(bytes), contribution["content_hash"]);
    assert_eq!(0, contribution["nodes"].as_array().unwrap().len());
    assert!(contribution["diagnostics"]
        .as_array()
        .unwrap()
        .iter()
        .any(|diagnostic| diagnostic["code"] == "RS_UNSCANNABLE_FILE"));
}

#[test]
fn an_input_hashes_map_larger_than_one_part_goes_out_ahead_of_the_result_in_parts() {
    // Long paths make a batch's map outgrow one part; every frame stays within
    // the part budget and the parts together hold every file read.
    let root = std::env::temp_dir().join("knossos-rust-input-hashes-parts");
    let _ = std::fs::remove_dir_all(&root);
    let directory = format!(
        "src/{}/{}/{}",
        "d".repeat(250),
        "e".repeat(250),
        "f".repeat(250)
    );
    std::fs::create_dir_all(root.join(&directory)).unwrap();
    let mut expected = serde_json::Map::new();
    let mut requested = Vec::new();
    for index in 0..400 {
        let relative = format!("{directory}/value_{index:04}.rs");
        let contents = format!("pub struct Value{index};\n");
        std::fs::write(root.join(&relative), &contents).unwrap();
        expected.insert(
            relative.clone(),
            Value::String(sha256_hex(contents.as_bytes())),
        );
        requested.push(relative);
    }
    let request = serde_json::json!({
        "jsonrpc": "2.0",
        "id": 1,
        "method": "scan",
        "params": {
            "limits": {"max_files": 100000, "max_file_bytes": 2000000}, "root": std::fs::canonicalize(&root).unwrap().to_str().unwrap(),
            "files": requested,
        },
    });
    let mut output: Vec<u8> = Vec::new();
    run(Cursor::new(request.to_string().into_bytes()), &mut output).unwrap();
    let _ = std::fs::remove_dir_all(&root);

    let mut merged = serde_json::Map::new();
    let mut parts = 0;
    let mut result_seen = false;
    for line in String::from_utf8(output).unwrap().lines() {
        let reply: Value = serde_json::from_str(line).unwrap();
        let map = if reply["method"] == "scan/input_hashes" {
            assert!(!result_seen, "a part arrived after the result");
            assert!(line.len() < 256_100, "a part of {} bytes", line.len());
            parts += 1;
            &reply["params"]["input_hashes"]
        } else if reply.get("result").is_some() {
            result_seen = true;
            &reply["result"]["input_hashes"]
        } else {
            continue;
        };
        for (relative, hash) in map.as_object().unwrap() {
            assert!(
                merged.insert(relative.clone(), hash.clone()).is_none(),
                "{relative} twice"
            );
        }
    }

    assert!(result_seen);
    assert!(parts >= 1, "the map went out on the result's line alone");
    // Beyond the files, only probes of the modules above them, none present.
    for (relative, hash) in &expected {
        assert_eq!(Some(hash), merged.get(relative), "{relative}");
    }
    assert!(merged
        .iter()
        .all(|(relative, hash)| expected.contains_key(relative) || hash.is_null()));
}

#[test]
fn a_cargo_manifest_read_for_its_crate_name_reports_the_hash_of_its_raw_bytes() {
    // The manifest names the crate, so its bytes feed facts, and discovery
    // hashes it as a project unit that the core checks the entry against. A
    // manifest that is not UTF-8 was still read and names no crate.
    let root = fresh_root("input-hashes-cargo-manifest");
    std::fs::create_dir_all(root.join("app/src")).unwrap();
    std::fs::create_dir_all(root.join("bad")).unwrap();
    let manifest = b"[package]\nname = \"app\"\n";
    std::fs::write(root.join("app/Cargo.toml"), manifest).unwrap();
    std::fs::write(root.join("app/src/lib.rs"), "pub fn app() {}\n").unwrap();
    std::fs::write(root.join("bad/Cargo.toml"), b"[package]\nname = \"\xff\"\n").unwrap();

    let (contributions, result) = scan_existing_root(
        &root,
        &["app/src/lib.rs"],
        &serde_json::json!({"config_files": ["app/Cargo.toml", "bad/Cargo.toml", "gone/Cargo.toml"]}),
    );
    let _ = std::fs::remove_dir_all(&root);

    assert_eq!(
        Value::String(sha256_hex(manifest)),
        result["input_hashes"]["app/Cargo.toml"]
    );
    assert_eq!(
        Value::String(sha256_hex(b"[package]\nname = \"\xff\"\n")),
        result["input_hashes"]["bad/Cargo.toml"]
    );
    assert_eq!(Value::Null, result["input_hashes"]["gone/Cargo.toml"]);
    assert!(result["input_hashes"].get("bad/src/lib.rs").is_none());
    assert!(contributions[0]["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .any(|node| node["kind"] == "package" && node["canonical_name"] == "app"));
}

#[test]
fn a_cargo_manifest_over_the_byte_cap_is_reported_as_null_and_names_no_crate() {
    let root = fresh_root("input-hashes-cargo-manifest-cap");
    std::fs::create_dir_all(root.join("src")).unwrap();
    std::fs::write(
        root.join("Cargo.toml"),
        format!("[package]\nname = \"big\"\n#{}\n", "x".repeat(64)),
    )
    .unwrap();
    std::fs::write(root.join("src/lib.rs"), "pub fn a() {}\n").unwrap();

    let (contributions, result) = scan_existing_root(
        &root,
        &["src/lib.rs"],
        &serde_json::json!({"config_files": ["Cargo.toml"], "limits": {"max_files": 100000, "max_file_bytes": 40}}),
    );
    let _ = std::fs::remove_dir_all(&root);

    assert_eq!(Value::Null, result["input_hashes"]["Cargo.toml"]);
    assert!(!contributions[0]["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .any(|node| node["kind"] == "package"));
}
