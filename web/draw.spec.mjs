import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import { drawFrame } from "./public/draw.mjs";

const HERE = dirname(fileURLToPath(import.meta.url));

const COLOURS = {
    text: "#ffffff",
    inactive: "#999999",
    suggestion: "#b1b9f9",
    diffAdded: "#225c2b",
    diffRemoved: "#7a2936",
    ground: "#1e1e1e",
    card: "#373737",
    userMessageBackground: "#373737",
};

/** A frame of `body` rows, in the dark theme. */
const frameOf = (body, footer = []) => ({
    type: "frame",
    body,
    footer,
    pinned: false,
    theme: "dark",
    colours: COLOURS,
});

describe("drawFrame", () => {
    it("draws a pressable tab as a button with its id and hotkey", () => {
        const html = drawFrame(
            frameOf([
                {
                    key: "tabs",
                    segments: [
                        {
                            text: "",
                            hidden: true,
                            press: {
                                id: "tabkey:hubs",
                                label: "Hubs",
                                hotkey: "2",
                            },
                        },
                        {
                            text: "Hubs",
                            bg: "userMessageBackground",
                            press: { id: "tab:hubs", label: "Hubs" },
                        },
                    ],
                },
            ]),
        );
        expect(html).toContain('<div class="row"');
        expect(html).toMatch(
            /<button class="hidden" data-press="tabkey:hubs" data-hotkey="2" tabindex="-1"/,
        );
        expect(html).toMatch(
            /<button data-press="tab:hubs"[^>]*style="background:#373737"/,
        );
    });

    it("draws a hotkey's own cells in the accent, the rest of the label as it is", () => {
        const html = drawFrame(
            frameOf([
                {
                    key: "r",
                    segments: [
                        {
                            text: "r: rescan",
                            press: {
                                id: "rescan",
                                label: "rescan",
                                hotkey: "r",
                            },
                        },
                    ],
                },
            ]),
        );
        expect(html).toContain(
            '<span class="hot" style="color:#b1b9f9">r</span>: rescan',
        );
    });

    it("gives a dim segment the theme's inactive colour", () => {
        const html = drawFrame(
            frameOf([{ key: "a", segments: [{ text: "quiet", dim: true }] }]),
        );
        expect(html).toContain('<span style="color:#999999">quiet</span>');
    });

    it("draws a code row's added and removed lines on the diff grounds, numbered from the hunk", () => {
        const html = drawFrame(
            frameOf([
                {
                    key: "diff",
                    segments: [],
                    code: {
                        source: "@@ -3,2 +3,2 @@\n same\n-gone\n+came",
                        path: "a.php",
                    },
                },
            ]),
        );
        expect(html).toMatch(
            /<div class="row" style="background:#225c2b"><span class="gutter"[^>]*>4 <\/span>\+ came<\/div>/,
        );
        expect(html).toMatch(
            /<div class="row" style="background:#7a2936"><span class="gutter"[^>]*>4 <\/span>- gone<\/div>/,
        );
        expect(html).toMatch(/>3 <\/span> {2}same<\/div>/);
        // The hunk header is no row of its own: the gutter numbers the lines instead.
        expect(html).not.toContain("@@");
    });

    it("keeps text as text: a name with markup in it is escaped", () => {
        const html = drawFrame(
            frameOf([
                {
                    key: "x",
                    segments: [
                        { text: "<script>alert(1)</script>" },
                        {
                            text: 'a"b',
                            press: { id: 'open:"x"', label: "<b>" },
                        },
                    ],
                },
            ]),
        );
        expect(html).not.toContain("<script>");
        expect(html).toContain("&lt;script&gt;alert(1)&lt;/script&gt;");
        expect(html).toContain('data-press="open:&quot;x&quot;"');
        expect(html).toContain('title="&lt;b&gt;"');
    });

    it("draws a field as an input keyed by its id, taking the blank cells after it", () => {
        const html = drawFrame(
            frameOf([
                {
                    key: "f",
                    segments: [
                        { text: "find " },
                        {
                            text: "abc",
                            field: {
                                id: "finder",
                                value: "abc",
                                placeholder: "a name",
                            },
                        },
                        { text: "     " },
                        { text: "|" },
                    ],
                },
            ]),
        );
        expect(html).toContain(
            '<input data-field="finder" value="abc" placeholder="a name"',
        );
        expect(html).not.toContain("     ");
        expect(html).toContain("|</span>");
    });

    it("hangs a preview card off its segment, drawn from the card's rows on the card ground", () => {
        const html = drawFrame(
            frameOf([
                {
                    key: "p",
                    segments: [
                        {
                            text: "Hub",
                            preview: {
                                key: "card:hub",
                                width: 12,
                                rows: [
                                    {
                                        key: "c1",
                                        segments: [{ text: "inside" }],
                                    },
                                ],
                            },
                        },
                    ],
                },
            ]),
        );
        expect(html).toMatch(
            /<span class="hover"><span>Hub<\/span><div class="card" style="width:12ch;background:#373737">.*inside.*<\/div><\/span>/,
        );
    });

    it("draws a raster row from its segments' text", () => {
        const html = drawFrame(
            frameOf([
                {
                    key: "g",
                    raster: "heat",
                    segments: [
                        {
                            text: "▓▓",
                            color: "suggestion",
                            cell: { glyph: "█", fg: "heat-3" },
                        },
                    ],
                },
            ]),
        );
        expect(html).toContain('<span style="color:#b1b9f9">▓▓</span>');
    });

    it("draws the footer after the body, in its own place", () => {
        const html = drawFrame(
            frameOf(
                [{ key: "b", segments: [{ text: "body" }] }],
                [{ key: "f", segments: [{ text: "foot", bold: true }] }],
            ),
        );
        expect(html).toMatch(
            /<div class="pane-body">.*body.*<\/div><div class="pane-footer">.*font-weight:bold.*foot.*<\/div>$/,
        );
    });
});

describe("style.css", () => {
    it("states no colour: every colour comes from the frame", () => {
        const css = readFileSync(join(HERE, "public/style.css"), "utf8");
        expect(css).not.toMatch(/#[0-9a-f]{3,6}|rgb\(/i);
    });
});
