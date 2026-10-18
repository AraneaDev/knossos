/**
 * A frame the server sends, as the page's HTML. Nothing here lays anything
 * out: the rows arrive laid out at the page's size, and each becomes one
 * line of the monospace grid. Colours are the frame's own (`colours`, by
 * theme key), so the page states none itself.
 *
 * A segment becomes a span in its colour, weight and ground; a press a
 * button keyed by its id (`data-press`) and its hotkey (`data-hotkey`),
 * out of sight when it is only there for its hotkey; a field an input
 * (`data-field`); a preview a card that shows while the pointer rests on
 * its segment. A `code` row is drawn as its diff: each line numbered in a
 * gutter, an added or removed one on the theme's diff ground. A `raster`
 * row is drawn from its segments' text, which every row carries.
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

/** An inline style from CSS properties, the unset ones left out. */
function styleOf(properties) {
    const set = Object.entries(properties).filter(([, v]) => v !== undefined);
    return set.length === 0
        ? ""
        : ` style="${escapeHtml(set.map(([k, v]) => `${k}:${v}`).join(";"))}"`;
}

/** A segment's ink, ground and weight: dim text is the theme's `inactive`. */
function lookOf(s, colours) {
    const colour = s.color ?? (s.dim ? "inactive" : undefined);
    return {
        color: colour === undefined ? undefined : colours[colour],
        background: s.bg === undefined ? undefined : colours[s.bg],
        "font-weight": s.bold ? "bold" : undefined,
    };
}

/** A press as a button: `hotkey: label` with the hotkey in the accent, as Claude Code draws a Button. */
function pressOf(s, colours) {
    const { id, label, hotkey } = s.press;
    const keyed =
        hotkey === undefined ? "" : ` data-hotkey="${escapeHtml(hotkey)}"`;
    if (s.hidden) {
        return `<button class="hidden" data-press="${escapeHtml(id)}"${keyed} tabindex="-1" aria-label="${escapeHtml(label)}"></button>`;
    }
    const prefix = hotkey === undefined ? "" : `${hotkey}: `;
    const text =
        prefix !== "" && s.text.startsWith(prefix)
            ? `<span class="hot"${styleOf({ color: colours.suggestion })}>${escapeHtml(hotkey)}</span>${escapeHtml(s.text.slice(hotkey.length))}`
            : escapeHtml(s.text);
    return `<button${s.dim ? ' class="dim"' : ""} data-press="${escapeHtml(id)}"${keyed} title="${escapeHtml(label)}"${styleOf(lookOf(s, colours))}>${text}</button>`;
}

/** One segment as HTML. */
function segmentOf(s, colours) {
    if (s.field !== undefined) {
        const { id, value, placeholder } = s.field;
        return `<input data-field="${escapeHtml(id)}" value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder)}" spellcheck="false" autocomplete="off"${styleOf(lookOf(s, colours))}>`;
    }
    const drawn =
        s.press !== undefined
            ? pressOf(s, colours)
            : `<span${styleOf(lookOf(s, colours))}>${escapeHtml(s.text)}</span>`;
    if (s.preview === undefined) return drawn;
    const card = `<div class="card"${styleOf({ width: `${s.preview.width}ch`, background: colours.card })}>${rowsOf(s.preview.rows, colours)}</div>`;
    return `<span class="hover">${drawn}${card}</span>`;
}

/** Blank text after a field, which the field takes as its own room. */
const blank = (s) =>
    s.press === undefined &&
    s.field === undefined &&
    s.bg === undefined &&
    /^ *$/.test(s.text);

/** A diff's lines, numbered from its `@@` header, which is drawn as no line of its own. */
function codeOf(row, colours) {
    const lines = row.code.source.split("\n");
    const head = /^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@/.exec(lines[0] ?? "");
    let old = head === null ? 1 : Number(head[1]);
    let now = head === null ? 1 : Number(head[2]);
    const body = lines.slice(1).filter((line) => /^[ +-]/.test(line));
    const width = String(Math.max(old, now) + body.length).length;
    const gutter = styleOf({ color: colours.inactive });
    const drawn = body.map((line) => {
        const marker = line[0];
        const number = marker === "-" ? old++ : now++;
        if (marker === " ") old++;
        const ground =
            marker === "+"
                ? colours.diffAdded
                : marker === "-"
                  ? colours.diffRemoved
                  : undefined;
        return `<div class="row"${styleOf({ background: ground })}><span class="gutter"${gutter}>${String(number).padStart(width)} </span>${escapeHtml(`${marker} ${line.slice(1)}`)}</div>`;
    });
    return `<div class="code" data-key="${escapeHtml(row.key)}">${drawn.join("")}</div>`;
}

/** One row as a line of the grid. */
function rowOf(row, colours) {
    if (row.code !== undefined) return codeOf(row, colours);
    const out = [];
    for (let i = 0; i < row.segments.length; i++) {
        const s = row.segments[i];
        out.push(segmentOf(s, colours));
        if (s.field !== undefined) {
            while (i + 1 < row.segments.length && blank(row.segments[i + 1]))
                i++;
        }
    }
    return `<div class="row" data-key="${escapeHtml(row.key)}">${out.join("")}</div>`;
}

/** Rows as lines of the grid. */
export const rowsOf = (rows, colours) =>
    rows.map((row) => rowOf(row, colours)).join("");

/** A frame as the pane's HTML: its body, then its footer. */
export function drawFrame(frame) {
    return `<div class="pane-body">${rowsOf(frame.body, frame.colours)}</div><div class="pane-footer">${rowsOf(frame.footer, frame.colours)}</div>`;
}
