import {
    mkdir,
    mkdtemp,
    readdir,
    readFile,
    rm,
    writeFile,
} from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { describe, expect, it } from "vitest";
import { addressOf, shootPage } from "./page.mjs";
import { runCleanups } from "./session.mjs";
import { SHOTS } from "./shots.mjs";

describe("addressOf", () => {
    it("reads the address knossos web prints, token and all, and nothing before it", () => {
        expect(addressOf("Starting...\n")).toBeNull();
        expect(
            addressOf(
                "Knossos web pane for /t/home/knossos: http://127.0.0.1:41234/?t=ab12\nPress Ctrl-C to stop it.\n",
            ),
        ).toBe("http://127.0.0.1:41234/?t=ab12");
    });
});

/** A browser whose one tab records what it is asked, and whose wait fails when `fails`. */
function fakeBrowser({ fails = false } = {}) {
    const seen = { closed: 0 };
    const tab = {
        setViewport: async (v) => void (seen.viewport = v),
        emulateMediaFeatures: async (f) => void (seen.media = f),
        goto: async (url) => void (seen.url = url),
        waitForFunction: async (_fn, _options, match) => {
            seen.match = match;
            if (fails) throw new Error("waited in vain");
        },
        evaluate: async () => "the page",
        screenshot: async ({ path: file, fullPage }) => {
            seen.fullPage = fullPage;
            await writeFile(file, "png");
        },
    };
    return {
        seen,
        launch: async () => ({
            newPage: async () => tab,
            close: async () => void seen.closed++,
        }),
    };
}

describe("shootPage", () => {
    const setUp = async () => {
        const run = await mkdtemp(path.join(os.tmpdir(), "page-run-"));
        const stage = path.join(run, "stage");
        await mkdir(stage);
        await writeFile(path.join(stage, "roots.json"), '{"roots":[]}');
        const stopped = { count: 0 };
        const start = (project, env) => {
            stopped.project = project;
            stopped.env = env;
            return {
                address: Promise.resolve("http://127.0.0.1:1/?t=x"),
                stop: async () => void stopped.count++,
            };
        };
        return { run, stage, stopped, start };
    };

    it("opens the printed address at the shot's width in its colour scheme, and keeps the whole page as its still", async () => {
        const { run, stage, stopped, start } = await setUp();
        const browser = fakeBrowser();
        const out = path.join(run, "out");
        try {
            await shootPage("web-overview", SHOTS["web-overview"], {
                worktree: "/w/knossos",
                stage,
                out,
                run,
                home: path.join(run, "home"),
                start,
                launch: browser.launch,
                keep: path.join(run, "keep"),
            });
            expect(browser.seen.viewport).toEqual({
                width: 1440,
                height: 1360,
                deviceScaleFactor: 1,
            });
            expect(browser.seen.media).toEqual([
                { name: "prefers-color-scheme", value: "dark" },
            ]);
            expect(browser.seen.url).toBe("http://127.0.0.1:1/?t=x");
            expect(browser.seen.match).toBe(SHOTS["web-overview"].match);
            expect(browser.seen.fullPage).toBe(true);
            expect(await readFile(path.join(out, "overview.png"), "utf8")).toBe(
                "png",
            );
            // The server ran on a copy of the stage, never on the stage itself, and is stopped.
            expect(stopped.project).toBe("/w/knossos");
            expect(stopped.env.KNOSSOS_DATA_DIR).not.toBe(stage);
            expect(stopped.env.KNOSSOS_DATA_DIR.startsWith(run)).toBe(true);
            expect(stopped.count).toBe(1);
            expect(browser.seen.closed).toBe(1);
        } finally {
            await runCleanups();
            await rm(run, { recursive: true, force: true });
        }
    });

    it("stops the server and the browser, and leaves no copy, when the page never shows its text", async () => {
        const { run, stage, stopped, start } = await setUp();
        const browser = fakeBrowser({ fails: true });
        try {
            await expect(
                shootPage("web-overview", SHOTS["web-overview"], {
                    worktree: "/w/knossos",
                    stage,
                    out: path.join(run, "out"),
                    run,
                    home: path.join(run, "home"),
                    start,
                    launch: browser.launch,
                    keep: path.join(run, "keep"),
                }),
            ).rejects.toThrow(/waited in vain/);
            expect(stopped.count).toBe(1);
            expect(browser.seen.closed).toBe(1);
            expect(
                (await readdir(run)).filter((f) =>
                    f.startsWith("knossos-capture-"),
                ),
            ).toEqual([]);
        } finally {
            await runCleanups();
            await rm(run, { recursive: true, force: true });
        }
    });
});
