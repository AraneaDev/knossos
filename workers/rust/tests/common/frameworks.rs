//! Helpers the mocking-crate scans share: a one-package crate, and the
//! doubles, members, operands and edges it gives.

use serde_json::{json, Value};

use super::{edges_of, nodes_of, scan_fixture_with};

/// Scan a one-package crate whose `src/lib.rs` is `source`, as `name`.
pub fn scan(name: &str, source: &str) -> Vec<Value> {
    scan_fixture_with(
        name,
        &[
            ("Cargo.toml", "[package]\nname = \"probe\"\n"),
            ("src/lib.rs", source),
        ],
        &json!({ "config_files": ["Cargo.toml"] }),
    )
}

/// The attributes of the double `canonical`.
pub fn double(contributions: &[Value], canonical: &str) -> Value {
    let doubles = nodes_of(contributions, "test_double");
    doubles
        .get(canonical)
        .unwrap_or_else(|| panic!("no double {canonical}: {:?}", doubles.keys()))["attributes"]
        .clone()
}

/// The members of a double as (name, api, pinning verbs).
pub fn members(attributes: &Value) -> Vec<(String, String, Vec<String>)> {
    attributes["members"]
        .as_array()
        .unwrap()
        .iter()
        .map(|member| {
            let pins = member["returns"]
                .as_array()
                .unwrap()
                .iter()
                .map(|pin| pin["api"].as_str().unwrap().to_owned())
                .collect();
            (
                member["name"].as_str().unwrap().to_owned(),
                member["api"].as_str().unwrap().to_owned(),
                pins,
            )
        })
        .collect()
}

/// The member list `[(name, api, [pins])]` written briefly.
pub fn expected(list: &[(&str, &str, &[&str])]) -> Vec<(String, String, Vec<String>)> {
    list.iter()
        .map(|(name, api, pins)| {
            (
                (*name).to_owned(),
                (*api).to_owned(),
                pins.iter().map(|pin| (*pin).to_owned()).collect(),
            )
        })
        .collect()
}

/// The operands of each assertion of the case on `line` of `src/lib.rs`.
pub fn operands(contributions: &[Value], line: usize) -> Vec<Value> {
    let cases = nodes_of(contributions, "test_case");
    cases[&format!("src/lib.rs#test:{line}")]["attributes"]["assertions"]
        .as_array()
        .unwrap()
        .iter()
        .map(|assertion| assertion["operands"].clone())
        .collect()
}

/// The `doubles` edge of `double`, if any.
pub fn target(contributions: &[Value], double: &str) -> Option<String> {
    let source = format!("rust:test_double:src/lib.rs#double:{double}");
    edges_of(contributions, "doubles")
        .into_iter()
        .find(|(from, _)| *from == source)
        .map(|(_, to)| to)
}

/// The frameworks of every double the scan gives.
pub fn frameworks(contributions: &[Value]) -> Vec<String> {
    nodes_of(contributions, "test_double")
        .values()
        .map(|node| node["attributes"]["framework"].as_str().unwrap().to_owned())
        .collect()
}

/// The faux mock every defeat case configures: `Foo`, its `get` and
/// `other`, and a helper handed the mock.
pub const FAUX: &str = "#[faux::create]\npub struct Foo {\n    a: u32,\n}\n#[faux::methods]\nimpl Foo {\n    \
    pub fn get(&self) -> u32 {\n        self.a\n    }\n    pub fn other(&self) -> u32 {\n        self.a\n    }\n}\n\
    pub fn configure(foo: &mut Foo) {\n    let _ = foo;\n}\n";

/// The value the last assertion's first operand gives in the case `t` whose
/// body follows `let mut mock = Foo::faux();` with `body`.
pub fn faux_value(name: &str, body: &str) -> Value {
    let source = format!("{FAUX}#[test]\nfn t() {{\n    let mut mock = Foo::faux();\n{body}}}\n");
    let contributions = scan(name, &source);
    let case = &nodes_of(&contributions, "test_case")["src/lib.rs#test:18"];
    let assertions = case["attributes"]["assertions"].as_array().unwrap();
    let operand = assertions.last().unwrap()["operands"][0].clone();
    assert_eq!("mock-config", operand["source"], "{body}");
    operand["value"].clone()
}
