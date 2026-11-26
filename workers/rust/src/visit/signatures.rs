//! The signature of a function or method as its source writes it:
//! parameter names, their types, the declared return type, the receiver
//! and the type parameters; and the facts a mock of it needs that sit on
//! the node beside it: its written visibility, `async` and `unsafe`.

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
/// passes: it is left out of `params` and given as `receiver`, absent for
/// an associated function. A parameter bound by a pattern rather than a
/// name is listed as `_`, which is how a caller sees it. The type
/// parameters are `type_params`, each as written (`T: Clone`, `'a`), and a
/// where clause is `where`, each only when there is one. A signature that
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
    if let Some(syn::FnArg::Receiver(receiver)) = signature.inputs.first() {
        out.insert(
            "receiver".to_owned(),
            Value::String(type_text(receiver, &mut truncated)),
        );
    }
    for (key, value) in generics_of(&signature.generics, &mut truncated) {
        out.insert(key.to_owned(), value);
    }
    if truncated {
        out.insert("truncated".to_owned(), Value::Bool(true));
    }
    Value::Object(out)
}

/// The type parameters and the where clause of an item or a signature, as
/// written: `type_params` (one text per parameter, lifetimes and consts
/// included) and `where`, each only when there is one. Sets `truncated`
/// when a text was cut.
pub(super) fn generics_of(
    generics: &syn::Generics,
    truncated: &mut bool,
) -> Vec<(&'static str, Value)> {
    let mut out = Vec::new();
    if !generics.params.is_empty() {
        let params = generics
            .params
            .iter()
            .map(|param| Value::String(type_text(param, truncated)))
            .collect();
        out.push(("type_params", Value::Array(params)));
    }
    if let Some(clause) = &generics.where_clause {
        out.push(("where", Value::String(type_text(clause, truncated))));
    }
    out
}

/// The facts of a function or method node that are not its signature:
/// `visibility`, `async` and `unsafe`, each only when it says something.
///
/// `visibility` is `private` for an item without `pub`, and the written
/// restriction (`pub(crate)`, `pub(super)`, `pub(in …)`) for a restricted
/// one; plain `pub` sends nothing, so an absent visibility means public, as
/// it does from every worker. `None` is a trait's item, or a trait impl's,
/// which is as public as the trait and sends none either.
pub(super) fn node_facts_of(
    signature: &syn::Signature,
    visibility: Option<&syn::Visibility>,
) -> Vec<(&'static str, Value)> {
    let mut out = Vec::new();
    match visibility {
        Some(syn::Visibility::Inherited) => {
            out.push(("visibility", Value::String("private".to_owned())))
        }
        Some(restricted @ syn::Visibility::Restricted(_)) => {
            out.push((
                "visibility",
                Value::String(type_text(restricted, &mut false)),
            ));
        }
        Some(syn::Visibility::Public(_)) | None => {}
    }
    if signature.asyncness.is_some() {
        out.push(("async", Value::Bool(true)));
    }
    if matches!(signature.safety, syn::Safety::Unsafe(_)) {
        out.push(("unsafe", Value::Bool(true)));
    }
    out
}

impl super::Walk<'_> {
    /// Record on the function or method node `canonical` its signature and
    /// the facts of [`node_facts_of`].
    pub(super) fn record_signature(
        &mut self,
        canonical: &str,
        signature: &syn::Signature,
        visibility: Option<&syn::Visibility>,
    ) {
        self.facts
            .node_attribute(canonical, "signature", signature_of(signature));
        for (key, value) in node_facts_of(signature, visibility) {
            self.facts.node_attribute(canonical, key, value);
        }
    }

    /// Record on a trait node what its `impl` must give besides methods:
    /// `associated_types`, each name with its bounds as written
    /// (`Item: Clone`), and `associated_consts`, each `{name, type}`, each
    /// list only when there is one.
    pub(super) fn record_associated(&mut self, canonical: &str, items: &[syn::TraitItem]) {
        let mut types = Vec::new();
        let mut consts = Vec::new();
        for item in items {
            match item {
                syn::TraitItem::Type(declared) if declared.bounds.is_empty() => {
                    types.push(Value::String(declared.ident.to_string()));
                }
                syn::TraitItem::Type(declared) => types.push(Value::String(format!(
                    "{}: {}",
                    declared.ident,
                    type_text(&declared.bounds, &mut false)
                ))),
                syn::TraitItem::Const(declared) => consts.push(json!({
                    "name": declared.ident.to_string(),
                    "type": type_text(&declared.ty, &mut false),
                })),
                _ => {}
            }
        }
        if !types.is_empty() {
            self.facts
                .node_attribute(canonical, "associated_types", Value::Array(types));
        }
        if !consts.is_empty() {
            self.facts
                .node_attribute(canonical, "associated_consts", Value::Array(consts));
        }
    }

    /// Record on a trait impl's method `canonical` the trait it implements,
    /// as the `impl` writes it (`Repo<String>`), so a mock of the type can
    /// give that impl of its own.
    pub(super) fn record_impl_trait(&mut self, canonical: &str, path: &syn::Path) {
        self.facts.node_attribute(
            canonical,
            "impl_trait",
            Value::String(type_text(path, &mut false)),
        );
    }

    /// Record on the trait, struct or enum node `canonical` its type
    /// parameters and where clause, as [`generics_of`] gives them. A text
    /// cut to the length kept ends in `…`, which no written one does.
    pub(super) fn record_generics(&mut self, canonical: &str, generics: &syn::Generics) {
        for (key, value) in generics_of(generics, &mut false) {
            self.facts.node_attribute(canonical, key, value);
        }
    }
}

/// Source as it is written, on one line and within the length kept; sets
/// `truncated` when it was cut.
///
/// The text comes from the span, which every file parsed from its source
/// has; one with no text behind its span reads `_`. Control characters
/// become a space: the text is shown to an agent as one line.
fn type_text(ty: &impl Spanned, truncated: &mut bool) -> String {
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
    fn gives_the_receiver_apart_from_the_parameters() {
        let item: syn::ImplItemFn =
            syn::parse_str("fn get(&self, key: &str) -> Option<&Value> {}").unwrap();
        assert_eq!(
            json!({"params": [{"name": "key", "type": "&str"}], "returns": "Option<&Value>", "receiver": "&self"}),
            signature_of(&item.sig),
        );
        let item: syn::ImplItemFn = syn::parse_str("fn take(self: Arc<Self>) {}").unwrap();
        assert_eq!(
            json!({"params": [], "receiver": "self: Arc<Self>"}),
            signature_of(&item.sig)
        );
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
