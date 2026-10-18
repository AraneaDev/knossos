import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
    createPage,
    focusStep,
    manifestFor,
    pageId,
    pressOfKey,
    pressOrder,
    retryDelay,
    wheelRows,
} from "./public/app.mjs";

/** An event stream that does nothing until a spec says so. */
class FakeSource {
    static opened = [];
    constructor(url) {
        this.url = url;
        this.closed = false;
        FakeSource.opened.push(this);
    }
    close() {
        this.closed = true;
    }
    /** The server sends `event`. */
    emit(event) {
        this.onmessage?.({ data: JSON.stringify(event) });
    }
    fail() {
        this.onerror?.(new Error("gone"));
    }
}

const press = (id, hotkey, extra = {}) => ({
    text: hotkey === undefined ? id : `${hotkey}: ${id}`,
    press: { id, label: id, ...(hotkey === undefined ? {} : { hotkey }) },
    ...extra,
});

/** A frame with two tabs (each a hidden hotkey twin and its button), a row press and a rescan in the footer. */
const FRAME = {
    type: "frame",
    body: [
        {
            key: "tabs",
            segments: [
                press("tabkey:overview", "1", { text: "", hidden: true }),
                press("tab:overview"),
                press("tabkey:hubs", "2", { text: "", hidden: true }),
                press("tab:hubs"),
            ],
        },
        { key: "r0", segments: [{ text: "  " }, press("row:0")] },
    ],
    footer: [{ key: "f", segments: [press("rescan", "r")] }],
    pinned: true,
    theme: "dark",
    colours: {},
};

/** A page over the fake stream and a recorded fetch. */
function page() {
    const posts = [];
    const fetch = vi.fn(async (url, init) => {
        posts.push({ url, headers: init.headers, body: JSON.parse(init.body) });
        return { ok: true, status: 204, json: async () => ({}) };
    });
    const drawn = [];
    const p = createPage({
        EventSource: FakeSource,
        fetch,
        token: "tok",
        id: "tab-1",
        size: () => ({ cols: 90, rows: 30 }),
        theme: () => "dark",
        draw: (frame) => drawn.push(frame),
        on: {},
    });
    return { p, posts, fetch, drawn };
}

beforeEach(() => {
    FakeSource.opened = [];
});
afterEach(() => {
    vi.useRealTimers();
});

describe("keys", () => {
    it("finds the press a key is the hotkey of, hidden twins included", () => {
        expect(pressOfKey(FRAME, { key: "2" })).toBe("tabkey:hubs");
        expect(pressOfKey(FRAME, { key: "r" })).toBe("rescan");
        expect(pressOfKey(FRAME, { key: "x" })).toBeNull();
        // A key held with a modifier is the browser's.
        expect(pressOfKey(FRAME, { key: "r", ctrlKey: true })).toBeNull();
        expect(pressOfKey(null, { key: "r" })).toBeNull();
    });

    it("posts the press of a key that matches a visible hotkey", async () => {
        const { p, posts } = page();
        p.connect();
        FakeSource.opened[0].emit(FRAME);
        expect(p.key({ key: "2" })).toEqual({ press: "tabkey:hubs" });
        await vi.waitFor(() => expect(posts).toHaveLength(1));
        expect(posts[0].url).toBe("/input");
        expect(posts[0].headers).toEqual({
            "content-type": "application/json",
            "x-knossos-token": "tok",
        });
        expect(posts[0].body).toEqual({
            id: "tab-1",
            kind: "press",
            press: "tabkey:hubs",
        });
    });

    it("leaves a key typed into a field to the field", () => {
        const { p, posts } = page();
        p.connect();
        FakeSource.opened[0].emit(FRAME);
        expect(p.key({ key: "r", inField: true })).toBeNull();
        expect(posts).toHaveLength(0);
    });

    it("walks the focus over the drawn presses in order, the hidden twins left out", () => {
        const order = pressOrder(FRAME);
        expect(order).toEqual(["tab:overview", "tab:hubs", "row:0", "rescan"]);
        expect(focusStep(order, "tab:hubs", 1)).toBe("row:0");
        expect(focusStep(order, "tab:hubs", -1)).toBe("tab:overview");
        expect(focusStep(order, "rescan", 1)).toBe("rescan");
        expect(focusStep(order, "tab:overview", -1)).toBe("tab:overview");
        // From nowhere, down starts at the first and up at the last.
        expect(focusStep(order, null, 1)).toBe("tab:overview");
        expect(focusStep(order, null, -1)).toBe("rescan");
        expect(focusStep([], null, 1)).toBeNull();
    });

    it("moves the focus to the next pressable on ArrowDown and posts focus with its id", async () => {
        const { p, posts } = page();
        p.connect();
        FakeSource.opened[0].emit(FRAME);
        p.focused = "tab:hubs";
        expect(p.key({ key: "ArrowDown" })).toEqual({ focus: "row:0" });
        expect(p.focused).toBe("row:0");
        await vi.waitFor(() => expect(posts).toHaveLength(1));
        expect(posts[0].body).toEqual({
            id: "tab-1",
            kind: "focus",
            element: "row:0",
        });
        expect(p.key({ key: "ArrowUp" })).toEqual({ focus: "tab:hubs" });
        // The focus landing where the page put it is not posted twice.
        p.focusedOn("tab:hubs");
        await vi.waitFor(() => expect(posts).toHaveLength(2));
        p.focusedOn("rescan");
        await vi.waitFor(() => expect(posts).toHaveLength(3));
        expect(posts[2].body.element).toBe("rescan");
    });
});

