/**
 * The page: one connection to the server's pane, and the browser's events
 * handed to it. The frames it is sent are drawn as they come (draw.mjs);
 * what the person does goes back as inputs (see web/server.ts). What decides
 * anything (a key's press, the focus ring's next stop, the retry's wait, the
 * wheel's rows) is a plain function of its arguments, exported beside
 * {@link start}, which only wires them to the document.
 *
 * Views are chosen by the URL's hash, `#/pane` the default and, for now,
 * the only one: another view is another entry in {@link VIEWS}.
 */

/** The first wait before the event stream is opened again, and the longest. */
export const RETRY_FIRST_MS = 500;
export const RETRY_MOST_MS = 5000;
/** How long a page waits for a window that stops changing size before it says so. */
const RESIZE_MS = 100;
/** How long a toast stays. */
const TOAST_MS = 4000;
/** The key the page's id is kept under, for a reload to keep it. */
const ID_KEY = "knossos-page-id";

/** The wait before the `attempt`th retry (0 the first): doubling from half a second, five at most. */
export const retryDelay = (attempt) =>
    Math.min(RETRY_MOST_MS, RETRY_FIRST_MS * 2 ** attempt);

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

/** Every segment of the frame's rows, the footer's after the body's. */
const segmentsOf = (frame) =>
    [...frame.body, ...frame.footer].flatMap((row) => row.segments);

/** The press `key` is the hotkey of in `frame`, out-of-sight twins included; null for none, or a key held with a modifier. */
export function pressOfKey(frame, key) {
    if (frame === null || key.ctrlKey || key.metaKey || key.altKey) return null;
    const s = segmentsOf(frame).find((s) => s.press?.hotkey === key.key);
    return s === undefined ? null : s.press.id;
}

/** The presses the focus ring stops at, in the order they are drawn: every press but the hidden twins. */
export const pressOrder = (frame) =>
    frame === null
        ? []
        : segmentsOf(frame)
              .filter((s) => s.press !== undefined && !s.hidden)
              .map((s) => s.press.id);

