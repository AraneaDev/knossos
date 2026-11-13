//! Scanning tests driving the worker against real files on disk:
//! framework routes, and the programs a command or test launches.

mod common;

use serde_json::Value;

use common::*;

#[test]
fn axum_routes_become_route_nodes_and_edges() {
    let source = r#"
use axum::Router;
use axum::routing::get;

fn handler() {}

fn app() -> Router {
    Router::new().route("/health", get(handler))
}
"#;
    let contributions = scan_fixture_with(
        "axum-routes",
        &[("src/lib.rs", source)],
        &serde_json::json!({ "frameworks": ["axum"] }),
    );
    let nodes = contributions[0]["nodes"].as_array().unwrap();
    let route = nodes
        .iter()
        .find(|node| node["kind"] == "route")
        .expect("axum route node missing");
    assert_eq!("GET /health => crate::handler", route["canonical_name"]);
    assert_eq!("axum", route["attributes"]["framework"]);
    assert_eq!("/health", route["attributes"]["path"]);

    let edges = contributions[0]["edges"].as_array().unwrap();
    assert!(edges.iter().any(|edge| {
        edge["kind"] == "routes_to"
            && edge["source"] == "rust:route:GET /health => crate::handler"
            && edge["target"] == "rust:function:crate::handler"
    }));
}

#[test]
fn axum_routes_are_diagnosed_when_framework_not_requested() {
    let source = r#"
use axum::Router;
use axum::routing::get;

fn handler() {}

fn app() -> Router {
    Router::new().route("/health", get(handler))
}
"#;
    let contributions = scan_fixture("axum-unrequested", &[("src/lib.rs", source)]);
    let nodes = contributions[0]["nodes"].as_array().unwrap();
    assert!(
        !nodes.iter().any(|node| node["kind"] == "route"),
        "no route without the axum framework hint"
    );
}

#[test]
fn actix_attribute_routes_are_recorded() {
    let source = r#"
#[get("/ping")]
#[route("/pong", method = "PUT")]
fn ping() {}
"#;
    let contributions = scan_fixture_with(
        "actix-attrs",
        &[("src/lib.rs", source)],
        &serde_json::json!({ "frameworks": ["actix"] }),
    );
    let nodes = contributions[0]["nodes"].as_array().unwrap();
    let routes: Vec<&Value> = nodes
        .iter()
        .filter(|node| node["kind"] == "route")
        .collect();
    assert_eq!(2, routes.len());
    assert!(routes
        .iter()
        .any(|route| route["canonical_name"] == "GET /ping => crate::ping"));
    assert!(routes
        .iter()
        .any(|route| route["canonical_name"] == "PUT /pong => crate::ping"));
    let handler = nodes
        .iter()
        .find(|node| node["canonical_name"] == "crate::ping")
        .unwrap();
    assert_eq!(
        serde_json::json!(["rust.route_handler"]),
        handler["attributes"]["rust_framework_roles"]
    );
}

#[test]
fn actix_call_routes_are_recorded() {
    let source = r#"
fn handler() {}

fn app() {
    web::resource("/health").route(web::get().to(handler));
}
"#;
    let contributions = scan_fixture_with(
        "actix-call-route",
        &[("src/routes.rs", source)],
        &serde_json::json!({ "frameworks": ["actix"] }),
    );
    let nodes = contributions[0]["nodes"].as_array().unwrap();
    assert!(nodes.iter().any(|node| {
        node["kind"] == "route" && node["canonical_name"] == "GET /health => crate::routes::handler"
    }));
    assert!(contributions[0]["edges"]
        .as_array()
        .unwrap()
        .iter()
        .any(|edge| {
            edge["kind"] == "routes_to" && edge["target"] == "rust:function:crate::routes::handler"
        }));
}

#[test]
fn an_axum_route_with_a_parameter_keeps_its_template() {
    let source = r#"
use axum::Router;
use axum::routing::get;

fn handler() {}

fn app() -> Router {
    Router::new().route("/users/:id", get(handler))
}
"#;
    let contributions = scan_fixture_with(
        "axum-template-route",
        &[("src/lib.rs", source)],
        &serde_json::json!({ "frameworks": ["axum"] }),
    );
    assert!(contributions[0]["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .any(|node| node["kind"] == "route"
            && node["canonical_name"] == "GET /users/:id => crate::handler"));
    assert!(!contributions[0]["diagnostics"]
        .as_array()
        .unwrap()
        .iter()
        .any(|diagnostic| diagnostic["code"] == "RS_DYNAMIC_ROUTE_PATH"));
}