describe("the event stream", () => {
    it("opens with the token, the page's id, size and theme", () => {
        const { p } = page();
        p.connect();
        const url = new URL(FakeSource.opened[0].url, "http://127.0.0.1");
        expect(url.pathname).toBe("/events");
        expect(Object.fromEntries(url.searchParams)).toEqual({
            t: "tok",
            id: "tab-1",
            cols: "90",
            rows: "30",
            theme: "dark",
        });
    });

    it("reconnects and draws the current frame", () => {
        vi.useFakeTimers();
        const { p, fetch, drawn } = page();
        p.connect();
        FakeSource.opened[0].emit(FRAME);
        expect(drawn).toEqual([FRAME]);
        // The server goes away: each retry waits twice as long, up to five seconds.
        const waits = [];
        for (let i = 0; i < 6; i++) {
            const source = FakeSource.opened.at(-1);
            source.fail();
            expect(source.closed).toBe(true);
            const before = FakeSource.opened.length;
            const started = Date.now();
            while (FakeSource.opened.length === before)
                vi.advanceTimersByTime(100);
            waits.push(Date.now() - started);
        }
        expect(waits).toEqual([500, 1000, 2000, 4000, 5000, 5000]);
        // Back: the first event is the current frame, and it replaces the page.
        const later = {
            ...FRAME,
            body: [{ key: "x", segments: [{ text: "now" }] }],
        };
        FakeSource.opened.at(-1).emit(later);
        expect(drawn.at(-1)).toEqual(later);
        // Nothing done while it was gone is sent again.
        expect(fetch).not.toHaveBeenCalled();
        // A later loss starts the waits over.
        FakeSource.opened.at(-1).fail();
        const before = FakeSource.opened.length;
        vi.advanceTimersByTime(500);
        expect(FakeSource.opened.length).toBe(before + 1);
    });

    it("hands the other events to their handlers", () => {
        const seen = [];
        const p = createPage({
            EventSource: FakeSource,
            fetch: async () => ({ ok: true }),
            token: "t",
            id: "a",
            size: () => ({ cols: 80, rows: 24 }),
            theme: () => "light",
            draw: () => undefined,
            on: {
                toast: (e) => seen.push(["toast", e.text]),
                focus: (e) => seen.push(["focus", e.key]),
            },
        });
        p.connect();
        FakeSource.opened[0].emit({ type: "toast", text: "hi" });
        FakeSource.opened[0].emit({ type: "focus", key: "tab:hubs" });
        FakeSource.opened[0].emit({ type: "scroll", key: "row:3" });
        expect(seen).toEqual([
            ["toast", "hi"],
            ["focus", "tab:hubs"],
        ]);
        expect(p.focused).toBe("tab:hubs");
    });

    it("waits half a second first and five at most", () => {
        expect([0, 1, 2, 3, 4, 10].map(retryDelay)).toEqual([
            500, 1000, 2000, 4000, 5000, 5000,
        ]);
    });
});

describe("the page's own", () => {
    it("keeps its id over a reload and takes a new one otherwise", () => {
        const store = new Map();
        const storage = {
            getItem: (k) => store.get(k) ?? null,
            setItem: (k, v) => store.set(k, v),
        };
        let n = 0;
        const random = () => `id-${++n}`;
        expect(pageId(storage, "navigate", random)).toBe("id-1");
        expect(pageId(storage, "reload", random)).toBe("id-1");
        // A duplicated tab copies the store, but comes in by no reload: it takes its own.
        expect(pageId(storage, "back_forward", random)).toBe("id-2");
        expect(pageId(null, "reload", random)).toBe("id-3");
    });

    it("turns the wheel's pixels and lines into whole rows, carrying the rest", () => {
        expect(wheelRows(0, { deltaY: 3, deltaMode: 1 }, 20)).toEqual({
            by: 3,
            carry: 0,
        });
        expect(wheelRows(0, { deltaY: 30, deltaMode: 0 }, 20)).toEqual({
            by: 1,
            carry: 10,
        });
        expect(wheelRows(10, { deltaY: 10, deltaMode: 0 }, 20)).toEqual({
            by: 1,
            carry: 0,
        });
        expect(wheelRows(0, { deltaY: -50, deltaMode: 0 }, 20)).toEqual({
            by: -2,
            carry: -10,
        });
    });

    it("points its manifest at this page and its icon with the token", () => {
        const made = manifestFor(
            {
                name: "Knossos",
                start_url: "/",
                icons: [{ src: "icon.svg", type: "image/svg+xml" }],
            },
            "http://127.0.0.1:4000/?t=tok#/pane",
        );
        expect(made.start_url).toBe("http://127.0.0.1:4000/?t=tok#/pane");
        expect(made.scope).toBe("http://127.0.0.1:4000/");
        expect(made.icons[0].src).toBe(
            "http://127.0.0.1:4000/static/icon.svg?t=tok",
        );
        expect(made.name).toBe("Knossos");
    });
});
