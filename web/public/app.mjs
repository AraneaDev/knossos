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
/** How often the server sends a keep-alive on the event stream, whatever else it sends. */
export const ALIVE_MS = 10000;
/**
 * How often a page looks whether its stream said anything: a whole period with
 * no event at all (two keep-alives missed) is a stream lost, so a stream gone
 * silent is noticed within one to two periods. A server that is scanning still
 * sends them: a scan runs in a process of its own.
 */
export const SILENT_MS = 2 * ALIVE_MS;
/** How long a page waits for a window that stops changing size before it says so. */
const RESIZE_MS = 100;
/** How long a toast stays. */
export const TOAST_MS = 4000;

/** The wait before the `attempt`th retry (0 the first): doubling from half a second, five at most. */
export const retryDelay = (attempt) =>
    Math.min(RETRY_MOST_MS, RETRY_FIRST_MS * 2 ** attempt);

/** Every segment of the frame's rows, the footer's after the body's. */
export const segmentsOf = (frame) =>
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

/** The press a pointer press lands on: the press it went down on, when it came up on the same one (whatever node now draws it); null otherwise. */
export const releasedOn = (down, up) =>
    down !== null && down !== undefined && down === up ? down : null;

/**
 * The wheel's queue: `scroll(by, row, column)` sends `by` rows of wheel with
 * `send({ row, column, by })`, one at a time, each read against the rows the
 * last one left, so the rows that come in meanwhile wait and go as one.
 * `onUnscrolled(by)` is told of rows the pane did not scroll with; a send
 * that fails is forgotten.
 */
export function wheelQueue(send, onUnscrolled) {
    let held = null;
    let sending = false;
    const scroll = (by, row, column) => {
        held = { by: (held?.by ?? 0) + by, row, column };
        if (sending) return;
        const next = held;
        held = null;
        sending = true;
        void send({ row: next.row, column: next.column, by: next.by })
            .then((r) => r.json())
            .then((answer) => {
                if (!answer.scrolled) onUnscrolled(next.by);
            })
            .catch(() => undefined)
            .finally(() => {
                sending = false;
                if (held !== null) {
                    const rest = held;
                    held = null;
                    scroll(rest.by, rest.row, rest.column);
                }
            });
    };
    return scroll;
}

/**
 * One page's connection to the pane: the event stream, opened again with
 * {@link retryDelay} after each loss (the first event back is the current
 * frame, which replaces whatever the page shows), and its inputs, each sent
 * once as it happens and never again. A stream silent for {@link SILENT_MS}
 * is a loss as well, once its server is known to send keep-alives, and
 * `lost()`, when given, is told of every loss. `prove()`, when given, is asked
 * before each stream opens, and the token goes nowhere until it says yes. A host
 * that is not a browser hands it `after(ms, fire)` to wait with.
 */
