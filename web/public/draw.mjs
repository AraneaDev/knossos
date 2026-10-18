/**
 * A frame the server sends, as the page's HTML. Nothing here lays anything
 * out or decides how anything looks: the rows arrive ready to draw (see
 * web/page-rows.ts), each segment with its look resolved to colours, and
 * each becomes one line of the monospace grid.
 *
 * A segment becomes a span in its look; a press a button keyed by its id
 * (`data-press`) and its hotkey (`data-hotkey`), out of sight when it is
 * only there for its hotkey; a field an input (`data-field`) as wide as its
 * room; a segment with a card a span (`data-card`) whose card is drawn apart,
 * in the page's layer of cards, so no row's edge cuts it. A diff row is drawn
 * as its lines, each numbered in a gutter.
 *
 * The rows are drawn one by one, keyed (`data-key`), so the page can replace
 * only those that changed ({@link patchPlan}), and a card is placed by
 * {@link cardPlace}.
 */

const ESCAPES = {
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#39;",
};

/** `text` safe to put in HTML, as text or in a quoted attribute. */
export const escapeHtml = (text) =>
    String(text).replace(/[&<>"']/g, (ch) => ESCAPES[ch]);

/** An inline style from a look (and any other CSS properties), the unset ones left out. */
function styleOf(look = {}, more = {}) {
    const properties = {
        color: look.color,
        background: look.background,
        "font-weight": look.bold ? "bold" : undefined,
        ...more,
    };
    const set = Object.entries(properties).filter(([, v]) => v !== undefined);
    return set.length === 0
        ? ""
        : ` style="${escapeHtml(set.map(([k, v]) => `${k}:${v}`).join(";"))}"`;
}

/** A press as a button: `hotkey: label` with the hotkey in the accent, as Claude Code draws a Button. */
function pressOf(s) {
    const { id, label, title, hotkey, dim } = s.press;
    const keyed =
        hotkey === undefined ? "" : ` data-hotkey="${escapeHtml(hotkey)}"`;
    if (s.hidden) {
        return `<button class="hidden" data-press="${escapeHtml(id)}"${keyed} tabindex="-1" aria-label="${escapeHtml(title)}"></button>`;
    }
    const text =
        hotkey === undefined
            ? escapeHtml(label)
            : `<span class="hot">${escapeHtml(hotkey)}</span>: ${escapeHtml(label)}`;
    return `<button${dim ? ' class="dim"' : ""} data-press="${escapeHtml(id)}"${keyed} title="${escapeHtml(title)}"${styleOf(s.look)}>${text}</button>`;
}

/** One segment as HTML. */
function segmentOf(s) {
    if (s.field !== undefined) {
        const { id, value, placeholder, width } = s.field;
        return `<input data-field="${escapeHtml(id)}" value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder)}" spellcheck="false" autocomplete="off"${styleOf(s.look, { width: `${width}ch` })}>`;
    }
    const drawn =
        s.press !== undefined
            ? pressOf(s)
            : `<span${styleOf(s.look)}>${escapeHtml(s.text)}</span>`;
    return s.card === undefined
        ? drawn
        : `<span class="hover" data-card="${escapeHtml(s.card.key)}">${drawn}</span>`;
}

/** One row as a line of the grid; a diff row as its lines. */
export function rowHtml(row) {
    if (row.lines !== undefined) {
        const lines = row.lines.map(
            (line) =>
                `<div class="line"${styleOf(line.look)}><span class="gutter"${styleOf(row.gutter)}>${escapeHtml(line.gutter)} </span>${escapeHtml(line.text)}</div>`,
        );
        return `<div class="code" data-key="${escapeHtml(row.key)}">${lines.join("")}</div>`;
    }
    return `<div class="row" data-key="${escapeHtml(row.key)}">${row.segments.map(segmentOf).join("")}</div>`;
}

/** Keys made unique in order: a key seen before gets `#2`, `#3`, … so every node of a list has a key of its own. */
function uniquely(entries) {
    const seen = new Map();
    return entries.map(({ key, html }) => {
        const n = (seen.get(key) ?? 0) + 1;
        seen.set(key, n);
        return { key: n === 1 ? key : `${key}#${n}`, html };
    });
}

/** Rows as keyed HTML, one entry a row. */
export const drawRows = (rows) =>
    uniquely(rows.map((row) => ({ key: row.key, html: rowHtml(row) })));

/** The cards `rows` hang, as keyed HTML for the page's layer of cards: each its lines on the card's ground, as wide as it is. */
export const drawCards = (rows) =>
    uniquely(
        rows
            .flatMap((row) => row.segments)
            .filter((s) => s.card !== undefined)
            .map(({ card }) => ({
                key: card.key,
                html: `<div class="card" data-card="${escapeHtml(card.key)}"${styleOf({}, { width: `${card.width}ch` })}>${card.lines.map((line) => `<div class="line">${escapeHtml(line)}</div>`).join("")}</div>`,
            })),
    );

/**
 * How a list of keyed nodes becomes `next` from `old`: one step for each
 * entry of `next`, in order, that either reuses the node drawn before
 * (`reuse`: same key, same HTML) or draws the entry anew. Whatever `old`
 * holds that `next` does not is removed.
 */
export function patchPlan(old, next) {
    const before = new Map(old.map((e) => [e.key, e.html]));
    return next.map(({ key, html }) => ({
        key,
        html,
        reuse: before.get(key) === html,
    }));
}

/**
 * Where a card goes for a segment at `anchor` (its box on the page): under
 * it, or over it when the window below cannot hold the card and the window
 * above can; never past the window's right edge. As Claude Code places one.
 */
export function cardPlace(anchor, size, view) {
    const below =
        anchor.bottom + size.height <= view.height || anchor.top < size.height;
    return {
        top: below ? anchor.bottom : anchor.top - size.height,
        left: Math.max(0, Math.min(anchor.left, view.width - size.width)),
    };
}

/** A frame as the pane's HTML: its body, then its footer, then its cards' layer. */
export function drawFrame(frame) {
    const html = (entries) => entries.map((e) => e.html).join("");
    return `<div class="pane-body">${html(drawRows(frame.body))}</div><div class="pane-footer">${html(drawRows(frame.footer))}</div><div class="cards">${html(drawCards([...frame.body, ...frame.footer]))}</div>`;
}
