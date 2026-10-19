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
 * only those that changed ({@link patchPlan}), and a card is placed by the shared
 * rule from {@link cardSeat}.
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
    // Every segment occupies the cells allocated by the shared layout.
    // Tracks use the row's regular font, so bold text and fallback drawing
    // glyphs cannot push later columns out of place. Hidden hotkeys take no
    // track; fields use their allocated room rather than their empty text.
    // The fallback also supports frames from hosts predating width metadata.
    const tracks = styleOf(
        {},
        {
            "grid-template-columns":
                row.segments
                    .filter((s) => !s.hidden)
                    .map(
                        (s) =>
                            `${s.field?.width ?? s.columns ?? Array.from(s.text).length}ch`,
                    )
                    .join(" ") || "none",
        },
    );
    return `<div class="row" data-key="${escapeHtml(row.key)}"${tracks}>${row.segments.map(segmentOf).join("")}</div>`;
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
 * The cell the pointer's segment is in, and the window in cells, for the
 * placement rule (`place.mjs`, the rule Claude Code places a card with): the
 * segment's box on the page over the size of a cell, whole cells only.
 */
export function cardSeat(anchor, cell, view) {
    return {
        row: Math.floor(anchor.top / cell.height),
        column: Math.floor(anchor.left / cell.width),
        pane: {
            rows: Math.floor(view.height / cell.height),
            columns: Math.floor(view.width / cell.width),
        },
    };
}

/** A card's size in cells, from its box in pixels. */
export function cardCells(box, cell) {
    return {
        rows: Math.ceil(box.height / cell.height),
        columns: Math.ceil(box.width / cell.width),
    };
}

/** A cell the rule chose, as the pixels the card sits at. */
export function cardPixels(place, cell) {
    return { top: place.row * cell.height, left: place.column * cell.width };
}
