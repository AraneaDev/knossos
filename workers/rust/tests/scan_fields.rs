//! Scanning tests for the field lists of named-field structs and the cases
//! of unit-only enums, open whenever a key may be read by another name.

mod common;

use common::*;
use serde_json::{json, Value};

/// The attributes of the class node `crate::<name>` in a scan of `source`
/// as `src/lib.rs`.
fn class(name: &str, source: &str) -> Value {
    // A root of its own per scan: tests run at once, several on one name.
    static SCANS: std::sync::atomic::AtomicUsize = std::sync::atomic::AtomicUsize::new(0);
    let scan = SCANS.fetch_add(1, std::sync::atomic::Ordering::SeqCst);
    let contributions = scan_fixture(&format!("fields-{name}-{scan}"), &[("src/lib.rs", source)]);
    nodes_of(&contributions, "class")[&format!("crate::{name}")]["attributes"].clone()
}

/// Each listed field's name with its `required`.
fn required(attributes: &Value) -> Vec<(String, bool)> {
    attributes["fields"]
        .as_array()
        .unwrap()
        .iter()
        .map(|field| {
            (
                field["name"].as_str().unwrap().to_owned(),
                field["required"].as_bool().unwrap(),
            )
        })
        .collect()
}

fn pairs(list: &[(&str, bool)]) -> Vec<(String, bool)> {
    list.iter()
        .map(|(name, required)| ((*name).to_owned(), *required))
        .collect()
}

#[test]
fn a_named_struct_lists_its_fields_sorted_with_type_and_line() {
    let source = "#[derive(Debug, Clone, serde::Deserialize)]\npub struct User {\n    pub name: String,\n    pub id: u64,\n    tags: Vec<String>,\n}\n";
    let attributes = class("User", source);

    assert_eq!(
        json!([
            {"name": "id", "type": "u64", "required": true, "line": 4},
            {"name": "name", "type": "String", "required": true, "line": 3},
            {"name": "tags", "type": "Vec<String>", "required": true, "line": 5},
        ]),
        attributes["fields"]
    );
    assert!(attributes.get("fields_open").is_none());
}

#[test]
fn an_option_field_is_not_required_under_serde() {
    let source = "#[derive(Deserialize)]\npub struct User {\n    a: Option<u8>,\n    b: std::option::Option<u8>,\n    c: core::option::Option<u8>,\n    d: Vec<Option<u8>>,\n    e: Box<Option<u8>>,\n    f: std::sync::Arc<Option<u8>>,\n    g: Box<u8>,\n}\n";

    assert_eq!(
        pairs(&[
            ("a", false),
            ("b", false),
            ("c", false),
            ("d", true),
            ("e", false),
            ("f", false),
            ("g", true)
        ]),
        required(&class("User", source))
    );
}

#[test]
fn a_serde_default_skip_or_type_parameter_field_is_not_required() {
    let source = "#[derive(Deserialize)]\npub struct Page<T> {\n    #[serde(default)]\n    a: u8,\n    #[serde(default = \"seven\")]\n    b: u8,\n    #[serde(skip_deserializing)]\n    c: u8,\n    #[serde(skip)]\n    d: u8,\n    e: T,\n    #[serde(skip_serializing_if = \"Vec::is_empty\")]\n    f: Vec<T>,\n}\n";
    let attributes = class("Page", source);

    assert_eq!(
        pairs(&[
            ("a", false),
            ("b", false),
            ("c", false),
            ("d", false),
            ("e", false),
            ("f", true)
        ]),
        required(&attributes)
    );
    assert!(attributes.get("fields_open").is_none());
}

#[test]
fn a_container_default_makes_every_field_optional() {
    let source = "#[derive(Deserialize, Default)]\n#[serde(default, deny_unknown_fields)]\npub struct Settings {\n    a: u8,\n    b: String,\n}\n";
    let attributes = class("Settings", source);

    assert_eq!(pairs(&[("a", false), ("b", false)]), required(&attributes));
    assert!(attributes.get("fields_open").is_none());
}

#[test]
fn a_camel_case_rename_all_opens_the_struct() {
    let source = "#[derive(Deserialize)]\n#[serde(rename_all = \"camelCase\")]\npub struct User {\n    user_id: u64,\n}\n";

    assert_eq!(Some(&json!(true)), class("User", source).get("fields_open"));
}

