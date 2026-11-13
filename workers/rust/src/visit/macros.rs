//! Macro bodies walked as the expressions they are made of, as far as they
//! parse as Rust.

use syn::visit::Visit;

use super::state::Calls;

impl Calls<'_, '_> {
    /// Walk a macro body as expressions, as far as it is made of them.
    ///
    /// Comma-separated expressions first (`format!`, `assert_eq!`). A body
    /// that is not (`json!({ "k": f(x) })`) is split at its top-level commas,
    /// each piece loses a leading `"key":` or `key:`, and what remains is
    /// parsed on its own; a piece that still does not parse is searched
    /// through its bracketed groups the same way. Nothing is guessed: only
    /// token runs that parse as Rust expressions are walked. The groups still
    /// to search are kept in a worklist, so the walk is one loop rather than
    /// two functions calling each other.
    ///
    /// Returns whether every token run was walked as expressions; when one
    /// was not, the caller may search the body's tokens instead.
    pub(super) fn visit_macro_tokens(&mut self, tokens: proc_macro2::TokenStream) -> bool {
        use syn::parse::Parser;
        let parser = syn::punctuated::Punctuated::<syn::Expr, syn::Token![,]>::parse_terminated;
        let mut pending = vec![tokens];
        let mut whole = true;
        while let Some(tokens) = pending.pop() {
            if let Ok(arguments) = parser.parse2(tokens.clone()) {
                for argument in &arguments {
                    self.visit_expr(argument);
                }
                continue;
            }
            for piece in top_level_pieces(tokens) {
                match macro_piece(piece) {
                    Ok(expression) => self.visit_expr(&expression),
                    Err(groups) => {
                        whole = false;
                        pending.extend(groups);
                    }
                }
            }
        }
        whole
    }

    /// Walk `matches!(value, pattern if guard)`'s arguments as the
    /// expression, pattern and guard they are, returning whether they
    /// parsed so.
    pub(super) fn visit_matches(&mut self, tokens: proc_macro2::TokenStream) -> bool {
        use syn::parse::Parser;
        let parser = |input: syn::parse::ParseStream<'_>| {
            let value: syn::Expr = input.parse()?;
            input.parse::<syn::Token![,]>()?;
            let pattern = syn::Pat::parse_multi_with_leading_vert(input)?;
            let guard = if input.peek(syn::Token![if]) {
                input.parse::<syn::Token![if]>()?;
                Some(input.parse::<syn::Expr>()?)
            } else {
                None
            };
            if input.peek(syn::Token![,]) {
                input.parse::<syn::Token![,]>()?;
            }
            Ok((value, pattern, guard))
        };
        let Ok((value, pattern, guard)) = parser.parse2(tokens) else {
            return false;
        };
        self.visit_expr(&value);
        let saved = self.receivers.clone();
        self.bind_pattern(&pattern);
        self.visit_pat(&pattern);
        if let Some(guard) = &guard {
            self.visit_expr(guard);
        }
        self.receivers = saved;
        true
    }

    /// Record a `possible` call to every path `tokens` write as one: a
    /// path of identifiers followed by a parenthesised group, at any depth,
    /// that is not a declaration (`fn f(`), a method call (`.f(`), a macro
    /// invocation (`f!(`), a macro metavariable (`$f(`) or a keyword
    /// (`if (`). `$crate::` is this crate. Read from tokens no parse turned
    /// into code, so none of it is certain.
    pub(super) fn visit_token_calls(&mut self, tokens: proc_macro2::TokenStream) {
        let mut calls = Vec::new();
        token_calls(tokens, &mut calls);
        self.walk.facts.enter_possible_scope();
        for (path, span) in calls {
            self.visit_call(&path, span);
        }
        self.walk.facts.exit_possible_scope();
    }
}

