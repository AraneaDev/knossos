/**
 * What only the browser's view uses: the size of a character cell, the element
 * of a press or field, the patching of a container's rows, the page's id, the
 * wheel's and a finger's rows, where a moved pane goes, and the proof a server
 * gives that it is this project's pane. Only a browser loads this module; the
 * pane's logic stays in app.mjs, which the terminal client shares.
 */

/** One character cell of the page's grid, in pixels. */
export function cellOf(container) {
    const probe = Object.assign(document.createElement("span"), {
        className: "probe",
        textContent: "M".repeat(10),
    });
    container.append(probe);
    const box = probe.getBoundingClientRect();
    probe.remove();
    return { width: box.width / 10 || 8, height: box.height || 16 };
}

/** The element of the page keyed `key`, a press or a field. */
export const keyed = (root, key) =>
    key === null || key === undefined
        ? null
        : root.querySelector(
              `[data-press="${CSS.escape(key)}"], [data-field="${CSS.escape(key)}"]`,
          );

/**
 * Makes `container`'s children the `entries` (keyed HTML), drawing anew only
 * those that changed since the last call (see `patchPlan`). A row that holds
 * the focused field is changed around it, never replaced: the field keeps
 * its node, so its value, its caret and the focus stay as the person left
 * them, whatever a frame drawn before their last keystroke says.
 */
export function patcher(container, patchPlan) {
    let drawn = [];
    const make = (html) => {
        const holder = document.createElement("template");
        holder.innerHTML = html;
        return holder.content.firstElementChild;
    };
    /** `node` changed to `html` around `input`, which stays where it is; null when the new row has no field of its own. */
    const around = (node, html, input) => {
        const fresh = make(html);
        const twin = fresh.querySelector(
            `[data-field="${CSS.escape(input.dataset.field)}"]`,
        );
        if (twin === null || input.parentElement !== node) return null;
        for (const child of [...node.childNodes])
            if (child !== input) child.remove();
        const kids = [...fresh.childNodes];
        const at = kids.indexOf(twin);
        input.before(...kids.slice(0, at));
        input.after(...kids.slice(at + 1));
        for (const { name, value } of twin.attributes)
            if (name !== "value" && input.getAttribute(name) !== value)
                input.setAttribute(name, value);
        return node;
    };
    return (entries) => {
        const nodes = new Map(drawn.map((e) => [e.key, e.node]));
        const active = document.activeElement;
        const field =
            active instanceof HTMLInputElement && container.contains(active)
                ? active
                : null;
        // The rows gone go first, so none stands between two that stay and makes one of them move.
        const kept = new Set(entries.map((e) => e.key));
        for (const e of drawn) if (!kept.has(e.key)) e.node.remove();
        drawn = patchPlan(drawn, entries).map((step, i) => {
            let node = nodes.get(step.key);
            if (!step.reuse) {
                const old = node;
                node =
                    (old !== undefined &&
                        field !== null &&
                        old.contains(field) &&
                        around(old, step.html, field)) ||
                    make(step.html);
                // In the old one's place: the rows after it stay where they are, and are not moved (a move would blur a field).
                if (
                    old !== undefined &&
                    old !== node &&
                    old.parentNode === container
                )
                    old.replaceWith(node);
            }
            const here = container.children[i];
            if (here !== node) container.insertBefore(node, here ?? null);
            return { key: step.key, html: step.html, node };
        });
        while (container.children.length > drawn.length)
            container.lastElementChild.remove();
    };
}

/** The key the page's id is kept under, for a reload to keep it. */
const ID_KEY = "knossos-page-id";

/**
 * The page's id: the one kept in `storage` when the page was reloaded, else
 * a new one, kept. A duplicated tab copies the store but arrives by no
 * reload, so two tabs never share an id.
 */
export function pageId(storage, navigation, random) {
    const kept = navigation === "reload" ? storage?.getItem(ID_KEY) : null;
    if (kept) return kept;
    const id = random();
    storage?.setItem(ID_KEY, id);
    return id;
}

/** A wheel event as whole rows (`by`), lines as they are and pixels by the cell's height; what is left over is carried to the next. */
export function wheelRows(carry, event, cellHeight) {
    if (event.deltaMode !== 0)
        return { by: Math.trunc(event.deltaY), carry: 0 };
    const pixels = carry + event.deltaY;
    const by = Math.trunc(pixels / cellHeight);
    return { by, carry: pixels - by * cellHeight };
}

/** A finger's drag from `fromY` to `toY` as whole rows, as {@link wheelRows} turns pixels: a finger moving up scrolls down. */
export const swipeRows = (carry, fromY, toY, cellHeight) =>
    wheelRows(carry, { deltaMode: 0, deltaY: fromY - toY }, cellHeight);

/**
 * The cell a pointer at (`x`, `y`) is over in the body whose box is `box`,
 * scrolled `scrolled` pixels (a pinned body scrolls inside its box, whose top
 * stays where it is).
 */
export const wheelCell = (x, y, box, cell, scrolled) => ({
    row: Math.floor((y - box.top + scrolled) / cell.height),
    column: Math.floor((x - box.left) / cell.width),
});

/**
 * Where a page at `href` goes when its server says the project's pane moved to
 * `port`: the same address on that port, its token and view kept, so nothing
 * the page did not already have is put in it. Null for a port that is not one.
 */
export function movedTo(port, href) {
    if (!Number.isInteger(port) || port <= 0 || port >= 65536) return null;
    const url = new URL(href);
    url.port = String(port);
    return url.href;
}

/**
 * Whether the server at the page's own address proves it is this project's
 * pane, asked without the token (see `proofOf` in web/lock.ts): a fresh
 * challenge, answered with an HMAC keyed with the token over it and the port.
 * The pane's own port and the one it gives directions on both count. A
 * browser without WebCrypto (only one that does not count 127.0.0.1 as a
 * secure place) cannot tell, and is let through as before the proof.
 */
export async function provesPane(fetchIt, subtle, token, port) {
    if (subtle === undefined) return true;
    const challenge = [...crypto.getRandomValues(new Uint8Array(16))]
        .map((b) => b.toString(16).padStart(2, "0"))
        .join("");
    const res = await fetchIt("/", {
        headers: { "x-knossos-challenge": challenge },
        cache: "no-store",
    });
    await res.body?.cancel?.().catch?.(() => undefined);
    const given = res.headers.get("x-knossos-proof");
    if (given === null) return false;
    const encoder = new TextEncoder();
    const key = await subtle.importKey(
        "raw",
        encoder.encode(token),
        { name: "HMAC", hash: "SHA-256" },
        false,
        ["sign"],
    );
    for (const kind of ["pane", "directions"]) {
        const signed = await subtle.sign(
            "HMAC",
            key,
            encoder.encode(`knossos-${kind}:${challenge}:${port}`),
        );
        const want = [...new Uint8Array(signed)]
            .map((b) => b.toString(16).padStart(2, "0"))
            .join("");
        if (want === given) return true;
    }
    return false;
}
