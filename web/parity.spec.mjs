import { join } from "node:path";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { mod, reset } from "../hooks/mod/state";
import {
    fixtureDashboard,
    fixturePluginRoot,
    scratch,
} from "./fixtures/helpers";
import { Hub } from "./hub";
import { webPort } from "./port";
import { drawFrame } from "./public/draw.mjs";

// The shared layout, made to return whatever a spec gives it: a card or row no web code has heard of.
const laid = vi.hoisted(() => ({ next: null }));
vi.mock("../hooks/lib/layout", async (original) => {
    const real = await original();
    return {
        ...real,
        paneLayout: (...args) => laid.next ?? real.paneLayout(...args),
    };
});

beforeEach(() => {
    reset({ watch: false });
    mod.host = "web";
    laid.next = null;
});

describe("the web host draws whatever the shared layout lays out", () => {
    it("sends a row and a card it has never seen to the page, and the page draws both", async () => {
        laid.next = {
            body: [
                {
                    key: "orbit-head",
                    segments: [
                        { text: "Orbit", bold: true, color: "claude" },
                        { text: " · " },
                        {
                            text: "o: spin",
                            press: {
                                id: "orbit:spin",
                                label: "spin",
                                hotkey: "o",
                            },
                        },
                        {
                            text: " moons",
                            dim: true,
                            preview: {
                                key: "card-orbit",
                                width: 24,
                                rows: [
                                    {
                                        key: "c",
                                        segments: [{ text: "3 moons, 1 ring" }],
                                    },
                                ],
                            },
                        },
                    ],
                },
            ],
            footer: [],
            pinned: false,
        };
        const hub = new Hub();
        const io = webPort({
            root: "/work/app",
            pluginRoot: fixturePluginRoot(),
            storeFile: join(scratch(), "store.json"),
            hub,
        });
        hub.attach(io);
        await io.state.dashboard.update(() => fixtureDashboard("/work/app"));
        const frames = [];
        hub.connect("a", { columns: 100, rows: 30 }, (event) => {
            if (event.type === "frame") frames.push(event);
        });
        await vi.waitFor(() => expect(frames).toHaveLength(1));
        const [frame] = frames;
        expect(frame.body.map((row) => row.key)).toEqual(["orbit-head"]);
        const html = drawFrame(frame);
        expect(html).toContain('data-key="orbit-head"');
        expect(html).toMatch(/font-weight:bold">Orbit</);
        expect(html).toContain(
            'data-press="orbit:spin" data-hotkey="o" title="spin"',
        );
        expect(html).toContain('data-card="card-orbit"');
        // Placed by the shared rule: the card's cell is the row below its segment's.
        expect(html).toMatch(
            /<div class="card" data-card="card-orbit" data-at="0,(\d+)" data-place="1,\1" style="width:24ch"><div class="line">3 moons, 1 ring<\/div><\/div>/,
        );
    });
});