/// The paths `tokens` write as calls, with where each is, for
/// [`Calls::visit_token_calls`].
pub(super) fn token_calls(
    tokens: proc_macro2::TokenStream,
    out: &mut Vec<(syn::Path, proc_macro2::Span)>,
) {
    use proc_macro2::TokenTree;
    /// Words a path written as a call never starts with.
    const NOT_CALLS: [&str; 22] = [
        "if", "while", "match", "return", "for", "in", "fn", "let", "loop", "move", "as", "else",
        "unsafe", "async", "await", "where", "impl", "dyn", "ref", "mut", "break", "yield",
    ];
    let tokens: Vec<TokenTree> = tokens.into_iter().collect();
    let mut index = 0;
    while index < tokens.len() {
        if let TokenTree::Group(group) = &tokens[index] {
            token_calls(group.stream(), out);
            index += 1;
            continue;
        }
        let TokenTree::Ident(first) = &tokens[index] else {
            index += 1;
            continue;
        };
        let before = index.checked_sub(1).map(|at| &tokens[at]);
        let dollar = matches!(before, Some(TokenTree::Punct(p)) if p.as_char() == '$');
        let excluded = match before {
            Some(TokenTree::Punct(p)) => p.as_char() == '.' || (dollar && *first != "crate"),
            Some(TokenTree::Ident(word)) => *word == "fn",
            _ => false,
        };
        let mut segments = vec![first.clone()];
        let mut end = index + 1;
        while end + 2 < tokens.len() {
            match (&tokens[end], &tokens[end + 1], &tokens[end + 2]) {
                (TokenTree::Punct(a), TokenTree::Punct(b), TokenTree::Ident(next))
                    if a.as_char() == ':' && b.as_char() == ':' =>
                {
                    segments.push(next.clone());
                    end += 3;
                }
                _ => break,
            }
        }
        let called = matches!(
            tokens.get(end),
            Some(TokenTree::Group(group)) if group.delimiter() == proc_macro2::Delimiter::Parenthesis
        );
        let keyword = segments.len() == 1 && NOT_CALLS.contains(&first.to_string().as_str());
        if called && !excluded && !keyword {
            let mut path = syn::Path {
                leading_colon: None,
                segments: syn::punctuated::Punctuated::new(),
            };
            for segment in &segments {
                path.segments.push(syn::PathSegment::from(segment.clone()));
            }
            out.push((path, first.span()));
        }
        index = end;
    }
}

/// A macro body split at its top-level commas.
pub(super) fn top_level_pieces(
    tokens: proc_macro2::TokenStream,
) -> Vec<Vec<proc_macro2::TokenTree>> {
    let mut pieces = vec![Vec::new()];
    for token in tokens {
        if matches!(&token, proc_macro2::TokenTree::Punct(punct) if punct.as_char() == ',') {
            pieces.push(Vec::new());
        } else if let Some(piece) = pieces.last_mut() {
            piece.push(token);
        }
    }
    pieces
}

/// One comma-separated piece of a macro body, without a leading `"key":` or
/// `key:`: the expression it parses as, or else the bracketed groups inside
/// it, which may hold expressions of their own. See [`Calls::visit_macro_tokens`].
pub(super) fn macro_piece(
    mut piece: Vec<proc_macro2::TokenTree>,
) -> Result<syn::Expr, Vec<proc_macro2::TokenStream>> {
    let keyed = matches!(
        piece.as_slice(),
        [
            proc_macro2::TokenTree::Literal(_) | proc_macro2::TokenTree::Ident(_),
            proc_macro2::TokenTree::Punct(colon),
            next,
            ..
        ] if colon.as_char() == ':'
            && colon.spacing() == proc_macro2::Spacing::Alone
            && !matches!(next, proc_macro2::TokenTree::Punct(p) if p.as_char() == ':')
    );
    if keyed {
        piece.drain(..2);
    }
    if piece.is_empty() {
        return Err(Vec::new());
    }
    let stream: proc_macro2::TokenStream = piece.iter().cloned().collect();
    syn::parse2::<syn::Expr>(stream).map_err(|_| {
        piece
            .into_iter()
            .filter_map(|token| match token {
                proc_macro2::TokenTree::Group(group) => Some(group.stream()),
                _ => None,
            })
            .collect()
    })
}
