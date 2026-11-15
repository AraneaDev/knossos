import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ALIVE_MS as SERVER_ALIVE_MS } from "./server.ts";
import { proofOf } from "./lock.ts";
import {
    ALIVE_MS,
    SILENT_MS,
    createPage,
    focusStep,
    pressOfKey,
    pressOrder,
    releasedOn,
    retryDelay,
    wheelQueue,
} from "./public/app.mjs";
import {
    movedTo,
    pageId,
    provesPane,
    swipeRows,
    wheelCell,
    wheelRows,
} from "./public/dom.mjs";

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

    it("takes a stream that says nothing for a while as lost, and keeps one that gets its keep-alives", () => {
        vi.useFakeTimers();
        let lost = 0;
        const p = createPage({
            EventSource: FakeSource,
            fetch: async () => ({ ok: true }),
            token: "t",
            id: "a",
            size: () => ({ cols: 80, rows: 24 }),
            theme: () => "dark",
            draw: () => undefined,
            lost: () => lost++,
            on: {},
        });
        p.connect();
        const first = FakeSource.opened[0];
        first.emit(FRAME);
        // The server's keep-alives keep it, however long nothing else comes.
        for (let i = 0; i < 12; i++) {
            vi.advanceTimersByTime(ALIVE_MS);
            first.emit({ type: "alive" });
        }
        expect(first.closed).toBe(false);
        expect(lost).toBe(0);
        // Silent from here, as a stream whose server was suspended: lost after one to two periods, then opened again.
        const until = (done) => {
            let waited = 0;
            while (!done() && waited < 10 * SILENT_MS) {
                vi.advanceTimersByTime(1000);
                waited += 1000;
            }
            return waited;
        };
        const silent = until(() => first.closed);
        expect(silent).toBeGreaterThanOrEqual(SILENT_MS);
        expect(silent).toBeLessThanOrEqual(2 * SILENT_MS);
        expect(lost).toBe(1);
        until(() => FakeSource.opened.length === 2);
        // One that never says anything is lost too, a period after it was opened.
        const never = until(() => FakeSource.opened[1].closed);
        expect(never).toBeLessThanOrEqual(SILENT_MS);
        expect(lost).toBe(2);
        until(() => FakeSource.opened.length === 3);
        // Stopped, it opens nothing more and looks for nothing.
        p.stop();
        vi.advanceTimersByTime(10 * SILENT_MS);
        expect(FakeSource.opened).toHaveLength(3);
        expect(lost).toBe(2);
    });

    it("never takes a stream for lost when its server sends no keep-alive events, as one from before them does", () => {
        vi.useFakeTimers();
        let lost = 0;
        const p = createPage({
            EventSource: FakeSource,
            fetch: async () => ({ ok: true }),
            token: "t",
            id: "a",
            size: () => ({ cols: 80, rows: 24 }),
            theme: () => "dark",
            draw: () => undefined,
            lost: () => lost++,
            on: {},
        });
        p.connect();
        FakeSource.opened[0].emit(FRAME);
        vi.advanceTimersByTime(10 * SILENT_MS);
        expect(FakeSource.opened[0].closed).toBe(false);
        expect(lost).toBe(0);
        p.stop();
    });

    it("counts on the keep-alive the server sends", () => {
        expect(SERVER_ALIVE_MS).toBe(ALIVE_MS);
        expect(SILENT_MS).toBeGreaterThanOrEqual(2 * ALIVE_MS);
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
                moved: (e) => seen.push(["moved", e.port]),
            },
        });
        p.connect();
        FakeSource.opened[0].emit({ type: "toast", text: "hi" });
        FakeSource.opened[0].emit({ type: "moved", port: 41234 });
        FakeSource.opened[0].emit({ type: "focus", key: "tab:hubs" });
        FakeSource.opened[0].emit({ type: "scroll", key: "row:3" });
        expect(seen).toEqual([
            ["toast", "hi"],
            ["moved", 41234],
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

describe("provesPane", () => {
    /** A server at `port` answering a challenge as the pane of the project with `token` would, as `kind`. */
    const answering =
        (token, port, kind = "pane") =>
        async (url, init) => {
            expect(url).toBe("/");
            const challenge = init.headers["x-knossos-challenge"];
            expect(challenge).toMatch(/^[0-9a-f]{32}$/);
            return new Response("refused", {
                status: 403,
                headers: {
                    "x-knossos-proof": proofOf(token, challenge, port, kind),
                },
            });
        };
    const TOKEN = "ab".repeat(24);

    it("takes a server whose answer proves it holds the token, on this very port, as the pane or its directions", async () => {
        const subtle = globalThis.crypto.subtle;
        expect(
            await provesPane(answering(TOKEN, 41231), subtle, TOKEN, 41231),
        ).toBe(true);
        expect(
            await provesPane(
                answering(TOKEN, 41231, "directions"),
                subtle,
                TOKEN,
                41231,
            ),
        ).toBe(true);
        // Another project's, an answer made for another port (one passed on from elsewhere), or none at all.
        expect(
            await provesPane(
                answering("cd".repeat(24), 41231),
                subtle,
                TOKEN,
                41231,
            ),
        ).toBe(false);
        expect(
            await provesPane(answering(TOKEN, 41232), subtle, TOKEN, 41231),
        ).toBe(false);
        expect(
            await provesPane(
                async () => new Response("", { status: 403 }),
                subtle,
                TOKEN,
                41231,
            ),
        ).toBe(false);
    });

    it("sends the token nowhere until the server proved itself, and tries again later when it cannot", async () => {
        vi.useFakeTimers();
        const fetch = vi.fn(async () => ({ ok: true }));
        let proven = false;
        const p = createPage({
            EventSource: FakeSource,
            fetch,
            token: "tok",
            id: "a",
            size: () => ({ cols: 80, rows: 24 }),
            theme: () => "dark",
            draw: () => undefined,
            prove: async () => proven,
            on: {},
        });
        p.connect();
        await vi.advanceTimersByTimeAsync(0);
        expect(FakeSource.opened).toHaveLength(0);
        await expect(p.post({ kind: "press", press: "x" })).rejects.toThrow(
            /not proven/,
        );
        expect(fetch).not.toHaveBeenCalled();
        proven = true;
        await vi.advanceTimersByTimeAsync(500);
        expect(FakeSource.opened).toHaveLength(1);
        await p.post({ kind: "press", press: "x" });
        expect(fetch).toHaveBeenCalledTimes(1);
        // Lost: nothing more with the token until the next proof.
        FakeSource.opened[0].fail();
        await expect(p.post({ kind: "press", press: "y" })).rejects.toThrow(
            /not proven/,
        );
        p.stop();
    });
});

describe("movedTo", () => {
    it("keeps the page's address but for the port, its token and view with it", () => {
        expect(movedTo(41234, "http://127.0.0.1:40000/?t=abc#pane")).toBe(
            "http://127.0.0.1:41234/?t=abc#pane",
        );
        expect(movedTo(41234, "http://localhost:40000/?t=abc")).toBe(
            "http://localhost:41234/?t=abc",
        );
    });

    it("goes nowhere for a port that is not one", () => {
        for (const port of [0, -1, 65536, 1.5, "41234", null, undefined])
            expect(movedTo(port, "http://127.0.0.1:40000/?t=abc")).toBeNull();
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

    it("turns a finger's drag into whole rows the way the wheel turns, up scrolling down", () => {
        expect(swipeRows(0, 200, 170, 20)).toEqual({ by: 1, carry: 10 });
        expect(swipeRows(10, 170, 160, 20)).toEqual({ by: 1, carry: 0 });
        expect(swipeRows(0, 100, 150, 20)).toEqual({ by: -2, carry: -10 });
        const short = swipeRows(0, 100, 105, 20);
        expect(short.by === 0 && short.carry === -5).toBe(true);
    });

    it("presses on release only over the press it went down on, whatever node draws it now", () => {
        expect(releasedOn("tab:hubs", "tab:hubs")).toBe("tab:hubs");
        expect(releasedOn("tab:hubs", "row:0")).toBeNull();
        expect(releasedOn(null, "tab:hubs")).toBeNull();
        expect(releasedOn(undefined, undefined)).toBeNull();
    });
});

describe("wheelCell", () => {
    it("counts a pinned body's rows from its first line, whatever it is scrolled to", () => {
        const cell = { width: 10, height: 20 };
        const box = { top: 100, left: 50 };
        expect(wheelCell(75, 145, box, cell, 0)).toEqual({ row: 2, column: 2 });
        expect(wheelCell(75, 145, box, cell, 200)).toEqual({
            row: 12,
            column: 2,
        });
    });
});

describe("wheelQueue", () => {
    const answer = (scrolled) => ({ json: async () => ({ scrolled }) });

    it("sends one at a time and folds what arrives meanwhile", async () => {
        const sent = [];
        const waiting = [];
        const scroll = wheelQueue(
            (at) => (sent.push(at), new Promise((r) => waiting.push(r))),
            () => undefined,
        );
        scroll(1, 3, 4);
        scroll(1, 5, 6);
        scroll(1, 7, 8);
        expect(sent).toEqual([{ row: 3, column: 4, by: 1 }]);
        waiting.shift()(answer(true));
        await vi.waitFor(() => expect(sent).toHaveLength(2));
        expect(sent[1]).toEqual({ row: 7, column: 8, by: 2 });
    });

    it("reports rows the pane did not scroll with, and forgets a failed send", async () => {
        const unscrolled = [];
        const scroll = wheelQueue(
            async () => answer(false),
            (by) => unscrolled.push(by),
        );
        scroll(3, 0, 0);
        await vi.waitFor(() => expect(unscrolled).toEqual([3]));
        const failing = wheelQueue(
            () => Promise.reject(new Error("down")),
            (by) => unscrolled.push(by),
        );
        failing(1, 0, 0);
        failing(1, 0, 0);
        for (let i = 0; i < 10; i++) await Promise.resolve();
        expect(unscrolled).toEqual([3]);
    });
});
