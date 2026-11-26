//! Hand-written fakes (Decision 4): a struct or enum declared in test code
//! with an `impl T for Fake` block, where the walk's own `implements` edge
//! (R5-4) names a trait of the project. Each such block is one double, named
//! at its `impl` token, whose members are the block's functions.
//!
//! A member pins the literal its body returns only when nothing else may
//! answer the call or change the body out of sight:
//!
//! - its body is one literal, or `return <literal>;`, and it has no
//!   attribute; the block has none either, and the fake only inert ones
//!   (docs, lints, `repr` and the standard derives);
//! - the fake is not `pub` in any form, is declared once in its module, and
//!   that module has no child module: so every impl of it is in this file;
//! - no other impl in the file, at any depth, declares the name for it,
//!   inherent or trait (an inherent method wins the call), nor a blanket
//!   or unnamed impl;
//! - no macro in the file may declare an impl: one where an item stands, a
//!   statement macro other than a standard one, or any naming the fake.
//!
//! Otherwise the member gives identity only.

use std::collections::BTreeSet;

use syn::spanned::Spanned;
use syn::visit::Visit;

use super::doubles::{DoubleFact, Member, Pin};
use super::input::TestInput;
use super::literals::literal_text;
use super::macros::Frozen;
use super::rivals::Rivals;
use super::syntax::identifiers;
use crate::resolve::ident_name;

/// The attributes a fake may carry and keep its pins.
const INERT: [&str; 10] = [
    "doc",
    "allow",
    "warn",
    "deny",
    "forbid",
    "expect",
    "must_use",
    "repr",
    "non_exhaustive",
    "derive",
];

/// The derives whose impls declare no name a fake's trait could share.
const STANDARD_DERIVES: [&str; 9] = [
    "Clone",
    "Copy",
    "Debug",
    "Default",
    "PartialEq",
    "Eq",
    "PartialOrd",
    "Ord",
    "Hash",
];

/// One `impl T for Fake` block: a double.
#[derive(Debug)]
pub(super) struct FakeImpl {
    /// Its members, each with the literal it pins, if any.
    pub(super) members: Vec<(String, Option<String>)>,
    /// The double.
    pub(super) fact: DoubleFact,
}

/// One fake type and its trait impls.
#[derive(Debug)]
pub(super) struct Fake {
    /// Its name.
    pub(super) name: String,
    /// Its canonical path.
    pub(super) canonical: String,
    /// The module it is declared in.
    pub(super) container: String,
    /// Whether it is a unit struct, which its own name makes a value of.
    pub(super) unit: bool,
    /// The associated functions that make one (`new`, `default`).
    pub(super) constructors: BTreeSet<&'static str>,
    /// Its doubles.
    pub(super) impls: Vec<FakeImpl>,
}

/// The fakes the file declares.
pub(super) fn fakes(input: &TestInput<'_>, frozen: &Frozen<'_>) -> Vec<Fake> {
    let mut shapes = Shapes {
        impls: Vec::new(),
        opens_all: false,
        named: BTreeSet::new(),
        frozen,
        rivals: Rivals::of(input),
    };
    shapes.visit_file(input.file);
    let test_file = super::production::under_cargo_tests(input.relative, input.layout);
    let mut found = Vec::new();
    modules(
        input,
        &shapes,
        input.module,
        &input.file.items,
        test_file,
        &mut found,
    );
    found
}

/// One impl block of the file, at any depth.
struct ImplShape {
    /// Where its `impl` token starts.
    at: (usize, usize),
    /// The name of its self type; `None` when it may be any type (a
    /// generic parameter, or no plain path).
    owner: Option<String>,
    /// The last segment of its trait, for a trait impl.
    trait_name: Option<String>,
    /// The functions it declares, each with the return type's last name.
    functions: Vec<(String, Option<String>)>,
}

/// What the whole file holds that may add a name to a fake.
struct Shapes<'f> {
    /// Every impl block.
    impls: Vec<ImplShape>,
    /// Whether a macro may declare any impl.
    opens_all: bool,
    /// Every name a macro is handed.
    named: BTreeSet<String>,
    /// Which macros are the standard ones.
    frozen: &'f Frozen<'f>,
    /// What else may answer a call on a fake.
    rivals: Rivals,
}

impl Visit<'_> for Shapes<'_> {
    fn visit_item_impl(&mut self, node: &syn::ItemImpl) {
        let generics: BTreeSet<String> = node
            .generics
            .type_params()
            .map(|param| ident_name(&param.ident))
            .collect();
        let mut ty = node.self_ty.as_ref();
        while let syn::Type::Reference(reference) = ty {
            ty = reference.elem.as_ref();
        }
        let owner = match ty {
            syn::Type::Path(path) if path.qself.is_none() => path
                .path
                .segments
                .last()
                .map(|last| ident_name(&last.ident))
                .filter(|name| !generics.contains(name)),
            _ => None,
        };
        self.impls.push(ImplShape {
            at: start(node.impl_token.span),
            owner,
            trait_name: node
                .trait_
                .as_ref()
                .and_then(|(path, _)| path.segments.last())
                .map(|last| ident_name(&last.ident)),
            functions: node
                .items
                .iter()
                .filter_map(|member| match member {
                    syn::ImplItem::Fn(function) => Some((
                        ident_name(&function.sig.ident),
                        returned_name(&function.sig.output),
                    )),
                    _ => None,
                })
                .collect(),
        });
        syn::visit::visit_item_impl(self, node);
    }

