#!/usr/bin/env node
/**
 * Dev-only. The creation brief, checked against history.
 *
 * For each recent commit that added a source file, the repository is put at
 * the commit before it, scanned, and asked for the brief of every file the
 * commit was about to add. The commit itself is the answer key: the files it
 * changed (did the brief's "goes with" name them?), the test it added (did
 * the brief predict its path?), and the folder facts after it (did the new
 * file follow the conventions the brief stated?).
 *
 *   node tools/eval/backtest.mjs <repo> [--commits 20] [--out DIR] [--json]
 *
 * The repository is cloned into the work directory first; the original is
 * only read. Scans go into a data directory of their own, never the user's.
 * No model is involved, so the same history gives the same numbers.
 */
import { execFile } from "node:child_process";
import { mkdir, mkdtemp, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { parseArgs, promisify } from "node:util";

const run = promisify(execFile);
const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");

/** The extensions a language worker scans: a commit counts when it adds one of these. */
export const SCANNED = new Set(["php", "ts", "tsx", "js", "jsx", "mjs", "cjs", "py", "rs"]);

/** A commit that adds more files than this is a bulk change, not someone adding a file. */
export const MAX_ADDED = 30;

/** The key in a folder's `kinds` facts that each `kind` convention text counts. */
const KIND_KEYS = {
    "one class per file": "one_class",
    "one interface per file": "one_interface",
    "one trait per file": "one_trait",
    "one enum per file": "one_enum",
    "functions only": "functions_only",
};

/** The part of a file name after its last dot, lower case; empty without one. */
export const extensionOf = (file) => {
    const base = file.slice(file.lastIndexOf("/") + 1);
    const dot = base.lastIndexOf(".");
    return dot <= 0 ? "" : base.slice(dot + 1).toLowerCase();
};

/**
 * The commits of a `git log --format=%x00%H --name-status` listing, as
 * `{ hash, added, changed }`: `added` the files it added, `changed` every
 * other path it touched.
 */
export function parseLog(output) {
    const commits = [];
    for (const block of output.split("\0").slice(1)) {
        const [hash, ...lines] = block.split("\n");
        const added = [];
        const changed = [];
        for (const line of lines) {
            const tab = line.indexOf("\t");
            if (tab < 1) continue;
            (line[0] === "A" ? added : changed).push(line.slice(tab + 1));
        }
        commits.push({ hash: hash.trim(), added, changed });
    }
    return commits;
}

/** The newest `limit` commits worth replaying, oldest first: each adds a scanned file and is not a bulk change. */
export function pickCommits(commits, limit) {
    return commits
        .filter((c) => c.added.length <= MAX_ADDED && c.added.some((f) => SCANNED.has(extensionOf(f))))
        .slice(0, limit)
        .reverse();
}

/** How many files of a folder's population hold the fact a shown convention counts, from its `facts`. */
export function countFor(convention, facts) {
    if (facts === null) return 0;
    const { id, text } = convention;
    if (id === "kind") return facts.kinds?.[KIND_KEYS[text]] ?? 0;
    if (id.startsWith("modifier:")) return facts.modifiers?.[id.slice("modifier:".length)] ?? 0;
    if (id === "suffix") return `named *${facts.suffix?.value}` === text ? facts.suffix.files : 0;
    for (const key of ["injects", "uses"]) {
        if (id.startsWith(`${key}:`)) {
            return (facts[key] ?? []).find((entry) => entry.type === id.slice(key.length + 1))?.files ?? 0;
        }
    }
    return 0;
}

/**
 * How the files one commit added to one folder population did against the
 * conventions the brief showed for it: per convention, how many of the
 * `added` new files hold the fact, read off the folder's counts before and
 * after. `lines` is a description, not a rule, and is left out.
 */
export function conventionScore(conventions, before, after, added) {
    return conventions
        .filter((c) => c.id !== "lines")
        .map((c) => ({
            id: c.id,
            text: c.text,
            shown: added,
            followed: Math.max(0, Math.min(added, countFor(c, after) - countFor(c, before))),
        }));
}

/** One file's "goes with" against the commit: which predicted paths the commit really touched. */
export function goesWithScore(brief, commit) {
    const touched = new Set([...commit.added, ...commit.changed]);
    const companions = brief.goes_with.filter((g) => g.kind === "companion").map((g) => g.path);
    const test = brief.goes_with.find((g) => g.kind === "test")?.path ?? null;
    const others = commit.changed.filter((p) => p !== brief.path);
    return {
        companions: companions.length,
        companionHits: companions.filter((p) => touched.has(p)).length,
        changedOthers: others.length,
        changedOthersNamed: others.filter((p) => companions.includes(p)).length,
        test: test === null ? 0 : 1,
        testHit: test !== null && touched.has(test) ? 1 : 0,
    };
}

/** The totals of a replay: every count summed, with the shares a reader wants beside them. */
export function summarise(rows) {
    const sum = (key) => rows.reduce((n, r) => n + (r[key] ?? 0), 0);
    const conventions = new Map();
    for (const row of rows) {
        for (const c of row.conventions ?? []) {
            const kind = c.id.split(":")[0];
            const at = conventions.get(kind) ?? { shown: 0, followed: 0 };
            conventions.set(kind, { shown: at.shown + c.shown, followed: at.followed + c.followed });
        }
    }
    const share = (hits, of) => (of === 0 ? null : Math.round((1000 * hits) / of) / 10);
    return {
        files: rows.length,
        briefed: rows.filter((r) => r.basis).length,
        borrowed: rows.filter((r) => r.borrowed).length,
        empty: rows.filter((r) => !r.basis && r.companions === 0 && r.test === 0).length,
        test: { predicted: sum("test"), hit: sum("testHit"), precision: share(sum("testHit"), sum("test")) },
        companions: {
            predicted: sum("companions"),
            hit: sum("companionHits"),
            precision: share(sum("companionHits"), sum("companions")),
            otherFilesChanged: sum("changedOthers"),
            recall: share(sum("changedOthersNamed"), sum("changedOthers")),
        },
        conventions: Object.fromEntries(
            [...conventions].sort(([a], [b]) => a.localeCompare(b)).map(([kind, c]) => [kind, { ...c, followedShare: share(c.followed, c.shown) }]),
        ),
    };
}

/** A git command in `dir`, its stdout. */
async function git(dir, args) {
    return (await run("git", ["-C", dir, ...args], { maxBuffer: 256 * 1024 * 1024 })).stdout;
}

/** A knossos command against the scratch data directory, its parsed JSON answer. */
async function knossos(env, args) {
    const { stdout } = await run(path.join(REPO_ROOT, "bin/knossos"), [...args, "--json"], { env, maxBuffer: 256 * 1024 * 1024 });
    return JSON.parse(stdout);
}

/** One folder population's stored facts, or null when it has no row. */
async function factsOf(database, project, { directory, extension, role }) {
    const quote = (value) => `'${value.replaceAll("'", "''")}'`;
    const sql = `SELECT facts_json FROM folder_facts WHERE project_id = ${quote(project)} AND directory = ${quote(directory)} AND extension = ${quote(extension)} AND role = ${quote(role)}`;
    const { stdout } = await run("sqlite3", [`file:${database}?mode=ro`, sql]);
    return stdout.trim() === "" ? null : JSON.parse(stdout);
}

/** Replays `limit` commits of the repository at `source`, returning one row per file a commit added. */
export async function replay(source, limit, work, log = () => {}) {
    const clone = path.join(work, "clone");
    const data = path.join(work, "data");
    await mkdir(data, { recursive: true });
    await run("git", ["clone", "--quiet", "--no-hardlinks", source, clone]);
    const env = { ...process.env, KNOSSOS_DATA_DIR: data, KNOSSOS_ALLOWED_ROOTS: clone };
    delete env.KNOSSOS_ROOTS_FILE;
    const database = path.join(data, "knossos.sqlite");
    const history = parseLog(await git(clone, ["log", "--no-merges", "--no-renames", "--format=%x00%H", "--name-status", "-n", "2000"]));
    const commits = pickCommits(history, limit);
    const rows = [];
    for (const [index, commit] of commits.entries()) {
        log(`${index + 1}/${commits.length} ${commit.hash.slice(0, 8)} adds ${commit.added.length}`);
        await git(clone, ["checkout", "--quiet", "--detach", `${commit.hash}^`]);
        const project = (await knossos(env, ["scan", clone])).project_id;
        const briefs = [];
        for (const file of commit.added.filter((f) => SCANNED.has(extensionOf(f)))) {
            const brief = (await knossos(env, ["creation-brief", clone, file])).data;
            briefs.push({ brief, before: await factsOf(database, project, brief) });
        }
        await git(clone, ["checkout", "--quiet", "--detach", commit.hash]);
        await knossos(env, ["scan", clone]);
        // Conventions are scored once per folder population: its counts move by every file the commit added there.
        const groups = new Map();
        for (const entry of briefs) {
            const key = [entry.brief.directory, entry.brief.extension, entry.brief.role].join("\0");
            groups.set(key, [...(groups.get(key) ?? []), entry]);
        }
        for (const group of groups.values()) {
            const [{ brief, before }] = group;
            const after = await factsOf(database, project, brief);
            const scored = conventionScore(brief.conventions, before, after, group.length);
            for (const [at, entry] of group.entries()) {
                rows.push({
                    commit: commit.hash.slice(0, 8),
                    path: entry.brief.path,
                    role: entry.brief.role,
                    basis: entry.brief.basis !== null,
                    borrowed: entry.brief.basis?.borrowed === true,
                    conventions: at === 0 ? scored : [],
                    ...goesWithScore(entry.brief, commit),
                });
            }
        }
    }
    return rows;
}

/** The summary as the lines a person reads. */
export function render(name, summary) {
    const pct = (value) => (value === null ? "n/a" : `${value}%`);
    const lines = [
        `${name}: ${summary.files} added files replayed, ${summary.briefed} with a folder to learn from (${summary.borrowed} borrowed), ${summary.empty} with nothing to say`,
        `  expected test: predicted ${summary.test.predicted}, the commit touched it ${summary.test.hit} (${pct(summary.test.precision)})`,
        `  goes with: predicted ${summary.companions.predicted}, the commit touched ${summary.companions.hit} (${pct(summary.companions.precision)}); of ${summary.companions.otherFilesChanged} other files the commits changed, ${pct(summary.companions.recall)} were named`,
    ];
    for (const [kind, c] of Object.entries(summary.conventions)) {
        lines.push(`  convention ${kind}: shown for ${c.shown} new files, followed by ${c.followed} (${pct(c.followedShare)})`);
    }
    return lines.join("\n");
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    const { values, positionals } = parseArgs({
        allowPositionals: true,
        options: { commits: { type: "string", default: "20" }, out: { type: "string" }, json: { type: "boolean", default: false } },
    });
    if (positionals.length !== 1) {
        console.error("usage: node tools/eval/backtest.mjs <repo> [--commits 20] [--out DIR] [--json]");
        process.exit(2);
    }
    const source = path.resolve(positionals[0]);
    const work = await mkdtemp(path.join(os.tmpdir(), "knossos-backtest-"));
    try {
        const rows = await replay(source, Number(values.commits), work, (line) => console.error(line));
        const summary = summarise(rows);
        if (values.out !== undefined) {
            await mkdir(values.out, { recursive: true });
            await writeFile(path.join(values.out, `${path.basename(source)}.json`), JSON.stringify({ source, summary, rows }, null, 2));
        }
        console.log(values.json ? JSON.stringify(summary, null, 2) : render(path.basename(source), summary));
    } finally {
        await rm(work, { recursive: true, force: true });
    }
}