#[test]
fn an_axum_route_path_built_at_runtime_is_diagnosed_not_guessed() {
    let source = r#"
use axum::Router;
use axum::routing::get;

fn handler() {}

fn app(prefix: &str) -> Router {
    Router::new().route(prefix, get(handler))
}
"#;
    let contributions = scan_fixture_with(
        "axum-dynamic-route",
        &[("src/lib.rs", source)],
        &serde_json::json!({ "frameworks": ["axum"] }),
    );
    assert!(!contributions[0]["nodes"]
        .as_array()
        .unwrap()
        .iter()
        .any(|node| node["kind"] == "route"));
    assert!(contributions[0]["diagnostics"]
        .as_array()
        .unwrap()
        .iter()
        .any(|diagnostic| diagnostic["code"] == "RS_DYNAMIC_ROUTE_PATH"));
}

#[test]
fn rocket_attribute_routes_are_recorded() {
    let source = r#"
#[get("/world")]
fn world() {}
"#;
    let contributions = scan_fixture_with(
        "rocket-attrs",
        &[("src/lib.rs", source)],
        &serde_json::json!({ "frameworks": ["rocket"] }),
    );
    let nodes = contributions[0]["nodes"].as_array().unwrap();
    assert!(nodes
        .iter()
        .any(|node| node["kind"] == "route"
            && node["canonical_name"] == "GET /world => crate::world"));
}

#[test]
fn attribute_routes_with_parameters_keep_their_templates() {
    for (framework, path) in [("rocket", "/users/<id>"), ("actix", "/users/{id}")] {
        let source = format!("#[get(\"{path}\")]\nfn user() {{}}\n");
        let contributions = scan_fixture_with(
            &format!("template-route-{framework}"),
            &[("src/lib.rs", source.as_str())],
            &serde_json::json!({ "frameworks": [framework] }),
        );
        let nodes = contributions[0]["nodes"].as_array().unwrap();
        let name = format!("GET {path} => crate::user");
        assert!(
            nodes
                .iter()
                .any(|node| node["kind"] == "route" && node["canonical_name"] == name.as_str()),
            "{framework}: no route {name}"
        );
        let diagnostics = contributions[0]["diagnostics"].as_array().unwrap();
        assert!(!diagnostics
            .iter()
            .any(|diag| diag["code"] == "RS_DYNAMIC_ROUTE_PATH"));
    }
}

#[test]
fn an_axum_route_inside_a_nest_takes_its_prefix() {
    let source = r#"
use axum::Router;
use axum::routing::get;

fn user() {}

fn app() -> Router {
    Router::new().nest("/api", Router::new().route("/users/:id", get(user)))
}
"#;
    let contributions = scan_fixture_with(
        "axum-inline-nest",
        &[("src/lib.rs", source)],
        &serde_json::json!({ "frameworks": ["axum"] }),
    );
    let routes = routes_of(&contributions[0]);
    assert!(
        routes.contains_key("GET /api/users/:id => crate::user"),
        "{routes:?}"
    );
    assert_eq!(routes.len(), 1);
}

#[test]
fn an_axum_router_built_in_another_function_is_mounted_with_its_prefix() {
    let source = r#"
use axum::Router;
use axum::routing::get;

fn user() {}

fn api() -> Router {
    Router::new().route("/users/:id", get(user))
}

fn app() -> Router {
    Router::new().nest("/api", api()).merge(api())
}
"#;
    let contributions = scan_fixture_with(
        "axum-function-nest",
        &[("src/lib.rs", source)],
        &serde_json::json!({ "frameworks": ["axum"] }),
    );
    let routes = routes_of(&contributions[0]);
    let route = &routes["GET /users/:id => crate::user"];
    assert_eq!(route["attributes"]["router"], "rust:function:crate::api");
    let mounts: Vec<&serde_json::Value> = contributions[0]["edges"]
        .as_array()
        .unwrap()
        .iter()
        .filter(|edge| edge["kind"] == "mounts")
        .collect();
    let prefixes: Vec<(&str, &str, &str, &str)> = mounts
        .iter()
        .map(|edge| {
            (
                edge["source"].as_str().unwrap(),
                edge["target"].as_str().unwrap(),
                edge["attributes"]["prefix"].as_str().unwrap(),
                edge["attributes"]["onto"].as_str().unwrap(),
            )
        })
        .collect();
    assert!(prefixes.contains(&(
        "rust:function:crate::app",
        "rust:function:crate::api",
        "/api",
        "rust:function:crate::app"
    )));
    assert!(prefixes.contains(&(
        "rust:function:crate::app",
        "rust:function:crate::api",
        "",
        "rust:function:crate::app"
    )));
}