    fn visit_item_macro(&mut self, node: &syn::ItemMacro) {
        if node.mac.path.is_ident("macro_rules") {
            identifiers(node.mac.tokens.clone(), &mut self.named);
        } else {
            self.opens_all = true;
        }
    }

    fn visit_impl_item_macro(&mut self, _: &syn::ImplItemMacro) {
        self.opens_all = true;
    }

    fn visit_stmt_macro(&mut self, node: &syn::StmtMacro) {
        self.opens_all |= self.frozen.declares_items(&node.mac);
        syn::visit::visit_stmt_macro(self, node);
    }

    fn visit_macro(&mut self, node: &syn::Macro) {
        // A standard macro expands to no item; any other may, of a name it
        // is handed.
        if self.frozen.declares_items(node) {
            identifiers(node.tokens.clone(), &mut self.named);
            return;
        }
        // Its arguments are expressions, whose blocks may hold an impl.
        for argument in super::assertions::arguments(node).unwrap_or_default() {
            self.visit_expr(&argument);
        }
    }
}

/// The last name of a function's return type: `Self` or a type's.
fn returned_name(output: &syn::ReturnType) -> Option<String> {
    let syn::ReturnType::Type(_, ty) = output else {
        return None;
    };
    let syn::Type::Path(path) = ty.as_ref() else {
        return None;
    };
    path.path.get_ident().map(ident_name)
}

/// Where a span starts (line, column).
fn start(span: proc_macro2::Span) -> (usize, usize) {
    let at = span.start();
    (at.line, at.column)
}

/// Add the fakes of `items`, in `container`, to `found`; `test` when the
/// file is test code by its place.
fn modules(
    input: &TestInput<'_>,
    shapes: &Shapes<'_>,
    container: &str,
    items: &[syn::Item],
    test: bool,
    found: &mut Vec<Fake>,
) {
    let has_child = items.iter().any(|item| matches!(item, syn::Item::Mod(_)));
    for item in items {
        let (ident, vis, attrs, unit) = match item {
            syn::Item::Mod(module) => {
                if let Some((_, inner)) = &module.content {
                    let nested = format!("{container}::{}", ident_name(&module.ident));
                    modules(input, shapes, &nested, inner, test, found);
                }
                continue;
            }
            syn::Item::Struct(node) => (
                &node.ident,
                &node.vis,
                &node.attrs,
                matches!(node.fields, syn::Fields::Unit),
            ),
            syn::Item::Enum(node) => (&node.ident, &node.vis, &node.attrs, false),
            _ => continue,
        };
        let name = ident_name(ident);
        let canonical = format!("{container}::{name}");
        if !test && !declared_as_test(input, &canonical) {
            continue;
        }
        let declarations = items.iter().filter(|other| declares(other, &name)).count();
        let pinnable = matches!(vis, syn::Visibility::Inherited)
            && !has_child
            && declarations == 1
            && attrs.iter().all(inert)
            && !shapes.opens_all
            && !shapes.named.contains(&name);
        let impls: Vec<FakeImpl> = items
            .iter()
            .filter_map(|item| match item {
                syn::Item::Impl(block) => {
                    fake_impl(input, shapes, container, &canonical, &name, block, pinnable)
                }
                _ => None,
            })
            .collect();
        if impls.is_empty() {
            continue;
        }
        found.push(Fake {
            constructors: constructors(shapes, &name, attrs),
            name,
            canonical,
            container: container.to_owned(),
            unit,
            impls,
        });
    }
}

/// Whether `item` declares a type or value `name`.
fn declares(item: &syn::Item, name: &str) -> bool {
    match item {
        syn::Item::Struct(node) => node.ident == name,
        syn::Item::Enum(node) => node.ident == name,
        syn::Item::Union(node) => node.ident == name,
        syn::Item::Type(node) => node.ident == name,
        _ => false,
    }
}

/// Whether the walk marked the declaration `canonical` as test code.
fn declared_as_test(input: &TestInput<'_>, canonical: &str) -> bool {
    input.facts.nodes().iter().any(|node| {
        node.canonical_name == canonical
            && node.attributes.get("test") == Some(&serde_json::Value::Bool(true))
    })
}

/// Whether an attribute leaves a fake's pins: an inert one, and only the
/// standard derives.
fn inert(attr: &syn::Attribute) -> bool {
    if !INERT.iter().any(|name| attr.path().is_ident(name)) {
        return false;
    }
    if !attr.path().is_ident("derive") {
        return true;
    }
    attr.parse_args_with(syn::punctuated::Punctuated::<syn::Path, syn::Token![,]>::parse_terminated)
        .is_ok_and(|derives| {
            derives
                .iter()
                .all(|derive| STANDARD_DERIVES.iter().any(|name| derive.is_ident(name)))
        })
}

