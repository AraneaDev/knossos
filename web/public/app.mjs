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

/**
 * The manifest, made for this page: its URLs carry the token, which no
 * file can hold since it changes each time the server starts.
 */
export function manifestFor(manifest, pageUrl) {
    const page = new URL(pageUrl);
    const token = page.searchParams.get("t") ?? "";
    const of = (src) => {
        const url = new URL(src, new URL("/static/", page));
        url.searchParams.set("t", token);
        return url.href;
    };
    return {
        ...manifest,
        start_url: page.href,
        scope: new URL("/", page).href,
        icons: (manifest.icons ?? []).map((icon) => ({
            ...icon,
            src: of(icon.src),
        })),
    };
}

/**
 * One page's connection to the pane: the event stream, opened again with
 * {@link retryDelay} after each loss (the first event back is the current
 * frame, which replaces whatever the page shows), and its inputs, each sent
 * once as it happens and never again.
 */
export function createPage(o) {
    let source = null;
    let attempt = 0;
    let timer = null;
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
            source = new o.EventSource(`/events?${query}`);
            source.onmessage = (message) => {
                attempt = 0;
                received(JSON.parse(message.data));
            };
            source.onerror = () => {
                // The stream would retry on its own at once; the page waits instead, longer each time.
                source.close();
                timer = setTimeout(() => {
                    timer = null;
                    page.connect();
                }, retryDelay(attempt++));
            };
        },
        close() {
            if (timer !== null) clearTimeout(timer);
            source?.close();
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
    link("stylesheet", withToken("style.css"));
    link("icon", withToken("icon.svg"));
    const manifest = await fetch(withToken("manifest.webmanifest"))
        .then((r) => r.json())
        .catch(() => null);
    if (manifest !== null) {
        link(
            "manifest",
            URL.createObjectURL(
                new Blob(
                    [JSON.stringify(manifestFor(manifest, location.href))],
                    { type: "application/manifest+json" },
                ),
            ),
        );
    }
    const { drawFrame } = await import(`./draw.mjs${query}`);
    const name = location.hash.replace(/^#\/?/, "");
    const view = VIEWS[name] ?? VIEWS.pane;
    view(document.getElementById("view"), { token, drawFrame });
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

/** The pane: the frames drawn into `root`, the person's keys, presses, typing, focus and wheel sent back. */
function paneView(root, { token, drawFrame }) {
    const light = matchMedia("(prefers-color-scheme: light)");
    const theme = () => (light.matches ? "light" : "dark");
    let cell = cellOf(root);
    const size = () => ({
        cols: Math.floor(root.clientWidth / cell.width),
        rows: Math.floor(window.innerHeight / cell.height),
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
            // The clipboard may refuse a write the person did not just ask for: the text is shown instead, to copy by hand.
            copy: (e) =>
                void navigator.clipboard
                    .writeText(e.text)
                    .catch(() => toast(e.text)),
            focus: (e) => keyed(root, e.key)?.focus(),
            scroll: (e) =>
                keyed(root, e.key)?.scrollIntoView({ block: "nearest" }),
        },
    });

    function draw(frame) {
        const style = document.documentElement.style;
        style.setProperty("--ground", frame.colours.ground);
        style.setProperty("--text", frame.colours.text);
        style.setProperty("--card", frame.colours.card);
        // A redraw keeps where the person was: the focus, a field's caret, the body's scroll.
        const active = document.activeElement;
        const was = root.contains(active)
            ? (active.dataset.press ?? active.dataset.field ?? null)
            : null;
        const caret =
            active instanceof HTMLInputElement
                ? [active.selectionStart, active.selectionEnd]
                : null;
        const scrolled = root.querySelector(".pane-body")?.scrollTop ?? 0;
        root.classList.toggle("pinned", frame.pinned);
        root.innerHTML = drawFrame(frame);
        const body = root.querySelector(".pane-body");
        if (body !== null) body.scrollTop = scrolled;
        const again = keyed(root, was);
        if (again !== null) {
            again.focus({ preventScroll: true });
            if (caret !== null && again instanceof HTMLInputElement)
                again.setSelectionRange(caret[0], caret[1]);
        }
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
    root.addEventListener("click", (e) => {
        const button = e.target.closest?.("[data-press]");
        if (button !== null && button !== undefined)
            void page.post({ kind: "press", press: button.dataset.press });
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
    let carry = 0;
    root.addEventListener(
        "wheel",
        (e) => {
            const body = root.querySelector(".pane-body");
            if (body === null) return;
            e.preventDefault();
            const turned = wheelRows(carry, e, cell.height);
            carry = turned.carry;
            if (turned.by === 0) return;
            const box = body.getBoundingClientRect();
            const at = {
                row: Math.floor((e.clientY - box.top) / cell.height),
                column: Math.floor((e.clientX - box.left) / cell.width),
            };
            // Over a list longer than its window the pane scrolls it; anywhere else the page scrolls itself.
            void page
                .post({ kind: "wheel", ...at, by: turned.by })
                .then((r) => r.json())
                .then((answer) => {
                    if (!answer.scrolled)
                        (root.classList.contains("pinned")
                            ? body
                            : document.scrollingElement
                        ).scrollBy(0, turned.by * cell.height);
                })
                .catch(() => undefined);
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
