//! galvanic-mock (Momus's `scanGalvanic`): inside a `#[use_mocks]`
//! function, `let mock = new_mock!(Trait);` makes a mock, and
//! `given! { <mock as Trait>::m(…) then_return v always; }` configures the
//! member `m`. `expect_interactions! { <mock as Trait>::m(…) times 1; }`
//! asserts how often it is called.
//!
//! Both are macros the attribute rewrites, read here only as tokens: each
//! clause gives the member and, for `then_return`, a pin, but the macro
//! stands where an item may and so leaves every value of the function
//! unknown (Decision 9).

use proc_macro2::{Delimiter, TokenTree};

use super::{Framework, FrameworkContext, Made};
use crate::resolve::ident_name;

/// The pattern of a galvanic mock (Decision 2).
const PATTERN: &str = "galvanic_mock::mockable";

/// The mock `new_mock!(Trait)` (also `new_mock!(Trait<…>)` and
/// `new_mock!(Trait for MyMock)`), written in `container`, makes.
pub(in crate::test_facts) fn constructed(
    context: &FrameworkContext<'_>,
    container: &str,
    mac: &syn::Macro,
) -> Option<Made> {
    if !mac.path.is_ident("new_mock") {
        return None;
    }
    let head: proc_macro2::TokenStream = mac
        .tokens
        .clone()
        .into_iter()
        .take_while(|tree| match tree {
            TokenTree::Punct(punct) => !matches!(punct.as_char(), '<' | '#'),
            TokenTree::Ident(ident) => ident != "for",
            _ => true,
        })
        .collect();
    let path = syn::parse2::<syn::Path>(head).ok()?;
    let target = context.declared_trait(container, &path);
    Some(Made::instance(
        Framework::Galvanic,
        super::written(&path),
        PATTERN,
        target,
    ))
}

/// One clause of a `given!` or `expect_interactions!` block.
#[derive(Debug, PartialEq, Eq)]
pub(in crate::test_facts) struct Clause {
    /// The handle it names (`mock` of `<mock as Trait>`).
    pub(in crate::test_facts) handle: String,
    /// The member it names.
    pub(in crate::test_facts) member: String,
    /// The member's line.
    pub(in crate::test_facts) line: usize,
    /// The line of its `then_return`, when it pins.
    pub(in crate::test_facts) pin: Option<usize>,
    /// Whether it asserts how often the member is called.
    pub(in crate::test_facts) times: bool,
}

/// The clauses of a `given! { … }` or `expect_interactions! { … }` block;
/// `None` for any other macro.
pub(in crate::test_facts) fn clauses(mac: &syn::Macro) -> Option<Vec<Clause>> {
    let given = if mac.path.is_ident("given") {
        true
    } else if mac.path.is_ident("expect_interactions") {
        false
    } else {
        return None;
    };
    let trees: Vec<TokenTree> = mac.tokens.clone().into_iter().collect();
    Some(
        trees
            .split(|tree| matches!(tree, TokenTree::Punct(punct) if punct.as_char() == ';'))
            .filter_map(|clause| self::clause(clause, given))
            .collect(),
    )
}

/// One clause: `<h as …>::m(…) rest`.
fn clause(trees: &[TokenTree], given: bool) -> Option<Clause> {
    let [TokenTree::Punct(open), TokenTree::Ident(handle), TokenTree::Ident(keyword), rest @ ..] =
        trees
    else {
        return None;
    };
    if open.as_char() != '<' || keyword != "as" {
        return None;
    }
    // Past the qualified type's closing `>`, at its depth.
    let mut depth = 1usize;
    let mut at = 0;
    while depth > 0 {
        if let TokenTree::Punct(punct) = rest.get(at)? {
            match punct.as_char() {
                '<' => depth += 1,
                '>' => depth -= 1,
                _ => {}
            }
        }
        at += 1;
    }
    let [TokenTree::Punct(first), TokenTree::Punct(second), TokenTree::Ident(member), TokenTree::Group(arguments), tail @ ..] =
        &rest[at..]
    else {
        return None;
    };
    if first.as_char() != ':'
        || second.as_char() != ':'
        || arguments.delimiter() != Delimiter::Parenthesis
    {
        return None;
    }
    let pin = tail.iter().find_map(|tree| match tree {
        TokenTree::Ident(verb) if given && verb == "then_return" => Some(verb.span().start().line),
        _ => None,
    });
    Some(Clause {
        handle: ident_name(handle),
        member: ident_name(member),
        line: member.span().start().line,
        pin,
        times: !given,
    })
}
