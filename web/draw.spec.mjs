import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import {
    cardOffset,
    drawCards,
    drawFrame,
    drawRows,
    patchPlan,
    rowHtml,
} from "./public/draw.mjs";

const HERE = dirname(fileURLToPath(import.meta.url));

/** A frame of ready `body` rows, in the dark theme. */
const frameOf = (body, footer = []) => ({
    type: "frame",
    body,
    footer,
    pinned: false,
    theme: "dark",
    colours: {
        ground: "#1e1e1e",
        text: "#ffffff",
        card: "#373737",
        suggestion: "#b1b9f9",
    },
});

/** A ready segment: its text in no look of its own unless given one. */
const seg = (text, more = {}) => ({ text, look: {}, ...more });

describe("rowHtml", () => {
    it("draws a press as a button with its id, hotkey and full name, a hidden twin out of sight", () => {
        const html = rowHtml({
            key: "tabs",
            segments: [
                seg("", {
                    hidden: true,
                    press: {
                        id: "tabkey:hubs",
                        label: "",
                        title: "Hubs",
                        hotkey: "2",
                    },
                }),
                seg("Hubs", {
                    look: { background: "#373737" },
                    press: { id: "tab:hubs", label: "Hubs", title: "Hubs" },
                }),
            ],
        });
        expect(html).toMatch(/^<div class="row" data-key="tabs">/);
        expect(html).toContain(
            '<button class="hidden" data-press="tabkey:hubs" data-hotkey="2" tabindex="-1" aria-label="Hubs"></button>',
        );
        expect(html).toContain(
            '<button data-press="tab:hubs" title="Hubs" style="background:#373737">Hubs</button>',
        );
    });

    it("draws a hotkey before the label it is given, the hotkey in the accent", () => {
        const html = rowHtml({
            key: "r",
            segments: [
                seg("r: resc…", {
                    dim: true,
                    press: {
                        id: "rescan",
                        label: "resc…",
                        title: "rescan",
                        hotkey: "r",
                        dim: true,
                    },
                }),
            ],
        });
        expect(html).toContain(
            '<button class="dim" data-press="rescan" data-hotkey="r" title="rescan"><span class="hot">r</span>: resc…</button>',
        );
    });

    it("draws each segment in the look it is given, and decides none itself", () => {
        const html = rowHtml({
            key: "a",
            segments: [
                seg("dim", { dim: true, look: { color: "#999999" } }),
                seg("plain"),
                seg("bold", { look: { bold: true } }),
            ],
        });
        expect(html).toContain('<span style="color:#999999">dim</span>');
        expect(html).toContain("<span>plain</span>");
        expect(html).toContain('<span style="font-weight:bold">bold</span>');
    });

    it("draws a field as an input keyed by its id, as wide as its room", () => {
        const html = rowHtml({
            key: "find",
            segments: [
                seg("find "),
                seg("", {
                    field: {
                        id: "finder",
                        value: 'a"b',
                        placeholder: "a name",
                        width: 23,
                    },
                }),
            ],
        });
        expect(html).toContain(
            '<input data-field="finder" value="a&quot;b" placeholder="a name" spellcheck="false" autocomplete="off" style="width:23ch">',
        );
    });

    it("draws a diff row as its numbered lines on their grounds", () => {
        const html = rowHtml({
            key: "diff-0",
            segments: [],
            gutter: { color: "#999999" },
            lines: [
                {
                    gutter: " 9",
                    text: "+ added",
                    look: { background: "#225c2b" },
                },
                { gutter: "10", text: "  kept", look: {} },
            ],
        });
        expect(html).toBe(
            '<div class="code" data-key="diff-0"><div class="line" style="background:#225c2b"><span class="gutter" style="color:#999999"> 9 </span>+ added</div><div class="line"><span class="gutter" style="color:#999999">10 </span>  kept</div></div>',
        );
    });

    it("keeps text as text: a name with markup in it is escaped", () => {
        const html = rowHtml({
            key: "<k>",
            segments: [seg("<script>alert(1)</script>")],
        });
        expect(html).not.toContain("<script>");
        expect(html).toContain("&lt;script&gt;alert(1)&lt;/script&gt;");
        expect(html).toContain('data-key="&lt;k&gt;"');
    });
});

