/**
 * Dev-only. What an eval run measures: the Knossos commit it ran against,
 * whether that checkout had local changes, and the plugin's version and
 * contents, so rows from two builds are never pooled.
 */
import { createHash } from "node:crypto";
import { execFile } from "node:child_process";
import { readdir, readFile } from "node:fs/promises";
import path from "node:path";
import { promisify } from "node:util";
import { readJson } from "./config.mjs";

const exec = promisify(execFile);

// Run output and hand-edited answer keys: neither changes the code under test.
const NOT_DIRTY = [".superpowers/", "tools/eval/corpus/"];

/** The paths `git status --porcelain` lists that make a checkout dirty, rename targets included. */
export function dirtyPaths(porcelain) {
    return porcelain
        .split("\n")
        .filter((line) => line.trim() !== "")
        .map((line) => line.slice(3).split(" -> ").at(-1).replace(/^"|"$/g, ""))
        .filter((p) => !NOT_DIRTY.some((prefix) => p.startsWith(prefix)));
}

/** The `version` in the materialised plugin's manifest, or null. */
export async function pluginVersion(pluginDir) {
    try {
        const { version } = await readJson(
            path.join(pluginDir, ".claude-plugin", "plugin.json"),
        );
        return typeof version === "string" ? version : null;
    } catch {
        return null;
    }
}

/** Every file under `dir`, as paths relative to it, sorted; none when it is missing. */
export async function filesUnder(dir) {
    const entries = await readdir(dir, {
        recursive: true,
        withFileTypes: true,
    }).catch(() => []);
    return entries
        .filter((entry) => entry.isFile())
        .map((entry) =>
            path.relative(
                dir,
                path.join(entry.parentPath ?? entry.path, entry.name),
            ),
        )
        .sort();
}

/**
 * A hash of everything the materialised plugin holds, or null when there is
 * none. Its version alone cannot tell a reinstall at the same version, which
 * changes what the full arm runs.
 */
export async function pluginHash(pluginDir) {
    const files = await filesUnder(pluginDir);
    if (files.length === 0) return null;
    const hash = createHash("sha256");
    for (const file of files) {
        hash.update(`${file}\0`);
        hash.update(await readFile(path.join(pluginDir, file)));
        hash.update("\0");
    }
    return hash.digest("hex");
}

/** What a run measures: the Knossos commit, whether it had local changes, and the plugin version and contents. */
export async function provenance(s) {
    const git = async (...args) =>
        (await exec("git", ["-C", s.sourceRoot, ...args])).stdout;
    let knossosCommit = null;
    let knossosDirty = null;
    try {
        knossosCommit = (await git("rev-parse", "HEAD")).trim();
        knossosDirty =
            dirtyPaths(await git("status", "--porcelain")).length > 0;
    } catch {
        // Not a git checkout: the commit is unknown, and recorded as such.
    }
    return {
        knossosCommit,
        knossosDirty,
        pluginVersion: await pluginVersion(s.pluginDir),
        pluginHash: await pluginHash(s.pluginDir),
    };
}

/**
 * Throws when this checkout or its plugin differs from what the run started
 * with, since rows from two builds must not be pooled. A run started before
 * provenance was recorded only gets a warning.
 */
export function checkProvenance(meta, current) {
    if (!("knossosCommit" in meta)) {
        console.warn(
            `warning: run ${meta.runId} recorded no provenance, so a change of Knossos commit or plugin version cannot be detected`,
        );
        return;
    }
    const changed = [];
    if (meta.knossosCommit !== current.knossosCommit)
        changed.push(
            `knossos commit ${meta.knossosCommit} -> ${current.knossosCommit}`,
        );
    if (meta.pluginVersion !== current.pluginVersion)
        changed.push(
            `plugin version ${meta.pluginVersion} -> ${current.pluginVersion}`,
        );
    // A run started before the hash was recorded has none to compare, and a
    // missing plugin is the prepare check's to report.
    else if (
        "pluginHash" in meta &&
        current.pluginHash !== null &&
        meta.pluginHash !== current.pluginHash
    )
        changed.push("plugin contents changed at the same version");
    if (changed.length > 0)
        throw new Error(
            `run ${meta.runId} started on a different build (${changed.join("; ")}); start a new run, or pass --force-provenance to pool the sessions anyway`,
        );
}