export function createPage(o) {
    let attempt = 0;
    /** Whether the host closed the page for good: no stream is opened again, and no silence is looked for. */
    let stopped = false;
    /**
     * Whether the page's server has sent a keep-alive event: only then is a silent stream a lost one. A server from
     * before those events (an update can leave one running) sends a comment no page sees, and is never taken for lost.
     */
    let sendsAlive = false;
    /**
     * Whether the token may go to the page's server: proven by `o.prove` before each stream opens, and no longer
     * once a stream is lost, as the port may be another's by then. A host that proves in its own way (the terminal
     * client, in its fetch) hands no `prove`.
     */
    let trusted = o.prove === undefined;
    // A host without timers of its own to hand (the browser) waits with `setTimeout`.
    const later = o.after ?? ((ms, fire) => setTimeout(fire, ms));
    const page = {
        frame: null,
        /** The press or field the page's focus is on, as the pane last heard of it. */
        focused: null,
        post(input) {
            if (!trusted)
                return Promise.reject(
                    new Error("the pane server is not proven yet"),
                );
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
            if (stopped) return;
            // Both are this connect's own: a retry comes back to it, as one call of it scheduling the next.
            /** No stream could be opened: told as a loss, and tried again after the usual wait. */
            const failed = () => {
                if (stopped) return;
                o.lost?.();
                later(retryDelay(attempt++), () => page.connect());
            };
            /** Opens the event stream, looked after until it is lost. */
            const open = () => {
                const size = o.size();
                const query = new URLSearchParams({
                    t: o.token,
                    id: o.id,
                    cols: String(size.cols),
                    rows: String(size.rows),
                    theme: o.theme(),
                });
                const source = new o.EventSource(`/events?${query}`);
                let heard = 0;
                let gone = false;
                const lose = () => {
                    if (gone) return;
                    gone = true;
                    if (o.prove !== undefined) trusted = false;
                    // The stream would retry on its own at once; the page waits instead, longer each time.
                    source.close();
                    o.lost?.();
                    later(retryDelay(attempt++), () => page.connect());
                };
                // A stream that says nothing for a whole period is lost too, as one whose server was suspended is: it
                // never ends by itself. The same holds for one that never opens.
                const look = (seen) =>
                    later(SILENT_MS, () => {
                        if (gone || stopped) return;
                        if (heard === seen && sendsAlive) return lose();
                        look(heard);
                    });
                source.onmessage = (message) => {
                    heard++;
                    attempt = 0;
                    const event = JSON.parse(message.data);
                    if (event.type === "alive") sendsAlive = true;
                    received(event);
                };
                source.onerror = lose;
                look(heard);
            };
            if (o.prove === undefined) return open();
            // The token goes only to a server that proved it is this project's pane; one that cannot is tried again later.
            void o
                .prove()
                .then(
                    (proven) =>
                        proven ? ((trusted = true), open()) : failed(),
                    failed,
                );
        },
        /** Ends the page's connection for good: the host is closing. */
        stop() {
            stopped = true;
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
 * What a view is handed: the page's token, the drawing module, the rule
 * Claude Code places a card with, and what the view does to its document.
 *
 * @typedef {object} ViewOptions
 * @property {string} token
 * @property {typeof import("./draw.mjs")} draw
 * @property {Function} cardPlace
 * @property {typeof import("./dom.mjs")} dom
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
    const dom = await import(`./dom.mjs${query}`);
    await stylesheet;
    const name = location.hash.replace(/^#\/?/, "");
    const view = VIEWS[name] ?? VIEWS.pane;
    view(document.getElementById("view"), { token, draw, cardPlace, dom });
}

/**
 * The pane: the frames drawn into `root`, the person's keys, presses, typing, focus and wheel sent back.
 *
 * @param {HTMLElement} root
 * @param {ViewOptions} options
 */
function paneView(root, { token, draw: drawn, cardPlace, dom }) {
    const {
        cellOf,
        keyed,
        patcher,
        pageId,
        wheelRows,
        swipeRows,
        wheelCell,
        follow,
        provesPane,
    } = dom;
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
        // The page's own port: the address it was opened on, or the default the scheme gives.
        prove: () =>
            provesPane(
                (url, init) => fetch(url, init),
                globalThis.crypto?.subtle,
                token,
                Number(location.port) || 80,
            ),
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
            // The server stepped down for another that took the project over (see `follow`).
            moved: (e) => void follow(e.port, token, toasts),
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
    const queue = wheelQueue(
        (at) => page.post({ kind: "wheel", ...at }),
        (by) =>
            (root.classList.contains("pinned")
                ? bodyOf
                : document.scrollingElement
            ).scrollBy(0, by * cell.height),
    );
    const scroll = (by, x, y) => {
        const pinned = root.classList.contains("pinned");
        const at = wheelCell(
            x,
            y,
            bodyOf.getBoundingClientRect(),
            cell,
            pinned ? bodyOf.scrollTop : 0,
        );
        queue(by, at.row, at.column);
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