describe("cards", () => {
    const rows = [
        {
            key: "hub-0",
            segments: [
                seg("Router", {
                    card: {
                        key: "card-0",
                        width: 30,
                        lines: ["Router", "12 dependents"],
                        at: { row: 0, column: 0 },
                        place: { row: 1, column: 0 },
                    },
                }),
            ],
        },
    ];

    it("marks the segment a card hangs from, and draws the card apart, in the cards' layer, not inside its row", () => {
        const row = rowHtml(rows[0]);
        expect(row).toBe(
            '<div class="row" data-key="hub-0"><span class="hover" data-card="card-0"><span>Router</span></span></div>',
        );
        expect(drawCards(rows)).toEqual([
            {
                key: "card-0",
                html: '<div class="card" data-card="card-0" data-at="0,0" data-place="1,0" style="width:30ch"><div class="line">Router</div><div class="line">12 dependents</div></div>',
            },
        ]);
        const html = drawFrame(frameOf(rows));
        expect(html).toMatch(
            /<div class="cards"><div class="card" data-card="card-0"/,
        );
    });

    it("carries the cell the frame placed a card in, and turns the distance from its segment into pixels", () => {
        const html = drawCards([
            {
                key: "hub-0",
                segments: [
                    seg("Router", {
                        card: {
                            key: "card-0",
                            width: 30,
                            lines: ["Router"],
                            at: { row: 9, column: 4 },
                            place: { row: 7, column: 2 },
                        },
                    }),
                ],
            },
        ])[0].html;
        expect(html).toContain('data-at="9,4" data-place="7,2"');
        // Two rows up and two columns left of the segment's own box.
        expect(
            cardOffset(
                { top: 200, left: 100 },
                { at: "9,4", place: "7,2" },
                { width: 8, height: 18 },
            ),
        ).toEqual({ top: 164, left: 84 });
    });
});

describe("patchPlan", () => {
    it("reuses every node whose row is as it was, draws the changed and the new ones, in the new order", () => {
        const old = drawRows([
            { key: "a", segments: [seg("one")] },
            { key: "b", segments: [seg("two")] },
            { key: "c", segments: [seg("three")] },
        ]);
        const next = drawRows([
            { key: "a", segments: [seg("one")] },
            { key: "c", segments: [seg("three, changed")] },
            { key: "d", segments: [seg("four")] },
        ]);
        expect(
            patchPlan(old, next).map(({ key, reuse }) => [key, reuse]),
        ).toEqual([
            ["a", true],
            ["c", false],
            ["d", false],
        ]);
    });

    it("gives a key seen twice a key of its own, so each row is one node", () => {
        const twice = drawRows([
            { key: "x", segments: [seg("1")] },
            { key: "x", segments: [seg("2")] },
        ]);
        expect(twice.map((e) => e.key)).toEqual(["x", "x#2"]);
        expect(patchPlan(twice, twice).every((step) => step.reuse)).toBe(true);
    });
});

describe("drawFrame", () => {
    it("draws the body, then the footer, each in its own place", () => {
        const html = drawFrame(
            frameOf(
                [{ key: "b", segments: [seg("body")] }],
                [{ key: "f", segments: [seg("foot")] }],
            ),
        );
        expect(html).toBe(
            '<div class="pane-body"><div class="row" data-key="b"><span>body</span></div></div><div class="pane-footer"><div class="row" data-key="f"><span>foot</span></div></div><div class="cards"></div>',
        );
    });
});

describe("style.css", () => {
    const css = readFileSync(join(HERE, "public/style.css"), "utf8");

    it("states no colour: every colour comes from the frame", () => {
        expect(css).not.toMatch(/#[0-9a-f]{3,6}|rgb\(/i);
    });

    it("draws cards in a layer of their own, which no row's clipping reaches", () => {
        expect(css).toMatch(/#cards > \.card \{[^}]*position: fixed/);
        expect(css).not.toMatch(/\.hover > \.card/);
    });
});
