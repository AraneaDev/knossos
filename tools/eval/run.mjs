#!/usr/bin/env node
/**
 * Dev-only. The agent eval: the same structural questions answered by Claude
 * Code without Knossos, with its tools, and with its whole plugin, run in
 * small batches that stop before the five-hour usage window runs out.
 *
 *   node tools/eval/run.mjs start [--tier easy|hard] [--repeats 3] ...   plan a run, print its id
 *   node tools/eval/run.mjs prepare <run-id>          clone, scan, seed config
 *   node tools/eval/run.mjs continue <run-id>         run one batch (exit 3: stopped at the limit)
 *   node tools/eval/run.mjs ablate <run-id>           add full-minus-<tool> arms
 *   node tools/eval/run.mjs status <run-id>
 *   node tools/eval/report.mjs <run-id>               write report.md
 */
import { createHash, randomUUID } from "node:crypto";
import { execFile } from "node:child_process";
import {
    access,
    link,
    mkdir,
    readdir,
    readFile,
    rename,
    rm,
    stat,
    writeFile,
} from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { parseArgs, promisify } from "node:util";
import { stopWatchers } from "../capture/session.mjs";
import { ANSWER_INSTRUCTION } from "./answer.mjs";
import { ablationArm, armArgv, BASE_ARMS, parseArm } from "./arms.mjs";
import {
    CORPUS_HASH_VERSION,
    corpusHash,
    corpusHashFor,
    plannedCorpus,
    TIERS,
    tierOf,
    loadCorpus,
} from "./corpus.mjs";
import {
    ensureClone,
    evalEnv,
    evalPaths,
    resetWorkspace,
    seedConfig,
    writeMcpConfig,
} from "./env.mjs";
import {
    appendRow,
    makePlan,
    pending,
    runnable,
    readLedger,
    sessionKey,
} from "./ledger.mjs";
import { buildRow, runSession, terminateGroups } from "./session.mjs";
import { selectAblations } from "./stats.mjs";
import { parseTranscript } from "./transcript.mjs";

export const REPO_ROOT = fileURLToPath(new URL("../..", import.meta.url));
const exec = promisify(execFile);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export function settings(env = process.env) {
    return {
        runsDir:
            env.KNOSSOS_EVAL_RUNS ??
            path.join(
                os.homedir(),
                ".claude",
                "superpowers",
                "Knossos-MCP",
                "evals",
            ),
        // Outside the home directory: Claude Code loads memory from every ancestor of its cwd.
        evalRoot: env.KNOSSOS_EVAL_ROOT ?? "/var/tmp/knossos-eval",
        pluginDir:
            env.KNOSSOS_EVAL_PLUGIN_DIR ?? path.join(REPO_ROOT, ".plugin"),
        claudeBin: env.KNOSSOS_EVAL_CLAUDE ?? "claude",
        // Clears, keeps and restores a project's annotations between sessions; tests use a fake.
        notesBin:
            env.KNOSSOS_EVAL_NOTES ??
            path.join(REPO_ROOT, "tools", "eval", "annotations.php"),
        // Where the real credentials are copied from; tests point it at an empty dir.
        realHome: env.KNOSSOS_EVAL_REAL_HOME ?? os.homedir(),
        // The Knossos checkout whose commit a run records; tests point it at a throwaway repository.
        sourceRoot: env.KNOSSOS_EVAL_SOURCE_ROOT ?? REPO_ROOT,
        env,
    };
}

