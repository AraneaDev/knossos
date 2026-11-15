// What a page tells search engines and link previews: its address, its
// description and the social card. config.mjs puts these in each page's head,
// and check.mjs checks the built pages against them.

// Where the site is published. Every absolute URL is built from this, so a move
// changes this line and nothing else.
export const SITE_URL = "https://araneadev.github.io/knossos/";

export const SITE_NAME = "Knossos";

// The social card, rendered by og-image.mjs into public/ and served from the
// site's root.
export const OG_IMAGE = {
    path: "og-image.png",
    width: 1200,
    height: 630,
    alt: "The Knossos sigil and name beside the Knossos pane in Claude Code, open on its Overview tab with the project's counts, composition and dependency concentration.",
};

// The browser chrome colour, per appearance, as the site's own background.
export const THEME_COLOR = { dark: "#08090b", light: "#fbfcfd" };

/** The absolute address of a page from its path in the site, e.g. "guides/web-pane". */
export function absoluteUrl(route) {
    return new URL(route.replace(/^\//, ""), SITE_URL).href;
}

/** The site address of a source page, as VitePress builds it with cleanUrls. */
export function routeOfPage(page) {
    return page.replace(/(^|\/)index\.md$/, "$1").replace(/\.md$/, "");
}

const LIMIT = 160;

/** Markdown inline syntax reduced to the text a reader sees. */
function plain(markdown) {
    return markdown
        .replace(/<!--[\s\S]*?-->/g, "")
        .replace(/!\[[^\]]*\]\([^)]*\)/g, "")
        .replace(/\[([^\]]*)\]\([^)]*\)/g, "$1")
        .replace(/\[([^\]]*)\]\[[^\]]*\]/g, "$1")
        .replace(/<[^>]+>/g, "")
        .replace(/`+([^`]*)`+/g, "$1")
        .replace(/(\*\*|__)(\S(?:.*?\S)?)\1/g, "$2")
        .replace(/(^|[\s(])[*_](\S(?:.*?\S)?)[*_](?=[\s.,;:!?)]|$)/g, "$1$2")
        .replace(/\\([\\`*_{}[\]()#+\-.!|<>])/g, "$1")
        .replace(/&nbsp;/g, " ")
        .replace(/&amp;/g, "&")
        .replace(/\s+/g, " ")
        .trim();
}

// The note that opens a generated page, saying where its source is.
const GENERATED = /^This file is generated\b/;

/** A block that is prose: not a heading, list, table, quote, HTML or image. */
function isProse(block) {
    return (
        block !== "" &&
        !/^(#|[-*+] |\d+[.)] |\||>|<|!\[)/.test(block) &&
        // A field such as "Status: Accepted" heads a record; it is not prose.
        !/^[A-Z][a-z]+(?: [a-z]+)?: /.test(block) &&
        !GENERATED.test(block)
    );
}

/**
 * The prose paragraphs of a page, as [lead, rest]: the lead is what comes
 * before its first section. Code, front matter and script blocks are left out.
 */
function paragraphs(source) {
    const body = source
        .replace(/^---\n[\s\S]*?\n---\n/, "")
        .replace(/^(`{3,}|~{3,})[^\n]*\n[\s\S]*?^\1[ \t]*$/gm, "\n")
        .replace(/<script[\s\S]*?<\/script>/g, "\n")
        .replace(/<style[\s\S]*?<\/style>/g, "\n");
    const split = body.search(/^#{2,6} /m);
    const parts =
        split < 0 ? [body, ""] : [body.slice(0, split), body.slice(split)];

    const [lead, rest] = parts.map((part) =>
        part.split(/\n[ \t]*\n/).map((block) => block.trim()),
    );
    // The sections of a generated page are its entries, not prose about the page.
    const generated = lead.some((block) => GENERATED.test(block));

    return [lead.filter(isProse), generated ? [] : rest.filter(isProse)];
}

/** Sentences of plain text: a stop followed by a space and a capital or a digit ends one. */
function sentences(text) {
    return text.split(/(?<=[.!?])\s+(?=[A-Z0-9"'(])/);
}

// A sentence that ends on one of these and a colon leads into what follows it
// ("Run the profile with:") and says nothing on its own.
const LEADS_IN =
    /\b(?:as|at|below|by|follows|following|for|from|in|into|like|of|on|these|this|to|with)$/i;

/** Text cut at the last whole word within the limit, marked as cut. */
function cut(text, limit) {
    const head = text.slice(0, limit);
    const end = head.lastIndexOf(" ");

    return `${head.slice(0, end > 0 ? end : limit).replace(/[\s,;:(-]+$/, "")}…`;
}

/** The sentences of a paragraph that can stand in a description, in order. */
function usable(paragraph, inLead) {
    const all = sentences(plain(paragraph));
    const last = all.length - 1;
    if (!all[last].endsWith(":")) {
        return all;
    }
    // A closing colon introduces a block. An opening sentence of the lead that
    // says something on its own ("one line sums up the turn:") stays, with a
    // full stop; anything else that only leads in ("Run it with:") goes.
    const statement = all[last].slice(0, -1);
    if (last === 0 && inLead && !LEADS_IN.test(statement)) {
        return [`${statement}.`];
    }

    return all.slice(0, last);
}

/**
 * A page's description, derived from its first paragraph of prose: the lead's
 * (before the first section) when it has one, otherwise the first anywhere.
 * Markdown is stripped and as many whole sentences kept as fit within 160
 * characters. When the first sentence is shorter than 40 characters, the next
 * is added, cut at the last word that fits. A page with no prose, or a
 * generated page with none in its lead, gets "".
 */
export function describe(source) {
    const [lead, rest] = paragraphs(source);
    const candidates = [
        ...lead.map((paragraph) => usable(paragraph, true)),
        ...rest.map((paragraph) => usable(paragraph, false)),
    ];
    const all = candidates.find((sentences) => sentences.length > 0);
    if (all === undefined) {
        return "";
    }
    let description = "";
    for (const sentence of all) {
        const next =
            description === "" ? sentence : `${description} ${sentence}`;
        if (next.length > LIMIT) {
            if (description.length < 40) {
                description = cut(next, LIMIT - 1);
            }
            break;
        }
        description = next;
    }

    return description;
}