/// The associated functions that make a value of the fake `name` (its
/// handles): an inherent `new` or `default` returning `Self` or the fake,
/// and `default` of a derived or implemented `Default`.
fn constructors(
    shapes: &Shapes<'_>,
    name: &str,
    attrs: &[syn::Attribute],
) -> BTreeSet<&'static str> {
    let mut found = BTreeSet::new();
    let own = |returned: &Option<String>| {
        returned
            .as_deref()
            .is_some_and(|returned| returned == "Self" || returned == name)
    };
    for shape in shapes
        .impls
        .iter()
        .filter(|shape| shape.owner.as_deref() == Some(name))
    {
        if shape.trait_name.as_deref() == Some("Default") {
            found.insert("default");
        }
        if shape.trait_name.is_some() {
            continue;
        }
        for (function, returned) in &shape.functions {
            match function.as_str() {
                "new" if own(returned) => {
                    found.insert("new");
                }
                "default" if own(returned) => {
                    found.insert("default");
                }
                _ => {}
            }
        }
    }
    let derives_default = attrs
        .iter()
        .filter(|attr| attr.path().is_ident("derive"))
        .any(|attr| {
            attr.parse_args_with(
                syn::punctuated::Punctuated::<syn::Path, syn::Token![,]>::parse_terminated,
            )
            .is_ok_and(|derives| derives.iter().any(|derive| derive.is_ident("Default")))
        });
    if derives_default {
        found.insert("default");
    }
    found
}

/// The double of the block `block`, in `container`, when it implements a
/// project trait for the fake `canonical`.
fn fake_impl(
    input: &TestInput<'_>,
    shapes: &Shapes<'_>,
    container: &str,
    canonical: &str,
    name: &str,
    block: &syn::ItemImpl,
    pinnable: bool,
) -> Option<FakeImpl> {
    block.trait_.as_ref()?;
    let syn::Type::Path(ty) = block.self_ty.as_ref() else {
        return None;
    };
    if ty.qself.is_some()
        || !ty
            .path
            .segments
            .last()
            .is_some_and(|last| last.ident == name)
    {
        return None;
    }
    let target = implemented(input, canonical, block)?;
    let at = block.impl_token.span.start();
    let pins = pinnable && block.attrs.is_empty();
    let here = start(block.impl_token.span);
    let mut members = Vec::new();
    let mut facts = Vec::new();
    for member in &block.items {
        let syn::ImplItem::Fn(function) = member else {
            continue;
        };
        let member_name = ident_name(&function.sig.ident);
        let shared = shapes.impls.iter().any(|shape| {
            shape.at != here
                && shape
                    .owner
                    .as_deref()
                    .is_none_or(|owner| owner == name || shapes.rivals.may_be_the_fake(owner))
                && shape
                    .functions
                    .iter()
                    .any(|(other, _)| *other == member_name)
        });
        let rival = shapes.rivals.rival(&target, &member_name);
        let literal = (pins && function.attrs.is_empty() && !shared && !rival)
            .then(|| body_literal(&function.block))
            .flatten();
        let line = function.sig.fn_token.span.start().line;
        facts.push(Member {
            name: member_name.clone(),
            line,
            api: "impl",
            returns: literal
                .iter()
                .map(|_| Pin {
                    api: "impl".to_owned(),
                    line,
                    once: false,
                })
                .collect(),
        });
        members.push((member_name, literal));
    }
    Some(FakeImpl {
        members,
        fact: DoubleFact {
            canonical: format!("{}#double:{}:{}", input.relative, at.line, at.column + 1),
            mock: canonical.to_owned(),
            span: block.span(),
            framework: "hand-written",
            pattern: "impl",
            target: Some(("interface", target)),
            target_kind: "class",
            members: facts,
            invocations: 0,
            test_case: None,
            module: container.to_owned(),
        },
    })
}

/// The project trait the walk's `implements` edge from `canonical` to the
/// block names.
fn implemented(input: &TestInput<'_>, canonical: &str, block: &syn::ItemImpl) -> Option<String> {
    let lines = [
        block.impl_token.span.start().line,
        block.span().start().line,
    ];
    let source = crate::facts::reference("class", canonical);
    let edge = input
        .facts
        .edges_from(&source)
        .find(|edge| edge.kind == "implements" && lines.contains(&edge.evidence.start_line))?;
    let trait_path = edge.target.strip_prefix("rust:interface:")?;
    let declared = input
        .facts
        .nodes()
        .iter()
        .any(|node| node.local_id == edge.target)
        || input.declarations.declares_quietly(trait_path);
    declared.then(|| trait_path.to_owned())
}

/// The literal a body is: one literal expression, or `return <literal>;`.
fn body_literal(block: &syn::Block) -> Option<String> {
    let [statement] = block.stmts.as_slice() else {
        return None;
    };
    let expr = match statement {
        syn::Stmt::Expr(syn::Expr::Return(returned), _) => returned.expr.as_deref()?,
        syn::Stmt::Expr(expr, None) => expr,
        _ => return None,
    };
    literal_text(expr)
}
