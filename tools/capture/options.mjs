/** Dev-only. shoot.mjs's command line. */
import path from "node:path";
import { SHOTS } from "./shots.mjs";
import { REPO } from "./worktree.mjs";

/**
 * `--only=a,b`, `--out=dir`, `--ref=<commit>` and `--plugin=dir`: every shot,
 * each shot's own place under docs/images (see outFor), HEAD and this
 * checkout's installed `.plugin` when left out. `--ref` is the commit the
 * throwaway worktree is made of (a branch ahead of the default one, for the
 * Branch tab); `--plugin` the plugin Claude Code loads, so a checkout with
 * none installed can shoot with another's.
 */
export function parseArgs(argv, shots = SHOTS) {
    let only = Object.keys(shots);
    let out = null;
    let ref = "HEAD";
    let plugin = path.join(REPO, ".plugin");
    for (const arg of argv) {
        const [key, value = ""] = arg.split(/=(.*)/s);
        if (key === "--only") {
            only = value.split(",").filter((s) => s !== "");
            const unknown = only.filter((s) => !Object.hasOwn(shots, s));
            if (unknown.length > 0)
                throw new Error(`unknown shot: ${unknown.join(", ")}`);
        } else if (key === "--out") {
            out = path.resolve(value);
        } else if (key === "--ref" && value !== "") {
            ref = value;
        } else if (key === "--plugin" && value !== "") {
            plugin = path.resolve(value);
        } else {
            throw new Error(`unknown option: ${arg}`);
        }
    }
    return { only, out, ref, plugin };
}

/** Where a shot's files go: `out` when given, else docs/images/web-client for the browser's and docs/images/claude-code for the rest. */
export const outFor = (shot, out) =>
    out ??
    path.join(
        REPO,
        "docs",
        "images",
        shot.kind === "browser" ? "web-client" : "claude-code",
    );