#[test]
fn actix_routes_under_scopes_take_every_scope_prefix() {
    let source = r#"
use actix_web::{web, App};

fn user() {}
fn health() {}

fn app() {
    App::new()
        .service(web::scope("/v1").service(web::scope("/users").route("/{id}", web::get().to(user))))
        .route("/health", web::get().to(health));
}
"#;
    let contributions = scan_fixture_with(
        "actix-scopes",
        &[("src/lib.rs", source)],
        &serde_json::json!({ "frameworks": ["actix"] }),
    );
    let routes = routes_of(&contributions[0]);
    assert!(
        routes.contains_key("GET /v1/users/{id} => crate::user"),
        "{routes:?}"
    );
    assert!(
        routes.contains_key("GET /health => crate::health"),
        "{routes:?}"
    );
}

#[test]
fn a_command_running_a_script_or_a_program_path_launches_that_file() {
    let source = r#"
use std::process::Command;

fn start() {
    Command::new("python3").arg("-I").arg("tools/run.py").status().ok();
    Command::new("node").args(["--expose-gc", "js/worker.js"]).status().ok();
    Command::new("./target/release/tool").status().ok();
    Command::new("node").arg("--version").status().ok();
    Command::new("git").arg("status").status().ok();
}
"#;
    let contributions = scan_fixture_with(
        "command-launches",
        &[("src/lib.rs", source)],
        &serde_json::json!({}),
    );
    let mut launched: Vec<(String, String, String)> = contributions[0]["edges"]
        .as_array()
        .unwrap()
        .iter()
        .filter(|edge| edge["kind"] == "launches")
        .map(|edge| {
            (
                edge["target"].as_str().unwrap().to_owned(),
                edge["confidence"].as_str().unwrap().to_owned(),
                edge["attributes"]["speculative"].to_string(),
            )
        })
        .collect();
    launched.sort();
    assert_eq!(
        launched,
        vec![
            (
                "rust:launched_file:js/worker.js".to_owned(),
                "probable".to_owned(),
                "true".to_owned()
            ),
            (
                "rust:launched_file:target/release/tool".to_owned(),
                "probable".to_owned(),
                "true".to_owned()
            ),
            (
                "rust:launched_file:tools/run.py".to_owned(),
                "probable".to_owned(),
                "true".to_owned()
            ),
        ]
    );
}

#[test]
fn a_test_naming_a_binary_through_cargo_bin_exe_launches_it() {
    // Cargo hands an integration test the path of each binary of its
    // package as `CARGO_BIN_EXE_<name>`; the test spawns it, which no call
    // records, so the binary read as untested.
    let test = r#"
#[test]
fn runs_the_fixture() {
    let status = std::process::Command::new(env!("CARGO_BIN_EXE_fixture_main")).status();
    let _ = status;
}
"#;
    let contributions = scan_fixture_with(
        "cargo-bin-exe",
        &[
            ("Cargo.toml", "[workspace]\nmembers = [\"crates/helper\"]\n"),
            ("crates/helper/Cargo.toml", "[package]\nname = \"helper\"\n"),
            ("crates/helper/src/lib.rs", "pub fn x() {}\n"),
            ("crates/helper/src/bin/fixture_main.rs", "fn main() {}\n"),
            ("crates/helper/tests/spawn.rs", test),
        ],
        &serde_json::json!({"config_files": ["Cargo.toml", "crates/helper/Cargo.toml"]}),
    );
    let launches: Vec<String> = edges_of(&contributions, "launches")
        .into_iter()
        .map(|(source, target)| format!("{source} -> {target}"))
        .collect();
    assert!(
        launches.iter().any(|edge| edge.ends_with(
            "runs_the_fixture -> rust:launched_file:crates/helper/src/bin/fixture_main.rs"
        )),
        "{launches:?}"
    );
}