/** Where the focus goes from `current` a step `by` (1 or -1) along `order`, held at its ends; from none, the first or the last. */
export function focusStep(order, current, by) {
    if (order.length === 0) return null;
    const at = order.indexOf(current);
    if (at < 0) return by > 0 ? order[0] : order.at(-1);
    return order[Math.min(order.length - 1, Math.max(0, at + by))];
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

/** The press a pointer press lands on: the press it went down on, when it came up on the same one (whatever node now draws it); null otherwise. */
export const releasedOn = (down, up) =>
    down !== null && down !== undefined && down === up ? down : null;

/**
 * One page's connection to the pane: the event stream, opened again with
 * {@link retryDelay} after each loss (the first event back is the current
 * frame, which replaces whatever the page shows), and its inputs, each sent
 * once as it happens and never again. A host that is not a browser hands it
 * `after(ms, fire)` to wait with.
 */
export function createPage(o) {
    let attempt = 0;
    // A host without timers of its own to hand (the browser) waits with `setTimeout`.
    const later = o.after ?? ((ms, fire) => setTimeout(fire, ms));
    const page = {
        frame: null,
        /** The press or field the page's focus is on, as the pane last heard of it. */
        focused: null,
        post(input) {
            return o.fetch("/input", {
                method: "POST",
                headers: {
                    "content-type": "application/json",
                    "x-knossos-token": o.token,
                },
                body: JSON.stringify({ id: o.id, ...input }),
            });
        },
        connect() {
            const size = o.size();
            const query = new URLSearchParams({
                t: o.token,
                id: o.id,
                cols: String(size.cols),
                rows: String(size.rows),
                theme: o.theme(),
            });
            const source = new o.EventSource(`/events?${query}`);
            source.onmessage = (message) => {
                attempt = 0;
                received(JSON.parse(message.data));
            };
            source.onerror = () => {
                // The stream would retry on its own at once; the page waits instead, longer each time.
                source.close();
                later(retryDelay(attempt++), () => page.connect());
            };
        },
        /** The focus landed on `element` (a press or field id): told to the pane, unless it already knows. */
        focusedOn(element) {
            if (element === page.focused) return;
            page.focused = element;
            void page.post({ kind: "focus", element });
        },
        /**
         * A key the person pressed, outside a field: an arrow moves the focus
         * along the presses (`{ focus }`, where the page moves it), a hotkey
         * presses its press (`{ press }`); null leaves the key to the browser.
         */
        key(event) {
            if (event.inField || event.ctrlKey || event.metaKey || event.altKey)
                return null;
            const by = {
                ArrowDown: 1,
                ArrowRight: 1,
                ArrowUp: -1,
                ArrowLeft: -1,
            }[event.key];
            if (by !== undefined) {
                const to = focusStep(pressOrder(page.frame), page.focused, by);
                if (to === null) return null;
                page.focusedOn(to);
                return { focus: to };
            }
            const press = pressOfKey(page.frame, event);
            if (press === null) return null;
            void page.post({ kind: "press", press });
            return { press };
        },
    };
    function received(event) {
        if (event.type === "frame") {
            page.frame = event;
            o.draw(event);
            return;
        }
        // The pane moved its focus: the page follows, and does not tell it back.
        if (event.type === "focus") page.focused = event.key ?? null;
        o.on[event.type]?.(event);
    }
    return page;
}

/**
 * What a view is handed: the page's token, the drawing module, and the rule
 * Claude Code places a card with.
 *
 * @typedef {object} ViewOptions
 * @property {string} token
 * @property {typeof import("./draw.mjs")} draw
 * @property {Function} cardPlace
 */

/** The views the hash names, by name: each draws into the page's one view container. */
const VIEWS = { pane: paneView };

/** Starts the page: the view its hash names (the pane when it names none it knows). */
export async function start() {
    const query = new URL(import.meta.url).search;
    const token = new URLSearchParams(location.search).get("t") ?? "";
    const withToken = (path) =>
        `/static/${path}?t=${encodeURIComponent(token)}`;
    const link = (rel, href) =>
        document.head.append(
            Object.assign(document.createElement("link"), { rel, href }),
        );
    const stylesheet = new Promise((resolve, reject) => {
        const sheet = Object.assign(document.createElement("link"), {
            rel: "stylesheet",
            href: withToken("style.css"),
            onload: resolve,
            onerror: () =>
                reject(new Error("Could not load the interface stylesheet")),
        });
        document.head.append(sheet);
    });
    const favicon = document.querySelector('link[rel="icon"]');
    favicon.type = "image/svg+xml";
    favicon.href = withToken("icon.svg");
    document.getElementById("brand-icon").src = withToken("icon.svg");
    // Version metadata is optional, so an older host can still draw the pane.
    void fetch(withToken("version.txt"))
        .then((response) => (response.ok ? response.text() : ""))
        .then((text) => {
            const version = text.trim();
            if (!/^\d+\.\d+\.\d+(?:[-+][\w.+-]+)?$/.test(version)) return;
            const label = document.getElementById("brand-version");
            label.textContent = `v${version}`;
            label.hidden = false;
            document.title = "Knossos - Architecture Explorer";
        })
        .catch(() => {});
    // The server makes the manifest: its addresses carry the token.
    link("manifest", `/manifest.webmanifest?t=${encodeURIComponent(token)}`);
    const draw = await import(`./draw.mjs${query}`);
    // The server makes this one from the hooks module's own file: the rule Claude Code places a card with.
    const { cardPlace } = await import(`./place.mjs${query}`);
    await stylesheet;
    const name = location.hash.replace(/^#\/?/, "");
    const view = VIEWS[name] ?? VIEWS.pane;
    view(document.getElementById("view"), { token, draw, cardPlace });
}

/** One character cell of the page's grid, in pixels. */
function cellOf(container) {
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
const keyed = (root, key) =>
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
function patcher(container, patchPlan) {
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

/**
 * The pane: the frames drawn into `root`, the person's keys, presses, typing, focus and wheel sent back.
 *
 * @param {HTMLElement} root
 * @param {ViewOptions} options
 */
function paneView(root, { token, draw: drawn, cardPlace }) {
    const light = matchMedia("(prefers-color-scheme: light)");
    const theme = () => (light.matches ? "light" : "dark");
    root.innerHTML =
        '<div class="pane-body"></div><div class="pane-footer"></div>';
    const bodyOf = root.querySelector(".pane-body");
    const footerOf = root.querySelector(".pane-footer");
    const layer = document.getElementById("cards");
    const patch = {
        body: patcher(bodyOf, drawn.patchPlan),
        footer: patcher(footerOf, drawn.patchPlan),
        cards: patcher(layer, drawn.patchPlan),
    };
    let cell = cellOf(root);
    const size = () => ({
        cols: Math.floor(root.clientWidth / cell.width),
        rows: Math.floor(root.clientHeight / cell.height),
    });
    const toasts = document.getElementById("toasts");
    const toast = (text) => {
        const shown = Object.assign(document.createElement("div"), {
            className: "toast",
            textContent: text,
        });
        toasts.append(shown);
        setTimeout(() => shown.remove(), TOAST_MS);
    };
    const page = createPage({
        EventSource,
        fetch: (url, init) => fetch(url, init),
        token,
        id: pageId(
            sessionStorage,
            performance.getEntriesByType("navigation")[0]?.type,
            () => crypto.randomUUID(),
        ),
        size,
        theme,
        draw,
        on: {
            toast: (e) => toast(e.text),
            // The clipboard may refuse a write the person did not just ask for: the page says so, with the text to copy by hand.
            copy: (e) =>
                void navigator.clipboard
                    .writeText(e.text)
                    .catch(() =>
                        toast(
                            `The browser refused the copy. Copy it from here: ${e.text}`,
                        ),
                    ),
            focus: (e) => keyed(root, e.key)?.focus(),
            scroll: (e) =>
                keyed(root, e.key)?.scrollIntoView({ block: "nearest" }),
        },
    });

    /** The card the pointer rests on, by its key, and the segment it hangs from. */
    let hovered = null;
    const showCard = () => {
        for (const card of layer.children) card.classList.remove("shown");
        if (hovered === null || !hovered.anchor.isConnected) return;
        const card = layer.querySelector(
            `[data-card="${CSS.escape(hovered.key)}"]`,
        );
        if (card === null) return;
        card.classList.add("shown");
        const view = { width: window.innerWidth, height: window.innerHeight };
        const seat = drawn.cardSeat(
            hovered.anchor.getBoundingClientRect(),
            cell,
            view,
        );
        const at = cardPlace(
            seat.row,
            seat.column,
            drawn.cardCells(
                { width: card.offsetWidth, height: card.offsetHeight },
                cell,
            ),
            seat.pane,
        );
        const pixels = drawn.cardPixels(at, cell);
        card.style.top = `${pixels.top}px`;
        card.style.left = `${pixels.left}px`;
    };
    root.addEventListener("pointerover", (e) => {
        const anchor = e.target.closest?.("[data-card]");
        if (anchor === null || anchor === undefined) return;
        hovered = { key: anchor.dataset.card, anchor };
        showCard();
    });
    root.addEventListener("pointerout", (e) => {
        if (hovered === null || hovered.anchor.contains(e.relatedTarget))
            return;
        hovered = null;
        showCard();
    });

    function draw(frame) {
        const style = document.documentElement.style;
        style.setProperty("--ground", frame.colours.ground);
        style.setProperty("--text", frame.colours.text);
        style.setProperty("--card", frame.colours.card);
        style.setProperty("--suggestion", frame.colours.suggestion);
        root.classList.toggle("pinned", frame.pinned);
        // Only the rows that changed are drawn again: a press, a selection, the scroll and a field's caret stay as they were.
        patch.body(drawn.drawRows(frame.body));
        patch.footer(drawn.drawRows(frame.footer));
        patch.cards(drawn.drawCards([...frame.body, ...frame.footer]));
        // A card that rests under the pointer follows its segment's new node.
        if (hovered !== null && !hovered.anchor.isConnected) {
            const again = root.querySelector(
                `[data-card="${CSS.escape(hovered.key)}"]`,
            );
            hovered =
                again === null ? null : { key: hovered.key, anchor: again };
        }
        showCard();
    }

    document.addEventListener("keydown", (e) => {
        const inField = e.target instanceof HTMLInputElement;
        if (
            inField &&
            e.key === "Enter" &&
            e.target.dataset.field !== undefined
        ) {
            e.preventDefault();
            void page.post({
                kind: "submit",
                field: e.target.dataset.field,
                value: e.target.value,
            });
            return;
        }
        const done = page.key({
            key: e.key,
            ctrlKey: e.ctrlKey,
            metaKey: e.metaKey,
            altKey: e.altKey,
            inField,
        });
        if (done === null) return;
        e.preventDefault();
        if (done.focus !== undefined) keyed(root, done.focus)?.focus();
    });
    // A pointer presses on release over the press it went down on, by id, so a frame that draws its row again in between loses nothing.
    const pressAt = (e) => e.target.closest?.("[data-press]")?.dataset.press;
    let down = null;
    root.addEventListener("pointerdown", (e) => {
        down = e.button === 0 ? pressAt(e) : null;
    });
    root.addEventListener("pointerup", (e) => {
        const press = releasedOn(down, pressAt(e));
        down = null;
        if (press !== null) void page.post({ kind: "press", press });
    });
    // The keyboard's Enter or Space on a focused press arrives as a click with no pointer behind it.
    root.addEventListener("click", (e) => {
        const press = pressAt(e);
        if (e.detail === 0 && press !== undefined)
            void page.post({ kind: "press", press });
    });
    root.addEventListener("focusin", (e) => {
        const key = e.target.dataset?.press ?? e.target.dataset?.field;
        if (key !== undefined) page.focusedOn(key);
    });
    root.addEventListener("input", (e) => {
        const field = e.target.dataset?.field;
        if (field !== undefined)
            void page.post({ kind: "type", field, value: e.target.value });
    });
    /**
     * `by` rows of scroll at the point (`x`, `y`): over a list longer than its
     * window the pane scrolls it; anywhere else the page scrolls itself. One
     * is sent at a time, each read against the rows the last one left, so the
     * rows that come in meanwhile wait and go as one.
     */
    let held = null;
    let sending = false;
    const scroll = (by, x, y) => {
        held = { by: (held?.by ?? 0) + by, x, y };
        if (sending) return;
        const next = held;
        held = null;
        sending = true;
        const body = bodyOf;
        const box = body.getBoundingClientRect();
        const at = {
            row: Math.floor((next.y - box.top) / cell.height),
            column: Math.floor((next.x - box.left) / cell.width),
        };
        void page
            .post({ kind: "wheel", ...at, by: next.by })
            .then((r) => r.json())
            .then((answer) => {
                if (!answer.scrolled)
                    (root.classList.contains("pinned")
                        ? body
                        : document.scrollingElement
                    ).scrollBy(0, next.by * cell.height);
            })
            .catch(() => undefined)
            .finally(() => {
                sending = false;
                if (held !== null) {
                    const rest = held;
                    held = null;
                    scroll(rest.by, rest.x, rest.y);
                }
            });
    };
    let carry = 0;
    root.addEventListener(
        "wheel",
        (e) => {
            e.preventDefault();
            const turned = wheelRows(carry, e, cell.height);
            carry = turned.carry;
            if (turned.by !== 0) scroll(turned.by, e.clientX, e.clientY);
        },
        { passive: false },
    );
    // A touch screen has no wheel: one finger dragged up or down scrolls as the wheel does, where it first touched, and is no press.
    let swipe = null;
    root.addEventListener("touchstart", (e) => {
        const t = e.touches[0];
        swipe =
            e.touches.length === 1
                ? { x: t.clientX, y: t.clientY, last: t.clientY, carry: 0 }
                : null;
    });
    root.addEventListener(
        "touchmove",
        (e) => {
            if (swipe === null || e.touches.length !== 1) return;
            e.preventDefault();
            const y = e.touches[0].clientY;
            const turned = swipeRows(swipe.carry, swipe.last, y, cell.height);
            swipe.last = y;
            swipe.carry = turned.carry;
            if (turned.by === 0) return;
            down = null;
            scroll(turned.by, swipe.x, swipe.y);
        },
        { passive: false },
    );
    let resizing = null;
    window.addEventListener("resize", () => {
        clearTimeout(resizing);
        resizing = setTimeout(() => {
            cell = cellOf(root);
            const now = size();
            void page.post({ kind: "resize", cols: now.cols, rows: now.rows });
        }, RESIZE_MS);
    });
    light.addEventListener(
        "change",
        () => void page.post({ kind: "theme", theme: theme() }),
    );
    page.connect();
}
