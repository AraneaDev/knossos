//! The signature of a function or method as its source writes it:
//! parameter names, their types, and the declared return type.

use serde_json::{json, Map, Value};
use syn::spanned::Spanned;

/// Parameters a signature lists at most; the rest are cut and it says so.
pub(super) const MAX_PARAMS: usize = 12;

/// Characters of a type's text a signature keeps at most.
pub(super) const MAX_TYPE_LENGTH: usize = 80;

/// The `signature` attribute of a function or method.
///
/// Types are the text written, on one line. The receiver (`self`, `&self`,
/// `self: Arc<Self>`) is what the call is made on, not something a caller
/// passes, and is left out. A parameter bound by a pattern rather than a
/// name is listed as `_`, which is how a caller sees it. A signature that
/// lost a parameter or part of a type says `truncated`.
pub(super) fn signature_of(signature: &syn::Signature) -> Value {
    let mut truncated = false;
    let typed: Vec<&syn::PatType> = signature
        .inputs
        .iter()
        .filter_map(|input| match input {
            syn::FnArg::Typed(typed) => Some(typed),
            syn::FnArg::Receiver(_) => None,
        })
        .collect();
    if typed.len() > MAX_PARAMS {
        truncated = true;
    }
    let params: Vec<Value> = typed
        .iter()
        .take(MAX_PARAMS)
        .map(|typed| {
            let name = match typed.pat.as_ref() {
                syn::Pat::Ident(binding) => binding.ident.to_string(),
                _ => "_".to_owned(),
            };
            json!({"name": name, "type": type_text(typed.ty.as_ref(), &mut truncated)})
        })
        .collect();
    let mut out = Map::new();
    out.insert("params".to_owned(), Value::Array(params));
    if let syn::ReturnType::Type(_, returned) = &signature.output {
        out.insert(
            "returns".to_owned(),
            Value::String(type_text(returned.as_ref(), &mut truncated)),
        );
    }
    if truncated {
        out.insert("truncated".to_owned(), Value::Bool(true));
    }
    Value::Object(out)
}

/// A type as its source writes it, on one line and within the length kept;
/// sets `truncated` when it was cut.
///
/// The text comes from the type's span, which every file parsed from its
/// source has; a type with no text behind its span reads `_`. Control
/// characters become a space: the text is shown to an agent as one line.
fn type_text(ty: &syn::Type, truncated: &mut bool) -> String {
    let written = ty.span().source_text().unwrap_or_else(|| "_".to_owned());
    let line = written
        .split(|c: char| c.is_whitespace() || c.is_control())
        .filter(|part| !part.is_empty())
        .collect::<Vec<_>>()
        .join(" ");
    if line.chars().count() <= MAX_TYPE_LENGTH {
        return line;
    }
    *truncated = true;
    let mut cut: String = line.chars().take(MAX_TYPE_LENGTH - 1).collect();
    cut.push('…');
    cut
}

#[cfg(test)]
mod tests {
    use super::*;

    fn of(source: &str) -> Value {
        let item: syn::ItemFn = syn::parse_str(source).unwrap();
        signature_of(&item.sig)
    }

    #[test]
    fn lists_parameters_with_their_types_and_the_return() {
        assert_eq!(
            json!({
                "params": [
                    {"name": "root", "type": "&Path"},
                    {"name": "limit", "type": "Option<usize>"},
                ],
                "returns": "Result<Vec<String>, Error>",
            }),
            of("fn scan(root: &Path, mut limit: Option<usize>) -> Result<Vec<String>, Error> {}"),
        );
        assert_eq!(json!({"params": []}), of("fn nothing() {}"));
    }

    #[test]
    fn names_a_pattern_parameter_as_a_caller_sees_it() {
        assert_eq!(
            json!({"params": [{"name": "_", "type": "(u8, u8)"}, {"name": "_", "type": "Unused"}]}),
            of("fn pair((a, b): (u8, u8), _: Unused) {}"),
        );
    }

    #[test]
    fn leaves_the_receiver_out() {
        let item: syn::ImplItemFn =
            syn::parse_str("fn get(&self, key: &str) -> Option<&Value> {}").unwrap();
        assert_eq!(
            json!({"params": [{"name": "key", "type": "&str"}], "returns": "Option<&Value>"}),
            signature_of(&item.sig),
        );
        let item: syn::ImplItemFn = syn::parse_str("fn take(self: Arc<Self>) {}").unwrap();
        assert_eq!(json!({"params": []}), signature_of(&item.sig));
    }

    #[test]
    fn puts_a_type_on_one_line() {
        assert_eq!(
            json!({"params": [{"name": "f", "type": "impl Fn( u8, ) -> u8"}]}),
            of("fn apply(f: impl Fn(\n    u8,\n) -> u8) {}"),
        );
    }

    #[test]
    fn cuts_a_long_type_and_a_long_list_and_says_so() {
        let long = format!("HashMap<String, ({})>", vec!["usize"; 20].join(", "));
        let cut = of(&format!("fn wide(a: {long}, b: u8) -> {long} {{}}"));
        let first = cut["params"][0]["type"].as_str().unwrap();
        assert_eq!(MAX_TYPE_LENGTH, first.chars().count());
        assert!(first.starts_with("HashMap<String, (usize, usize"));
        assert!(first.ends_with('…'));
        assert_eq!("u8", cut["params"][1]["type"]);
        assert_eq!(
            MAX_TYPE_LENGTH,
            cut["returns"].as_str().unwrap().chars().count()
        );
        assert_eq!(true, cut["truncated"]);

        let names: Vec<String> = (0..MAX_PARAMS + 2).map(|i| format!("p{i}: u8")).collect();
        let many = of(&format!("fn many({}) {{}}", names.join(", ")));
        assert_eq!(MAX_PARAMS, many["params"].as_array().unwrap().len());
        assert_eq!(true, many["truncated"]);
        let exact = of(&format!(
            "fn exact({}) {{}}",
            names[..MAX_PARAMS].join(", ")
        ));
        assert!(exact.get("truncated").is_none());
    }
}