#[test]
fn every_renaming_serde_attribute_opens_the_struct() {
    let attributes = [
        "#[serde(rename = \"U\")]",
        "#[serde(rename(deserialize = \"U\"))]",
        "#[serde(rename_all(deserialize = \"kebab-case\"))]",
        "#[serde(transparent)]",
        "#[serde(tag = \"type\")]",
        "#[serde(untagged)]",
        "#[serde(from = \"Raw\")]",
        "#[serde(try_from = \"Raw\")]",
        "#[serde(remote = \"Other\")]",
        "#[serde(unknown_key_we_do_not_know)]",
        "#[serde(rename_all = )]",
        "#[cfg_attr(feature = \"serde\", serde(rename_all = \"camelCase\"))]",
        "#[cfg_attr(feature = \"serde\", cfg_attr(test, serde(rename_all = \"camelCase\")))]",
        "#[derive(Builder)]",
        "#[derive(my_crate::Deserialize)]",
        "#[serde_with::serde_as]",
        "#[skip_serializing_none]",
        "#[pyclass]",
    ];
    for attribute in attributes {
        let source = format!(
            "#[derive(serde::Serialize, serde::Deserialize)]\n{attribute}\npub struct User {{\n    user_id: u64,\n}}\n"
        );
        assert_eq!(
            Some(&json!(true)),
            class("User", &source).get("fields_open"),
            "{attribute}"
        );
    }
}

#[test]
fn every_renaming_field_attribute_opens_the_struct() {
    let attributes = [
        "#[serde(rename = \"userId\")]",
        "#[serde(rename(deserialize = \"userId\"))]",
        "#[serde(alias = \"userId\")]",
        "#[serde(flatten)]",
        "#[serde(something_new)]",
        "#[cfg(feature = \"ids\")]",
        "#[cfg_attr(feature = \"serde\", serde(rename = \"userId\"))]",
        "#[serde_as(as = \"DisplayFromStr\")]",
        "#[builder(default)]",
    ];
    for attribute in attributes {
        let source = format!(
            "#[derive(Deserialize)]\npub struct User {{\n    {attribute}\n    user_id: u64,\n    name: String,\n}}\n"
        );
        assert_eq!(
            Some(&json!(true)),
            class("User", &source).get("fields_open"),
            "{attribute}"
        );
    }
}

#[test]
fn the_frozen_std_derives_and_inert_attributes_keep_the_list_closed() {
    let source = "/// A user.\n#[derive(Clone, Copy, Debug, Default, Eq, Hash, Ord, PartialEq, PartialOrd, Serialize, Deserialize)]\n#[derive(std::fmt::Debug, serde_derive::Deserialize)]\n#[cfg_attr(feature = \"serde\", derive(serde::Serialize))]\n#[non_exhaustive]\n#[repr(C)]\n#[allow(dead_code)]\n#[serde(deny_unknown_fields, bound = \"\", expecting = \"a user\")]\npub struct User {\n    /// The id.\n    #[allow(unused)]\n    #[serde(skip_serializing, borrow)]\n    id: u64,\n}\n";
    let attributes = class("User", source);

    assert!(attributes.get("fields_open").is_none(), "{attributes}");
    assert_eq!(pairs(&[("id", true)]), required(&attributes));
}

#[test]
fn a_with_or_deserialize_with_field_drops_its_type_but_keeps_its_key() {
    let source = "#[derive(Deserialize)]\npub struct Event {\n    #[serde(with = \"ts_seconds\")]\n    at: DateTime,\n    #[serde(deserialize_with = \"from_str\")]\n    count: u64,\n}\n";
    let attributes = class("Event", source);

    assert_eq!(
        json!([
            {"name": "at", "required": true, "line": 4},
            {"name": "count", "required": true, "line": 6},
        ]),
        attributes["fields"]
    );
    assert!(attributes.get("fields_open").is_none());
}

#[test]
fn a_tuple_or_unit_struct_sends_no_list() {
    let source = "pub struct Id(u64);\npub struct Marker;\npub union Bits { a: u32, b: f32 }\n";
    let contributions = scan_fixture("fields-tuple", &[("src/lib.rs", source)]);
    let classes = nodes_of(&contributions, "class");

    for name in ["crate::Id", "crate::Marker", "crate::Bits"] {
        assert!(
            classes[name]["attributes"].get("fields").is_none(),
            "{name}"
        );
    }
}

