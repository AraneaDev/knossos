/**
 * Dev-only. A browser shot: `knossos web` serving the throwaway worktree from
 * a copy of the stage, and its page in headless Chrome at the shot's width,
 * in the shot's colour scheme, kept as `<still>.png` once `match` is on the
 * page and the page has stopped changing. The server, its watcher and the
 * copy are gone however it ends.
 */
import { spawn } from "node:child_process";
import { copyFile, mkdir, mkdtemp, rm } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { clearTimeout, setTimeout } from "node:timers";
import { fileURLToPath } from "node:url";
import puppeteer from "puppeteer-core";
import { outFor } from "./options.mjs";
import {
    copyData,
    guardEnv,
    registerCleanup,
    stopWatchers,
} from "./session.mjs";
import { REPO } from "./worktree.mjs";

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const OUT = path.join(path.dirname(fileURLToPath(import.meta.url)), "out");

/** The address `knossos web` prints, token included; null until it has. */
export function addressOf(printed) {
    const found = /^Knossos web pane for .*: (http:\/\/\S+)$/m.exec(printed);
    return found === null ? null : found[1];
}

/**
 * Starts `knossos web <project> --no-open` in a process group of its own and
 * resolves with its address once printed. `stop` ends the whole group.
 */
export function startWeb(project, env, { timeoutMs = 60000 } = {}) {
    const child = spawn(
        path.join(REPO, "bin", "knossos"),
        ["web", project, "--no-open"],
        { env, detached: true, stdio: ["ignore", "pipe", "pipe"] },
    );
    const stop = async () => {
        try {
            process.kill(-child.pid, "SIGTERM");
        } catch {
            return;
        }
        for (let n = 0; n < 30 && child.exitCode === null; n += 1)
            await sleep(100);
        try {
            process.kill(-child.pid, "SIGKILL");
        } catch {
            // Gone.
        }
    };
    let printed = "";
    const address = new Promise((resolve, reject) => {
        const timer = setTimeout(
            () =>
                reject(new Error(`knossos web printed no address: ${printed}`)),
            timeoutMs,
        );
        const read = (chunk) => {
            printed += chunk;
            const url = addressOf(printed);
            if (url === null) return;
            clearTimeout(timer);
            resolve(url);
        };
        child.stdout.setEncoding("utf8").on("data", read);
        child.stderr.setEncoding("utf8").on("data", read);
        child.on("exit", (code) => {
            clearTimeout(timer);
            reject(new Error(`knossos web ended (${code}): ${printed}`));
        });
    });
    return { address, stop };
}

/** Waits until the page's text matches `match`, then until two reads 600 ms apart are the same. */
async function settled(tab, match, timeoutMs) {
    await tab.waitForFunction(
        (source) =>
            new RegExp(source, "m").test(globalThis.document.body.innerText),
        { timeout: timeoutMs },
        match,
    );
    let before = await tab.evaluate(() => globalThis.document.body.innerText);
    for (let n = 0; n < 25; n += 1) {
        await sleep(600);
        const now = await tab.evaluate(
            () => globalThis.document.body.innerText,
        );
        if (now === before) return;
        before = now;
    }
}

/**
 * Shoots one browser shot: `shot.size` is the viewport's [width, height] in
 * CSS pixels, the still is the whole page at that width (`scale` device
 * pixels a CSS pixel, 1 unless given). It is kept in `keep` (tools/capture/out)
 * under the shot's name, and copied to its place (see outFor).
 */
export async function shootPage(
    name,
    shot,
    {
        worktree,
        stage,
        out,
        run: runDir,
        home,
        start = startWeb,
        launch = (options) => puppeteer.launch(options),
        keep = OUT,
    },
) {
    const base = await mkdtemp(path.join(runDir, "knossos-capture-"));
    const removeBase = registerCleanup(() =>
        rm(base, { recursive: true, force: true }),
    );
    const dataCopy = path.join(base, "data");
    const env = {
        ...process.env,
        KNOSSOS_DATA_DIR: dataCopy,
        KNOSSOS_ROOTS_FILE: path.join(dataCopy, "roots.json"),
        CLAUDE_CONFIG_DIR: path.join(base, "config"),
        HOME: home,
    };
    guardEnv(env, {
        realDataDir: path.join(os.homedir(), ".knossos"),
        realConfigDir: path.join(os.homedir(), ".claude"),
        tempBase: runDir,
    });
    await copyData(stage, dataCopy);
    const web = start(worktree, env);
    const stopWeb = async () => {
        await web.stop();
        await stopWatchers(dataCopy);
    };
    const forgetWeb = registerCleanup(stopWeb);
    let browser = null;
    try {
        const url = await web.address;
        browser = await launch({
            executablePath: "/usr/bin/google-chrome",
            args: ["--no-sandbox"],
        });
        const tab = await browser.newPage();
        const [width, height] = shot.size;
        await tab.setViewport({
            width,
            height,
            deviceScaleFactor: shot.scale ?? 1,
        });
        await tab.emulateMediaFeatures([
            { name: "prefers-color-scheme", value: shot.theme },
        ]);
        await tab.goto(url);
        await settled(tab, shot.match, shot.timeoutMs ?? 60000);
        const dir = path.join(keep, name);
        await rm(dir, { recursive: true, force: true });
        await mkdir(dir, { recursive: true });
        const png = path.join(dir, `${shot.still}.png`);
        await tab.screenshot({ path: png, fullPage: true });
        const target = outFor(shot, out);
        await mkdir(target, { recursive: true });
        await copyFile(png, path.join(target, `${shot.still}.png`));
        process.stdout.write(
            `${shot.still}: ${path.join(target, `${shot.still}.png`)}\n`,
        );
    } finally {
        if (browser !== null) await browser.close();
        forgetWeb();
        await stopWeb();
        removeBase();
        await rm(base, { recursive: true, force: true });
    }
}