const readJson = async (file) => JSON.parse(await readFile(file, "utf8"));
const writeJson = (file, value) =>
    writeFile(file, `${JSON.stringify(value, null, 2)}\n`);

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
async function filesUnder(dir) {
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

/** A positive finite number from a flag, or a clear error naming the flag. */
export function positive(
    name,
    value,
    { integer = false, max = Infinity } = {},
) {
    const n = Number(value);
    const kind = integer ? "a positive integer" : "a positive number";
    const limit = max === Infinity ? "" : ` of at most ${max}`;
    if (
        !Number.isFinite(n) ||
        n <= 0 ||
        n > max ||
        (integer && !Number.isInteger(n))
    )
        throw new Error(`--${name} must be ${kind}${limit}, got "${value}"`);
    return n;
}

const alive = (pid) => {
    try {
        process.kill(pid, 0);
        return true;
    } catch (error) {
        return error.code === "EPERM";
    }
};

/** A takeover mutex older than this belonged to a process that died holding it. */
const TAKEOVER_STALE_MS = 5000;

/**
 * Holds `${file}.takeover` (an atomic mkdir) while `fn` runs; null when another
 * process holds it. `fn` gets `stillOwner()`: a peer may break the mutex of a
 * process that was suspended for longer than `staleMs`, and that process must
 * notice before it removes anything. A mutex dated more than `staleMs` in the
 * future is stale too. A stale mutex is broken by renaming it to a name of its
 * own, so of several peers only one rename succeeds, and the mover checks that
 * what it moved really was stale and puts a fresh one back.
 */
async function withTakeover(file, staleMs, hooks, fn) {
    const mutex = `${file}.takeover`;
    const token = randomUUID();
    try {
        await mkdir(mutex);
    } catch (error) {
        if (error.code !== "EEXIST") throw error;
        const ageOf = () =>
            stat(mutex)
                .then((st) => Date.now() - st.mtimeMs)
                .catch(() => 0);
        // A little clock skew between the file system and Date.now() is normal; only a clearly future date is stale.
        if (Math.abs(await ageOf()) <= staleMs) {
            await sleep(10);
            return null;
        }
        await hooks.beforeBreak?.();
        const moved = `${mutex}.broken-${process.pid}-${randomUUID()}`;
        try {
            await rename(mutex, moved);
        } catch (error) {
            // Another peer broke it first, or its owner released it.
            if (error.code === "ENOENT") return null;
            throw error;
        }
        const movedAge = await stat(moved)
            .then((st) => Date.now() - st.mtimeMs)
            .catch(() => Infinity);
        // What was moved was a fresh mutex after all: give it back to its owner, who checks it still owns it.
        if (Math.abs(movedAge) <= staleMs)
            await rename(moved, mutex).catch(() =>
                rm(moved, { recursive: true, force: true }),
            );
        else await rm(moved, { recursive: true, force: true });
        return null;
    }
    const mine = (await stat(mutex)).ino;
    let wrote = false;
    const stillOwner = async () =>
        (await readFile(path.join(mutex, "owner"), "utf8").catch(() => "")) ===
        token;
    // Before the owner file exists the directory is only recognisable by its inode.
    const sameDir = () =>
        stat(mutex).then(
            (st) => st.ino === mine,
            () => false,
        );
    try {
        await (hooks.write ?? writeFile)(path.join(mutex, "owner"), token);
        wrote = true;
        return await fn(stillOwner);
    } finally {
        if (wrote ? await stillOwner() : await sameDir())
            await rm(mutex, { recursive: true, force: true });
    }
}

/** Removes `<lock>.takeover.broken-*` directories a crashed breaker left behind; never the live mutex. */
async function sweepBroken(file, staleMs) {
    const dir = path.dirname(file);
    const prefix = `${path.basename(file)}.takeover.broken-`;
    const names = await readdir(dir).catch(() => []);
    for (const name of names.filter((n) => n.startsWith(prefix))) {
        const full = path.join(dir, name);
        const age = await stat(full)
            .then((st) => Date.now() - st.mtimeMs)
            .catch(() => null);
        if (age !== null && Math.abs(age) > staleMs)
            await rm(full, { recursive: true, force: true });
    }
}

/**
 * Takes `file` with the pid of this process; throws while another live
 * process holds it, takes it over when the holder is gone. The lock appears
 * atomically with its pid in it (a temp file linked into place), the takeover
 * runs under a mutex and re-reads the holder inside it, so two processes that
 * both saw a stale lock cannot each remove the other's fresh one. Returns a
 * release that removes the lock only while it still holds this pid.
 * `hooks.beforeRemove`, `hooks.beforeBreak`, `hooks.write` and `hooks.staleMs` exist for the tests.
 */
export async function acquireLock(file, hooks = {}) {
    await mkdir(path.dirname(file), { recursive: true });
    await sweepBroken(file, hooks.staleMs ?? TAKEOVER_STALE_MS);
    const holder = async () =>
        Number((await readFile(file, "utf8").catch(() => "")).trim());
    const release = async () => {
        if ((await holder()) === process.pid) await rm(file, { force: true });
    };
    const create = async () => {
        const temp = `${file}.${process.pid}.${randomUUID()}.tmp`;
        await writeFile(temp, String(process.pid));
        try {
            await link(temp, file);
            return true;
        } catch (error) {
            if (error.code !== "EEXIST") throw error;
            return false;
        } finally {
            await rm(temp, { force: true });
        }
    };
    for (;;) {
        if (await create()) return release;
        const pid = await holder();
        if (pid > 0 && alive(pid))
            throw new Error(`another continue is running (pid ${pid})`);
        const won = await withTakeover(
            file,
            hooks.staleMs ?? TAKEOVER_STALE_MS,
            hooks,
            async (stillOwner) => {
                const current = await holder();
                if (current > 0 && alive(current))
                    throw new Error(
                        `another continue is running (pid ${current})`,
                    );
                await hooks.beforeRemove?.();
                if (!(await stillOwner())) return false;
                await rm(file, { force: true });
                return create();
            },
        );
        if (won) return release;
    }
}

export async function openRun(runId, s) {
    const dir = path.join(s.runsDir, runId);
    const meta = await readJson(path.join(dir, "meta.json"));
    const corpus = await loadCorpus(meta.corpusDir);
    const plan = await readJson(path.join(dir, "plan.json"));
    const planned = plannedCorpus(
        corpus,
        plan.map((e) => e.task),
    );
    if (corpusHashFor(planned, meta) !== meta.corpusHash)
        throw new Error(
            `the corpus changed since run ${runId} started; start a new run`,
        );
    return {
        dir,
        meta,
        corpus,
        // The tasks this run planned: what ablate, calibrate and the report
        // read, never the whole corpus, which may have grown since.
        tasks: planned.tasks,
        plan,
        ledger: path.join(dir, "ledger.jsonl"),
    };
}

async function start(args, s) {
    const { values } = parseArgs({
        args,
        options: {
            corpus: {
                type: "string",
                default: path.join(REPO_ROOT, "tools", "eval", "corpus"),
            },
            model: { type: "string", default: "sonnet" },
            arms: { type: "string", default: "baseline,tools,full" },
            repeats: { type: "string", default: "3" },
            seed: { type: "string", default: String(Date.now() % 2 ** 31) },
            budget: { type: "string", default: "1" },
            timeout: { type: "string", default: "600" },
            tier: { type: "string" },
            passes: { type: "string", default: "1" },
        },
    });
    if (values.passes !== "1" && values.passes !== "2")
        throw new Error(`--passes must be 1 or 2, got "${values.passes}"`);
    const passes = Number(values.passes);
    if (values.tier !== undefined && !TIERS.includes(values.tier))
        throw new Error(`--tier must be one of ${TIERS.join(", ")}`);
    const repeats = positive("repeats", values.repeats, { integer: true });
    const budgetUsd = positive("budget", values.budget);
    const timeoutSeconds = positive("timeout", values.timeout);
    const seed = Number(values.seed);
    if (!Number.isInteger(seed))
        throw new Error(`--seed must be an integer, got "${values.seed}"`);
    const arms = values.arms.split(",");
    for (const arm of arms) parseArm(arm);
    const corpusDir = path.resolve(values.corpus);
    const corpus = await loadCorpus(corpusDir);
    const tasks =
        values.tier === undefined
            ? corpus.tasks
            : corpus.tasks.filter((t) => tierOf(t) === values.tier);
    if (tasks.length === 0)
        throw new Error(`no ${values.tier} tasks in the corpus`);
    const stamp = new Date()
        .toISOString()
        .replace(/[-:]/g, "")
        .replace("T", "-")
        .slice(0, 15);
    // Two runs in the same second get -2, -3, ...; a directory is never reused.
    await mkdir(s.runsDir, { recursive: true });
    let runId = `${stamp}-${values.model}`;
    for (let n = 2; ; n += 1) {
        try {
            await mkdir(path.join(s.runsDir, runId));
            break;
        } catch (error) {
            if (error.code !== "EEXIST") throw error;
            runId = `${stamp}-${values.model}-${n}`;
        }
    }
    const meta = {
        runId,
        createdAt: new Date().toISOString(),
        corpusDir,
        corpusHash: corpusHash(
            plannedCorpus(
                corpus,
                tasks.map((t) => t.id),
            ),
        ),
        corpusHashVersion: CORPUS_HASH_VERSION,
        model: values.model,
        arms,
        repeats,
        seed,
        budgetUsd,
        timeoutMs: timeoutSeconds * 1000,
        ...(values.tier === undefined ? {} : { tier: values.tier }),
        ...(passes === 2 ? { passes } : {}),
        ...(await provenance(s)),
    };
    const dir = path.join(s.runsDir, runId);
    await mkdir(path.join(dir, "transcripts"), { recursive: true });
    await writeJson(path.join(dir, "meta.json"), meta);
    await writeJson(
        path.join(dir, "plan.json"),
        makePlan({
            tasks,
            arms: meta.arms,
            repeats: meta.repeats,
            seed: meta.seed,
            passes,
        }),
    );
    console.log(runId);
    return 0;
}

/**
 * Throws when a scan produced a graph that must not be trusted: its summary
 * says PARTIAL GRAPH (a language worker failed, yet the scan exits 0) or it
 * holds no nodes. `json` is the parsed `knossos scan --json` output.
 */
export function checkScan(name, json) {
    const summary = String(json?.summary ?? "");
    if (summary.includes("PARTIAL GRAPH") || json?.data?.nodes === 0)
        throw new Error(
            `the scan of ${name} is not usable: ${summary || "no summary"}`,
        );
}

async function prepare(args, s) {
    const { values, positionals } = parseArgs({
        args,
        allowPositionals: true,
        options: { "no-scan": { type: "boolean", default: false } },
    });
    const { corpus, dir } = await openRun(positionals[0], s);
    const paths = evalPaths(s.evalRoot);
    // Guard first: nothing is written before the isolation is proven.
    const env = await evalEnv(paths, { base: s.env });
    const names = [...new Set(corpus.tasks.map((t) => t.repo))].sort();
    const workspaces = [];
    for (const name of names)
        workspaces.push(await ensureClone(name, corpus.repos[name], paths));
    await seedConfig(paths, workspaces, { realHome: s.realHome });
    await writeMcpConfig(paths, REPO_ROOT);
    await warnPluginVersion(s);
    if (values["no-scan"]) return 0;
    const plugin = path.join(s.pluginDir, ".claude-plugin", "plugin.json");
    await access(plugin).catch((error) => {
        throw new Error(
            `no materialised plugin at ${plugin}; run tools/install from this checkout first`,
            { cause: error },
        );
    });
    const scanMs = {};
    for (const [i, name] of names.entries()) {
        console.log(`scanning ${name}`);
        const started = Date.now();
        const { stdout } = await exec(
            "php",
            [
                path.join(REPO_ROOT, "bin", "knossos"),
                "scan",
                workspaces[i],
                `--name=eval-${name}`,
                "--json",
            ],
            { env, maxBuffer: 1 << 26 },
        );
        checkScan(name, JSON.parse(stdout));
        scanMs[name] = Date.now() - started;
    }
    await writeJson(path.join(dir, "prepare.json"), scanMs);
    return 0;
}

/** Warns when the materialised plugin is not the version of this checkout: the full arm runs the installed one. */
async function warnPluginVersion(s) {
    const installed = await pluginVersion(s.pluginDir);
    const checkout = await readFile(
        path.join(s.sourceRoot, "version.txt"),
        "utf8",
    )
        .then((text) => text.trim())
        .catch(() => null);
    if (installed !== null && checkout !== null && installed !== checkout)
        console.warn(
            `warning: the plugin in ${s.pluginDir} is version ${installed}, but this checkout is ${checkout}; the full arm runs the installed plugin, so run tools/install first to measure this checkout`,
        );
    // The same version can still be a stale copy: pulled, never reinstalled.
    const stale = [];
    for (const root of ["hooks", "skills"]) {
        for (const file of await filesUnder(path.join(s.pluginDir, root))) {
            const relative = path.join(root, file);
            const ours = await readFile(
                path.join(s.sourceRoot, relative),
            ).catch(() => null);
            if (ours === null) continue;
            const theirs = await readFile(path.join(s.pluginDir, relative));
            if (!ours.equals(theirs)) stale.push(relative);
        }
    }
    if (stale.length > 0)
        console.warn(
            `warning: the plugin in ${s.pluginDir} differs from this checkout in ${stale.length} file${stale.length === 1 ? "" : "s"} (${stale.slice(0, 5).join(", ")}${stale.length > 5 ? ", …" : ""}); the full arm runs the installed plugin, so run tools/install first to measure this checkout`,
        );
}

/**
 * Runs the annotations helper for one project: `clear`, or `export`/`import`
 * with `file`. Prints, and so returns, how many notes a model wrote (export).
 */
export async function notes(s, paths, command, workspace, file) {
    let stdout;
    try {
        ({ stdout } = await promisify(execFile)(
            s.notesBin,
            [
                command,
                path.join(paths.data, "knossos.sqlite"),
                workspace,
                ...(file === undefined ? [] : [file]),
            ],
            { env: s.env },
        ));
    } catch (e) {
        // The session is paid for by now when an export fails: say why it stopped.
        const reason = (e.stderr ?? "").trim() || e.message;
        throw new Error(
            `annotations ${command} for ${workspace} failed: ${reason}`,
            { cause: e },
        );
    }
    return Number.parseInt(stdout.trim(), 10) || 0;
}

async function executeEntry(entry, ctx) {
    const { run, s, paths, env, rows, maxUtilization } = ctx;
    const task = run.tasks.find((t) => t.id === entry.task);
    const repo = run.corpus.repos[entry.repo];
    const workspace = path.join(paths.repos, entry.repo);
    await resetWorkspace(workspace, repo.commit);
    // Annotations live in the shared graph database: a note one session wrote
    // would answer the next. Every session starts without any, except a
    // second pass, which starts with exactly what its first pass left.
    const kept = path.join(
        run.dir,
        "notes",
        `${entry.task}--${entry.arm}--${entry.repeat}.json`,
    );
    const imported = await notes(
        s,
        paths,
        entry.pass === 2 ? "import" : "clear",
        workspace,
        entry.pass === 2 ? kept : undefined,
    );
    const transcript = path.join(
        "transcripts",
        `${entry.task}--${entry.arm}--${entry.repeat}${entry.pass === 2 ? "--p2" : ""}.jsonl`,
    );
    const outcome = await runSession({
        claudeBin: s.claudeBin,
        argv: armArgv(entry.arm, {
            model: run.meta.model,
            budgetUsd: run.meta.budgetUsd,
            mcpConfigPath: paths.mcpConfig,
            pluginDir: s.pluginDir,
        }),
        prompt: `${task.prompt}${ANSWER_INSTRUCTION}`,
        cwd: workspace,
        env,
        transcriptPath: path.join(run.dir, transcript),
        timeoutMs: run.meta.timeoutMs,
        onSpawn: ctx.onSpawn,
        onClose: ctx.onClose,
    });
    // What the session left: a first pass keeps it for its second; every
    // session counts the model notes it ends with, and a second pass how many
    // of those it was handed.
    await mkdir(path.dirname(kept), { recursive: true });
    const written = await notes(
        s,
        paths,
        "export",
        workspace,
        entry.pass === 2
            ? path.join(
                  path.dirname(kept),
                  `${path.basename(kept, ".json")}--p2.json`,
              )
            : kept,
    );
    const row = buildRow({
        entry,
        task,
        language: repo.language,
        workspace,
        record: parseTranscript(outcome.lines),
        timedOut: outcome.timedOut,
        wallMs: outcome.wallMs,
        exitCode: outcome.exitCode,
        stderr: outcome.stderr,
        maxUtilization,
        priorErrors: rows.filter(
            (r) => sessionKey(r) === sessionKey(entry) && r.status === "error",
        ).length,
        transcript,
        model: run.meta.model,
        knossosCommit: ctx.knossosCommit,
    });
    return {
        ...row,
        pass: entry.pass ?? 1,
        notesWritten: written,
        ...(entry.pass === 2 ? { notesImported: imported } : {}),
    };
}

/**
 * Runs `queue` with `concurrency` workers, one session per repo at a time.
 * `state.stop` (set by a limit, an error or a signal) keeps the workers from
 * starting another entry; sessions already running finish.
 */
export async function drain(queue, concurrency, execute, state) {
    const busy = new Set();
    const worker = async () => {
        while (state.stop === null && queue.length > 0) {
            const at = queue.findIndex((e) => !busy.has(e.repo));
            if (at === -1) {
                await sleep(100);
                continue;
            }
            const [entry] = queue.splice(at, 1);
            busy.add(entry.repo);
            try {
                await execute(entry);
            } catch (error) {
                state.firstError ??= error;
                state.stop ??= "error";
            } finally {
                busy.delete(entry.repo);
            }
        }
    };
    await Promise.all(Array.from({ length: concurrency }, worker));
}

/**
 * The SIGINT/SIGTERM handler. The first signal cleans up: it always releases
 * the lock and exits, even when terminating the sessions or stopping the
 * watchers rejects. A second signal while that is still running does not wait
 * for it and forces exit 130.
 */
export function signalHandler(code, state, ops) {
    return async () => {
        if (state.stop === "signal") {
            ops.exit(130);
            return;
        }
        // Before anything else, so no worker starts a session the live snapshot misses.
        state.stop = "signal";
        try {
            await ops.terminate();
            await ops.stopWatchers();
        } catch (error) {
            console.error(`cleanup after the signal failed: ${error.message}`);
        } finally {
            try {
                await ops.release();
            } catch (error) {
                console.error(`releasing the lock failed: ${error.message}`);
            }
            ops.exit(code);
        }
    };
}

/** Throws unless `prepare` left what the queued arms need. */
async function assertPrepared(queue, paths, s) {
    const bases = queue.map((e) => parseArm(e.arm).base);
    const need = [];
    if (bases.some((b) => b !== "baseline")) need.push(paths.mcpConfig);
    if (bases.includes("full"))
        need.push(path.join(s.pluginDir, ".claude-plugin", "plugin.json"));
    for (const file of need) {
        await access(file).catch((error) => {
            throw new Error(`run prepare first: ${file} is missing`, {
                cause: error,
            });
        });
    }
}

async function continueRun(args, s) {
    const { values, positionals } = parseArgs({
        args,
        allowPositionals: true,
        options: {
            batch: { type: "string", default: "4" },
            concurrency: { type: "string", default: "2" },
            "max-utilization": { type: "string", default: "0.85" },
            "force-provenance": { type: "boolean", default: false },
        },
    });
    const batch = positive("batch", values.batch, { integer: true });
    const concurrency = positive("concurrency", values.concurrency, {
        integer: true,
    });
    const maxUtilization = positive(
        "max-utilization",
        values["max-utilization"],
        { max: 1 },
    );
    const run = await openRun(positionals[0], s);
    const rows = await readLedger(run.ledger);
    // A second pass waits for its first, so a batch may hold only first
    // passes; something is runnable whenever anything is pending.
    const queue = runnable(run.plan, rows).slice(0, batch);
    if (queue.length === 0) {
        console.log(
            `run ${run.meta.runId} is complete; write the report with: node tools/eval/report.mjs ${run.meta.runId}`,
        );
        return 0;
    }
    const current = await provenance(s);
    if (!values["force-provenance"]) checkProvenance(run.meta, current);
    const paths = evalPaths(s.evalRoot);
    const env = await evalEnv(paths, { base: s.env });
    await assertPrepared(queue, paths, s);
    const release = await acquireLock(path.join(paths.root, "continue.lock"));
    const live = new Set();
    const state = { stop: null, firstError: null };
    const onSignal = (code) =>
        signalHandler(code, state, {
            terminate: () => terminateGroups([...live], 5000),
            stopWatchers: () => stopWatchers(paths.data),
            release,
            exit: process.exit,
        });
    const handlers = { SIGINT: onSignal(130), SIGTERM: onSignal(143) };
    for (const [name, handler] of Object.entries(handlers))
        process.on(name, handler);
    try {
        const workspaces = [
            ...new Set(run.plan.map((e) => path.join(paths.repos, e.repo))),
        ];
        await seedConfig(paths, workspaces, { realHome: s.realHome });
        const ctx = {
            run,
            s,
            paths,
            env,
            rows,
            maxUtilization,
            knossosCommit: current.knossosCommit,
            onSpawn: (pid) => {
                if (pid !== undefined) live.add(pid);
            },
            onClose: (pid) => live.delete(pid),
        };
        await drain(
            queue,
            concurrency,
            async (entry) => {
                const row = await executeEntry(entry, ctx);
                await appendRow(run.ledger, row);
                console.log(
                    `${row.status.padEnd(12)} ${entry.task} ${entry.arm} #${entry.repeat}`,
                );
                if (row.limit !== "ok") state.stop ??= row.limit;
            },
            state,
        );
    } finally {
        try {
            await stopWatchers(paths.data);
        } finally {
            for (const [name, handler] of Object.entries(handlers))
                process.off(name, handler);
            await release();
        }
    }
    const { stop, firstError } = state;
    if (firstError !== null) throw firstError;
    const left = pending(run.plan, await readLedger(run.ledger)).length;
    if (stop !== null) {
        console.log(
            `stopped: ${stop === "limited" ? "usage limit reached" : "five-hour window above the threshold"}; ${left} sessions left, continue after the window resets`,
        );
        return 3;
    }
    console.log(`${left} sessions left`);
    return 0;
}

async function ablate(args, s) {
    const { values, positionals } = parseArgs({
        args,
        allowPositionals: true,
        options: { threshold: { type: "string", default: "0.2" } },
    });
    const threshold = positive("threshold", values.threshold);
    const run = await openRun(positionals[0], s);
    const rows = await readLedger(run.ledger);
    // Ablation picks its tools from the full arm, and compares against it: both must be finished.
    const waiting = pending(run.plan, rows).filter((e) =>
        BASE_ARMS.includes(e.arm),
    ).length;
    if (waiting > 0)
        throw new Error(
            `${waiting} baseline, tools or full sessions are still pending; finish them with continue before ablate`,
        );
    const have = new Set(run.plan.map(sessionKey));
    const added = [];
    for (const { tool, taskIds } of selectAblations(rows, run.tasks, {
        threshold,
    })) {
        for (const id of taskIds) {
            const task = run.tasks.find((t) => t.id === id);
            for (let repeat = 1; repeat <= run.meta.repeats; repeat += 1) {
                const entry = {
                    task: id,
                    repo: task.repo,
                    arm: ablationArm(tool),
                    repeat,
                };
                if (!have.has(sessionKey(entry))) {
                    have.add(sessionKey(entry));
                    added.push(entry);
                }
            }
        }
    }
    await writeJson(path.join(run.dir, "plan.json"), [...run.plan, ...added]);
    console.log(`added ${added.length} ablation sessions`);
    return 0;
}

async function status(args, s) {
    const run = await openRun(args[0], s);
    const rows = await readLedger(run.ledger);
    const counts = {};
    for (const r of rows) counts[r.status] = (counts[r.status] ?? 0) + 1;
    console.log(
        JSON.stringify({
            planned: run.plan.length,
            pending: pending(run.plan, rows).length,
            ...counts,
        }),
    );
    return 0;
}

const COMMANDS = { start, prepare, continue: continueRun, ablate, status };

export async function main(args, env = process.env) {
    const [command, ...rest] = args;
    const handler = COMMANDS[command];
    if (!handler) {
        console.error(
            `usage: node tools/eval/run.mjs ${Object.keys(COMMANDS).join("|")} ...`,
        );
        return 2;
    }
    return handler(rest, settings(env));
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    main(process.argv.slice(2)).then(
        (code) => process.exit(code),
        (error) => {
            console.error(error.message);
            process.exit(1);
        },
    );
}