#[test]
fn a_struct_a_macro_generates_sends_no_list() {
    let source = "macro_rules! dto { ($name:ident) => { pub struct $name { pub id: u64 } } }\ndto!(Generated);\n";
    let contributions = scan_fixture("fields-macro", &[("src/lib.rs", source)]);

    assert!(nodes_of(&contributions, "class")
        .values()
        .all(|node| node["attributes"].get("fields").is_none()));
}

#[test]
fn two_cfg_alternatives_of_one_struct_open_the_list() {
    let source = "#[cfg(unix)]\npub struct Config {\n    a: u8,\n}\n#[cfg(not(unix))]\npub struct Config {\n    b: u8,\n}\n";

    let contributions = scan_fixture("fields-cfg-alternatives", &[("src/lib.rs", source)]);
    let declared: Vec<&Value> = contributions[0]["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .filter(|node| node["local_id"] == "rust:class:crate::Config")
        .collect();

    // Each declaration is a node of its own; whichever the core keeps, no list is closed.
    assert_eq!(2, declared.len());
    assert_eq!(
        Some(&json!(true)),
        declared[0]["attributes"].get("fields_open")
    );
    assert!(declared[1]["attributes"].get("fields").is_none());
}

#[test]
fn a_struct_in_an_inline_module_lists_its_fields() {
    let source = "pub mod dto {\n    #[derive(Deserialize)]\n    pub struct User {\n        id: u64,\n    }\n}\n";
    let contributions = scan_fixture("fields-inline", &[("src/lib.rs", source)]);
    let attributes = &nodes_of(&contributions, "class")["crate::dto::User"]["attributes"];

    assert_eq!(pairs(&[("id", true)]), required(attributes));
}

#[test]
fn a_long_list_is_cut_and_marked() {
    let fields: String = (0..70)
        .map(|index| format!("    f{index:03}: u8,\n"))
        .collect();
    let attributes = class("Wide", &format!("pub struct Wide {{\n{fields}}}\n"));

    assert_eq!(64, attributes["fields"].as_array().unwrap().len());
    assert_eq!("f063", attributes["fields"][63]["name"]);
    assert_eq!(Some(&json!(true)), attributes.get("fields_truncated"));
}

#[test]
fn a_unit_only_enum_lists_its_cases_by_name() {
    let source = "#[derive(Debug, Deserialize)]\n#[non_exhaustive]\npub enum Lane {\n    /// Fast.\n    Fast,\n    Slow = 2,\n    #[allow(unused)]\n    Bus,\n}\n";
    let attributes = class("Lane", source);

    assert_eq!(
        json!([
            {"name": "Bus", "value": "Bus"},
            {"name": "Fast", "value": "Fast"},
            {"name": "Slow", "value": "Slow"},
        ]),
        attributes["cases"]
    );
    assert!(attributes.get("fields").is_none());
}

#[test]
fn an_enum_whose_wire_names_may_differ_sends_no_cases() {
    let sources = [
        "#[derive(Deserialize)]\n#[serde(rename_all = \"snake_case\")]\npub enum Lane { Fast, Slow }\n",
        "#[derive(Deserialize)]\npub enum Lane { #[serde(rename = \"fast\")] Fast, Slow }\n",
        "#[derive(Deserialize)]\npub enum Lane { #[serde(alias = \"quick\")] Fast, Slow }\n",
        "#[derive(Deserialize)]\n#[serde(tag = \"t\", content = \"c\")]\npub enum Lane { Fast, Slow }\n",
        "#[derive(Deserialize)]\n#[serde(untagged)]\npub enum Lane { Fast, Slow }\n",
        "#[derive(Deserialize)]\n#[serde(deny_unknown_fields)]\npub enum Lane { Fast, Slow }\n",
        "#[derive(Deserialize)]\npub enum Lane { Fast, Slow(u8) }\n",
        "#[derive(Deserialize)]\npub enum Lane { Fast, Slow { speed: u8 } }\n",
        "#[derive(Deserialize_repr)]\n#[repr(u8)]\npub enum Lane { Fast = 1, Slow = 2 }\n",
        "#[derive(Deserialize)]\npub enum Lane { Fast, #[cfg(feature = \"slow\")] Slow }\n",
        "#[cfg_attr(feature = \"serde\", serde(rename_all = \"lowercase\"))]\npub enum Lane { Fast, Slow }\n",
        "#[strum(serialize_all = \"snake_case\")]\npub enum Lane { Fast, Slow }\n",
    ];
    for source in sources {
        let attributes = class("Lane", source);
        assert!(attributes.get("cases").is_none(), "{source}");
        assert!(attributes.get("fields").is_none(), "{source}");
    }
}
