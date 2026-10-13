import { execFile, spawn } from "node:child_process";
import {
    access,
    mkdir,
    readdir,
    readFile,
    rm,
    stat,
    utimes,
    writeFile,
} from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { promisify } from "node:util";
import { randomUUID } from "node:crypto";
import { afterAll, describe, expect, it, vi } from "vitest";
import { removeTemp, tmp } from "./fixtures/tmp.mjs";
import { corpusHash, legacyCorpusHash, loadCorpus } from "./corpus.mjs";
import { evalPaths } from "./env.mjs";
import { readLedger, sessionKey } from "./ledger.mjs";
import { stopWatchers } from "../capture/session.mjs";
import {
    acquireLock,
    checkProvenance,
    checkScan,
    dirtyPaths,
    drain,
    main,
    signalHandler,
} from "./run.mjs";

afterAll(removeTemp);

vi.mock("../capture/session.mjs", async (importOriginal) => {
    const actual = await importOriginal();
    return { ...actual, stopWatchers: vi.fn(actual.stopWatchers) };
});

const git = promisify(execFile);
const FAKE = fileURLToPath(
    new URL("./fixtures/fake-claude.mjs", import.meta.url),
);

async function fixture({ second = false } = {}) {
    const origin = await tmp("eval-origin-");
    await git("git", ["init", "-q", origin]);
    await writeFile(path.join(origin, "a.php"), "<?php\n");
    await git("git", [
        "-C",
        origin,
        "-c",
        "user.name=t",
        "-c",
        "user.email=t@t",
        "add",
        ".",
    ]);
    await git("git", [
        "-C",
        origin,
        "-c",
        "user.name=t",
        "-c",
        "user.email=t@t",
        "commit",
        "-qm",
        "one",
    ]);
    const commit = (
        await git("git", ["-C", origin, "rev-parse", "HEAD"])
    ).stdout.trim();
    const corpus = await tmp("eval-corpus-");
    await writeFile(
        path.join(corpus, "repos.json"),
        JSON.stringify({
            repos: {
                demo: { url: origin, commit, language: "php" },
                ...(second
                    ? { other: { url: origin, commit, language: "php" } }
                    : {}),
            },
        }),
    );
    if (second) {
        await mkdir(path.join(corpus, "tasks", "other"), { recursive: true });
        await writeFile(
            path.join(corpus, "tasks", "other", "other-callers-a.json"),
            JSON.stringify({
                id: "other-callers-a",
                repo: "other",
                kind: "question",
                category: "callers",
                subject: "Invoice::total",
                prompt: "Who calls Invoice::total?",
                answer: {
                    unit: "symbol",
                    expected: ["App\\Billing::summarise"],
                },
                verified: "2026-10-13",
            }),
        );
    }
    const pluginDir = await tmp("eval-plugin-");
    await mkdir(path.join(pluginDir, ".claude-plugin"));
    await writeFile(
        path.join(pluginDir, ".claude-plugin", "plugin.json"),
        "{}",
    );
    await mkdir(path.join(corpus, "tasks", "demo"), { recursive: true });
    for (const id of ["demo-callers-a", "demo-callers-b"]) {
        await writeFile(
            path.join(corpus, "tasks", "demo", `${id}.json`),
            JSON.stringify({
                id,
                repo: "demo",
                kind: "question",
                category: "callers",
                subject: "Invoice::total",
                prompt: "Who calls Invoice::total?",
                answer: {
                    unit: "symbol",
                    expected: ["App\\Billing::summarise"],
                },
                verified: "2026-10-13",
            }),
        );
    }
    const env = {
        ...process.env,
        KNOSSOS_EVAL_RUNS: await tmp("eval-runs-"),
        KNOSSOS_EVAL_ROOT: await tmp("eval-root-"),
        KNOSSOS_EVAL_CLAUDE: FAKE,
        KNOSSOS_EVAL_REAL_HOME: await tmp("eval-nohome-"),
        KNOSSOS_EVAL_PLUGIN_DIR: pluginDir,
        // The demo origin stands in for the Knossos checkout, so provenance never reads this repository.
        KNOSSOS_EVAL_SOURCE_ROOT: origin,
    };
    return { corpus, env, origin, pluginDir };
}

async function started(extra = [], options = {}) {
    const { corpus, env } = await fixture(options);
    const log = vi.spyOn(console, "log").mockImplementation(() => {});
    expect(
        await main(
            [
                "start",
                "--corpus",
                corpus,
                "--repeats",
                "1",
                "--seed",
                "1",
                ...extra,
            ],
            env,
        ),
    ).toBe(0);
    const runId = log.mock.calls.at(-1)[0];
    expect(await main(["prepare", runId, "--no-scan"], env)).toBe(0);
    log.mockRestore();
    return {
        corpus,
        env,
        runId,
        dir: path.join(env.KNOSSOS_EVAL_RUNS, runId),
        origin: env.KNOSSOS_EVAL_SOURCE_ROOT,
        pluginDir: env.KNOSSOS_EVAL_PLUGIN_DIR,
    };
}

describe("run CLI", () => {
    it("runs one small batch at a time and resumes where it stopped", async () => {
        const { env, runId, dir } = await started();
        expect(
            await main(
                ["continue", runId, "--batch", "2", "--concurrency", "2"],
                env,
            ),
        ).toBe(0);
        expect(await readLedger(path.join(dir, "ledger.jsonl"))).toHaveLength(
            2,
        );
        expect(await main(["continue", runId, "--batch", "10"], env)).toBe(0);
        const rows = await readLedger(path.join(dir, "ledger.jsonl"));
        expect(rows).toHaveLength(6);
        expect(rows.every((r) => r.status === "scored")).toBe(true);
    });
    it("stops the batch when the five-hour window passes the threshold", async () => {
        const { env, runId, dir } = await started();
        const code = await main(
            ["continue", runId, "--batch", "6", "--concurrency", "1"],
            { ...env, FAKE_UTILIZATION: "0.9" },
        );
        expect(code).toBe(3);
        expect(await readLedger(path.join(dir, "ledger.jsonl"))).toHaveLength(
            1,
        );
    });
    it("runs sessions isolated, with the prompt on stdin", async () => {
        const { env, runId } = await started(["--arms", "baseline"]);
        const fakeLog = path.join(await tmp("eval-log-"), "fake.jsonl");
        await main(["continue", runId, "--batch", "1"], {
            ...env,
            FAKE_LOG: fakeLog,
        });
        const [call] = (await readFile(fakeLog, "utf8"))
            .trim()
            .split("\n")
            .map((l) => JSON.parse(l));
        expect(call.home).toBe(path.join(env.KNOSSOS_EVAL_ROOT, "home"));
        expect(call.cwd).toBe(
            path.join(env.KNOSSOS_EVAL_ROOT, "repos", "demo"),
        );
        expect(call.prompt).toMatch(
            /Who calls Invoice::total\?[\s\S]*```answer|tagged `answer`/,
        );
        expect(call.argv).not.toContain("--mcp-config");
    });
    it("keeps a run open after a task it did not plan is added", async () => {
        const { corpus, env, runId } = await started();
        const file = path.join(corpus, "tasks", "demo", "demo-callers-a.json");
        const task = JSON.parse(await readFile(file, "utf8"));
        await writeFile(
            path.join(corpus, "tasks", "demo", "demo-callers-c.json"),
            JSON.stringify({ ...task, id: "demo-callers-c", tier: "hard" }),
        );
        await expect(main(["status", runId], env)).resolves.toBe(0);
    });
    it("refuses to continue after the corpus changed", async () => {
        const { corpus, env, runId } = await started();
        const file = path.join(corpus, "tasks", "demo", "demo-callers-a.json");
        const task = JSON.parse(await readFile(file, "utf8"));
        await writeFile(file, JSON.stringify({ ...task, prompt: "changed" }));
        await expect(main(["continue", runId], env)).rejects.toThrow(
            /corpus changed/,
        );
    });
    it("adds ablation arms for tools full used", async () => {
        const { env, runId, dir } = await started();
        await main(["continue", runId, "--batch", "10"], env);
        expect(await main(["ablate", runId], env)).toBe(0);
        const plan = JSON.parse(
            await readFile(path.join(dir, "plan.json"), "utf8"),
        );
        expect(
            plan.filter((e) => e.arm === "full-minus-list_usages"),
        ).toHaveLength(2);
    });
    it("proves the isolation before it writes anything, whatever the credentials source", async () => {
        const { env, runId } = await started();
        // A fresh unique name under the real ~/.knossos: it must never be created.
        const root = path.join(
            os.homedir(),
            ".knossos",
            `eval-guard-${randomUUID()}`,
        );
        const bad = {
            ...env,
            KNOSSOS_EVAL_ROOT: root,
            KNOSSOS_EVAL_REAL_HOME: path.join(root, "home"),
        };
        await expect(
            main(["prepare", runId, "--no-scan"], bad),
        ).rejects.toThrow(/refusing/);
        await expect(main(["continue", runId], bad)).rejects.toThrow(
            /refusing/,
        );
        await expect(access(root)).rejects.toThrow();
    });
    it("lets the other session finish and rethrows when one session cannot start", async () => {
        const { env, runId, dir } = await started(["--arms", "baseline"], {
            second: true,
        });
        await rm(path.join(env.KNOSSOS_EVAL_ROOT, "repos", "other"), {
            recursive: true,
        });
        const log = vi.spyOn(console, "log").mockImplementation(() => {});
        await expect(
            main(["continue", runId, "--batch", "2", "--concurrency", "2"], {
                ...env,
                FAKE_SLEEP_MS: "400",
            }),
        ).rejects.toThrow();
        log.mockRestore();
        const rows = await readLedger(path.join(dir, "ledger.jsonl"));
        expect(rows.map((r) => r.repo)).toEqual(["demo"]);
    });
    it("gives a second run in the same second its own directory", async () => {
        const { corpus, env } = await fixture();
        const log = vi.spyOn(console, "log").mockImplementation(() => {});
        vi.useFakeTimers({ toFake: ["Date"] });
        vi.setSystemTime(new Date("2026-10-13T10:00:00Z"));
        const args = [
            "start",
            "--corpus",
            corpus,
            "--repeats",
            "1",
            "--seed",
            "1",
        ];
        await main(args, env);
        await main(args, env);
        await main(args, env);
        vi.useRealTimers();
        const ids = log.mock.calls.map((c) => c[0]);
        log.mockRestore();
        expect(ids).toEqual([
            "20261013-100000-sonnet",
            "20261013-100000-sonnet-2",
            "20261013-100000-sonnet-3",
        ]);
        for (const id of ids)
            await access(path.join(env.KNOSSOS_EVAL_RUNS, id, "meta.json"));
    });
    it("rejects bad numbers and unknown arms with a clear message", async () => {
        const { corpus, env, runId } = await started();
        const start = (...extra) =>
            main(["start", "--corpus", corpus, ...extra], env);
        await expect(start("--repeats", "0")).rejects.toThrow(
            /--repeats must be a positive integer/,
        );
        await expect(start("--repeats", "1.5")).rejects.toThrow(/--repeats/);
        await expect(start("--budget", "abc")).rejects.toThrow(/--budget/);
        await expect(start("--timeout", "-1")).rejects.toThrow(/--timeout/);
        await expect(start("--arms", "baseline,bogus")).rejects.toThrow(
            /unknown arm bogus/,
        );
        await expect(
            main(["continue", runId, "--batch", "0"], env),
        ).rejects.toThrow(/--batch/);
        await expect(
            main(["continue", runId, "--concurrency", "x"], env),
        ).rejects.toThrow(/--concurrency/);
        await expect(
            main(["continue", runId, "--max-utilization", "1.5"], env),
        ).rejects.toThrow(/at most 1/);
        await expect(
            main(["ablate", runId, "--threshold", "0"], env),
        ).rejects.toThrow(/--threshold/);
    });
    it("refuses to spend a session before prepare produced what the arm needs", async () => {
        const { env, runId } = await started();
        const noPlugin = {
            ...env,
            KNOSSOS_EVAL_PLUGIN_DIR: await tmp("eval-noplugin-"),
        };
        await expect(main(["continue", runId], noPlugin)).rejects.toThrow(
            /run prepare first/,
        );
        await rm(path.join(env.KNOSSOS_EVAL_ROOT, "mcp.json"));
        await expect(main(["continue", runId], env)).rejects.toThrow(
            /run prepare first.*mcp\.json/,
        );
    });
    it("does not need the plugin or the MCP config for a baseline-only run", async () => {
        const { env, runId } = await started(["--arms", "baseline"]);
        await rm(path.join(env.KNOSSOS_EVAL_ROOT, "mcp.json"));
        const log = vi.spyOn(console, "log").mockImplementation(() => {});
        const code = await main(["continue", runId, "--batch", "1"], {
            ...env,
            KNOSSOS_EVAL_PLUGIN_DIR: await tmp("eval-noplugin-"),
        });
        log.mockRestore();
        expect(code).toBe(0);
    });
    it("refuses a second continue while the first holds the lock", async () => {
        const { env, runId } = await started();
        const release = await acquireLock(
            path.join(env.KNOSSOS_EVAL_ROOT, "continue.lock"),
        );
        await expect(main(["continue", runId], env)).rejects.toThrow(
            new RegExp(`another continue is running \\(pid ${process.pid}\\)`),
        );
        await release();
    });
});

describe("corpus hash versions", () => {
    it("writes the hash version into meta and still opens a run stored with the legacy hash", async () => {
        const { env, runId, dir } = await started();
        const file = path.join(dir, "meta.json");
        const meta = JSON.parse(await readFile(file, "utf8"));
        expect(meta.corpusHashVersion).toBe(2);
        const corpus = await loadCorpus(meta.corpusDir);
        expect(meta.corpusHash).toBe(corpusHash(corpus));
        const old = { ...meta };
        delete old.corpusHashVersion;
        await writeFile(
            file,
            JSON.stringify({ ...old, corpusHash: legacyCorpusHash(corpus) }),
        );
        const log = vi.spyOn(console, "log").mockImplementation(() => {});
        expect(await main(["status", runId], env)).toBe(0);
        log.mockRestore();
        await writeFile(
            file,
            JSON.stringify({ ...old, corpusHash: "0".repeat(64) }),
        );
        await expect(main(["status", runId], env)).rejects.toThrow(
            /corpus changed/,
        );
    });
});

describe("continue with the fake claude", () => {
    const rowsOf = async (dir) => readLedger(path.join(dir, "ledger.jsonl"));
    it("reruns a limited session on the next continue", async () => {
        const { env, runId, dir } = await started(["--arms", "baseline"]);
        const log = vi.spyOn(console, "log").mockImplementation(() => {});
        const code = await main(["continue", runId, "--batch", "1"], {
            ...env,
            FAKE_LIMIT_STATUS: "rejected",
        });
        expect(code).toBe(3);
        const [limited] = await rowsOf(dir);
        expect(limited.status).toBe("limited");
        expect(await main(["continue", runId, "--batch", "1"], env)).toBe(0);
        log.mockRestore();
        const rows = await rowsOf(dir);
        expect(rows.map((r) => r.status)).toEqual(["limited", "scored"]);
        expect(sessionKey(rows[1])).toBe(sessionKey(rows[0]));
    });
    it("retries an error once and then marks the session failed", async () => {
        const { env, runId, dir } = await started(["--arms", "baseline"]);
        const log = vi.spyOn(console, "log").mockImplementation(() => {});
        const failing = {
            ...env,
            FAKE_RESULT_SUBTYPE: "error_during_execution",
        };
        await main(["continue", runId, "--batch", "1"], failing);
        await main(["continue", runId, "--batch", "1"], failing);
        log.mockRestore();
        const rows = await rowsOf(dir);
        expect(rows.map((r) => r.status)).toEqual(["error", "failed"]);
        expect(sessionKey(rows[1])).toBe(sessionKey(rows[0]));
    });
    it("gives the child CLAUDE_CONFIG_DIR inside the eval root", async () => {
        const { env, runId } = await started(["--arms", "baseline"]);
        const fakeLog = path.join(await tmp("eval-log-"), "fake.jsonl");
        const log = vi.spyOn(console, "log").mockImplementation(() => {});
        await main(["continue", runId, "--batch", "1"], {
            ...env,
            FAKE_LOG: fakeLog,
        });
        log.mockRestore();
        const [call] = (await readFile(fakeLog, "utf8"))
            .trim()
            .split("\n")
            .map((l) => JSON.parse(l));
        expect(call.configDir).toBe(evalPaths(env.KNOSSOS_EVAL_ROOT).config);
    });
    it("starts no session while a live process holds the lock", async () => {
        const { env, runId, dir } = await started(["--arms", "baseline"]);
        const fakeLog = path.join(await tmp("eval-log-"), "fake.jsonl");
        const release = await acquireLock(
            path.join(env.KNOSSOS_EVAL_ROOT, "continue.lock"),
        );
        await expect(
            main(["continue", runId], { ...env, FAKE_LOG: fakeLog }),
        ).rejects.toThrow(/another continue is running/);
        await release();
        expect(await readFile(fakeLog, "utf8").catch(() => "")).toBe("");
        expect(await rowsOf(dir)).toEqual([]);
    });
});

describe("acquireLock", () => {
    it("throws while a live process holds it and releases cleanly", async () => {
        const file = path.join(await tmp("eval-lock-"), "continue.lock");
        const release = await acquireLock(file);
        await expect(acquireLock(file)).rejects.toThrow(
            /another continue is running \(pid \d+\)/,
        );
        await release();
        await release2(file);
    });
    const deadPid = async () => {
        const child = spawn("true");
        await new Promise((resolve) => child.once("exit", resolve));
        return child.pid;
    };
    const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    it("lets only one of two acquirers that both saw the lock stale take it over", async () => {
        const file = path.join(await tmp("eval-lock-"), "continue.lock");
        await writeFile(file, String(await deadPid()));
        // Each acquirer pauses between deciding the lock is stale and removing it. When both get
        // there, the first goes all the way through, then the second removes what the first created.
        const waiting = [];
        const hooks = {
            // Only the first pause of each acquirer is held; any later one passes at once.
            beforeRemove: () =>
                waiting.length < 2
                    ? new Promise((resolve) => waiting.push(resolve))
                    : undefined,
        };
        const settled = (p) =>
            p.then(
                () => {},
                () => {},
            );
        const first = acquireLock(file, hooks);
        const second = acquireLock(file, hooks);
        // Handle a rejection at once: the second may fail while the test is still awaiting the first.
        settled(first);
        settled(second);
        for (let i = 0; i < 30 && waiting.length < 2; i += 1) await wait(10);
        waiting[0]();
        await settled(first);
        for (let i = 0; i < 30 && waiting.length < 2; i += 1) await wait(10);
        waiting[1]?.();
        const results = await Promise.allSettled([first, second]);
        expect(results.filter((r) => r.status === "fulfilled")).toHaveLength(1);
        const loser = results.find((r) => r.status === "rejected");
        expect(loser.reason.message).toMatch(/another continue is running/);
        await results.find((r) => r.status === "fulfilled").value();
    });
    it("lets a process that was suspended inside the mutex notice a peer took over", async () => {
        const file = path.join(await tmp("eval-lock-"), "continue.lock");
        await writeFile(file, String(await deadPid()));
        let resume;
        const gate = new Promise((resolve) => (resume = resolve));
        const suspended = acquireLock(file, {
            staleMs: 30,
            beforeRemove: () => gate,
        });
        const outcome = suspended.then(
            () => "acquired",
            (e) => e.message,
        );
        await wait(20);
        // The peer breaks the stale mutex and takes the lock over while the first is suspended.
        const release = await acquireLock(file, { staleMs: 30 });
        const before = (await stat(file)).ino;
        resume();
        expect(await outcome).toMatch(/another continue is running/);
        expect((await stat(file)).ino).toBe(before);
        await release();
    });
    it("does not let a slow breaker remove a fresh mutex that replaced the stale one it judged", async () => {
        const file = path.join(await tmp("eval-lock-"), "continue.lock");
        await writeFile(file, String(await deadPid()));
        const mutex = `${file}.takeover`;
        await mkdir(mutex);
        const old = new Date(Date.now() - 3600_000);
        await utimes(mutex, old, old);
        const pause = () => {
            let release;
            const gate = new Promise((resolve) => (release = resolve));
            return { gate, release, reached: false };
        };
        const breakA = pause();
        const breakB = pause();
        const removeA = pause();
        let removals = 0;
        const hooksA = {
            beforeBreak: async () => {
                breakA.reached = true;
                await breakA.gate;
            },
            beforeRemove: async () => {
                removals += 1;
                removeA.reached = true;
                await removeA.gate;
            },
        };
        const hooksB = {
            beforeBreak: async () => {
                breakB.reached = true;
                await breakB.gate;
            },
            beforeRemove: async () => {
                removals += 1;
            },
        };
        const until = async (test) => {
            for (let i = 0; i < 100 && !test(); i += 1) await wait(10);
        };
        const a = acquireLock(file, hooksA);
        const b = acquireLock(file, hooksB);
        const outcomes = Promise.allSettled([a, b]);
        // Both judge the old mutex stale. A breaks it, takes a fresh one and stops before removing the lock.
        await until(() => breakA.reached && breakB.reached);
        breakA.release();
        await until(() => removeA.reached);
        // B's judgement is out of date now: what stands at that path is A's fresh mutex.
        breakB.release();
        await wait(100);
        removeA.release();
        const results = await outcomes;
        // Exactly one acquirer holds the lock. Which one may vary: once A removes the stale lock,
        // B's own loop can create it before A does, and A then backs off.
        const won = results.filter((r) => r.status === "fulfilled");
        expect(won).toHaveLength(1);
        // B never took a mutex of its own: A's survived B's late break, so B never reached
        // the removal step (the one removal is A's).
        expect(removals).toBe(1);
        await won[0].value();
        expect((await readdir(path.dirname(file))).sort()).toEqual([]);
    });
    it("removes the mutex again when the owner file cannot be written", async () => {
        const dir = await tmp("eval-lock-");
        const file = path.join(dir, "continue.lock");
        await writeFile(file, String(await deadPid()));
        const failing = {
            write: async () => {
                throw Object.assign(new Error("ENOSPC: no space left"), {
                    code: "ENOSPC",
                });
            },
        };
        await expect(acquireLock(file, failing)).rejects.toThrow(/ENOSPC/);
        expect(await readdir(dir)).toEqual([path.basename(file)]);
    });
    it("sweeps broken-mutex leftovers older than the stale threshold and nothing else", async () => {
        const dir = await tmp("eval-lock-");
        const file = path.join(dir, "continue.lock");
        const old = new Date(Date.now() - 3600_000);
        const leftover = `${file}.takeover.broken-123-abc`;
        const recent = `${file}.takeover.broken-456-def`;
        const other = path.join(dir, "other.lock.takeover.broken-1-x");
        for (const d of [leftover, recent, other]) await mkdir(d);
        await writeFile(path.join(leftover, "owner"), "t");
        await utimes(leftover, old, old);
        await utimes(other, old, old);
        const release = await acquireLock(file);
        const left = (await readdir(dir)).sort();
        expect(left).toEqual(
            [
                path.basename(file),
                path.basename(recent),
                path.basename(other),
            ].sort(),
        );
        await release();
    });
    it("treats a takeover mutex dated in the future as stale", async () => {
        const file = path.join(await tmp("eval-lock-"), "continue.lock");
        await writeFile(file, String(await deadPid()));
        await mkdir(`${file}.takeover`);
        const future = new Date(Date.now() + 3600_000);
        await utimes(`${file}.takeover`, future, future);
        const release = await acquireLock(file);
        expect(await readFile(file, "utf8")).toBe(String(process.pid));
        await release();
    });
    it("only releases a lock that still holds its own pid", async () => {
        const file = path.join(await tmp("eval-lock-"), "continue.lock");
        const release = await acquireLock(file);
        const other = String(await deadPid());
        await writeFile(file, other);
        await release();
        expect(await readFile(file, "utf8")).toBe(other);
    });
    it("never leaves a temp file or a mutex behind", async () => {
        const dir = await tmp("eval-lock-");
        const file = path.join(dir, "continue.lock");
        await writeFile(file, String(await deadPid()));
        const release = await acquireLock(file);
        await release();
        expect(await readdir(dir)).toEqual([]);
    });
    it("takes over a lock whose process is gone", async () => {
        const file = path.join(await tmp("eval-lock-"), "continue.lock");
        const child = spawn("true");
        await new Promise((resolve) => child.once("exit", resolve));
        await writeFile(file, String(child.pid));
        await release2(file);
        expect(await readFile(file, "utf8").catch(() => "")).toBe("");
    });
});

async function release2(file) {
    const release = await acquireLock(file);
    expect(await readFile(file, "utf8")).toBe(String(process.pid));
    await release();
}

describe("drain", () => {
    it("starts no further entry once a signal has set stop", async () => {
        const queue = [
            { repo: "a", id: 1 },
            { repo: "b", id: 2 },
            { repo: "a", id: 3 },
        ];
        const state = { stop: null, firstError: null };
        const started = [];
        await drain(
            queue,
            1,
            async (entry) => {
                started.push(entry.id);
                state.stop = "signal"; // what the signal handler does first
            },
            state,
        );
        expect(started).toEqual([1]);
        expect(queue).toHaveLength(2);
    });
    it("records the first error and stops the others", async () => {
        const state = { stop: null, firstError: null };
        await drain(
            [{ repo: "a" }, { repo: "a" }],
            2,
            async () => {
                throw new Error("boom");
            },
            state,
        );
        expect(state.firstError.message).toBe("boom");
        expect(state.stop).toBe("error");
    });
});

describe("continue cleanup", () => {
    it("releases the lock and the handlers even when stopWatchers throws", async () => {
        const { env, runId } = await started(["--arms", "baseline"]);
        const before = process.listenerCount("SIGINT");
        stopWatchers.mockImplementationOnce(async () => {
            throw new Error("watchers stuck");
        });
        const log = vi.spyOn(console, "log").mockImplementation(() => {});
        await expect(
            main(["continue", runId, "--batch", "1"], env),
        ).rejects.toThrow(/watchers stuck/);
        log.mockRestore();
        await expect(
            access(path.join(env.KNOSSOS_EVAL_ROOT, "continue.lock")),
        ).rejects.toThrow();
        expect(process.listenerCount("SIGINT")).toBe(before);
    });
});

describe("signalHandler", () => {
    it("sets stop first, runs its cleanup once and forces exit 130 on a second signal", async () => {
        const state = { stop: null, firstError: null };
        const calls = [];
        let finishTerminate;
        const ops = {
            terminate: () =>
                new Promise((resolve) => {
                    calls.push(`terminate:${state.stop}`);
                    finishTerminate = resolve;
                }),
            stopWatchers: async () => calls.push("watchers"),
            release: async () => calls.push("release"),
            exit: (code) => calls.push(`exit:${code}`),
        };
        const handler = signalHandler(130, state, ops);
        const first = handler();
        await handler(); // a second Ctrl-C while the first is still terminating
        expect(calls).toEqual(["terminate:signal", "exit:130"]);
        finishTerminate();
        await first;
        expect(calls).toEqual([
            "terminate:signal",
            "exit:130",
            "watchers",
            "release",
            "exit:130",
        ]);
    });
    it("releases and exits with the signal's code when cleanup rejects", async () => {
        const err = vi.spyOn(console, "error").mockImplementation(() => {});
        for (const failing of ["terminate", "stopWatchers"]) {
            const calls = [];
            const ops = {
                terminate: async () => {
                    if (failing === "terminate") throw new Error("kill failed");
                },
                stopWatchers: async () => {
                    if (failing === "stopWatchers")
                        throw new Error("stop failed");
                },
                release: async () => calls.push("release"),
                exit: (code) => calls.push(`exit:${code}`),
            };
            await signalHandler(143, { stop: null }, ops)();
            expect(calls).toEqual(["release", "exit:143"]);
        }
        err.mockRestore();
    });
    it("still exits when release itself rejects", async () => {
        const err = vi.spyOn(console, "error").mockImplementation(() => {});
        const calls = [];
        await signalHandler(
            130,
            { stop: null },
            {
                terminate: async () => {},
                stopWatchers: async () => {},
                release: async () => {
                    throw new Error("rm failed");
                },
                exit: (code) => calls.push(code),
            },
        )();
        expect(calls).toEqual([130]);
        err.mockRestore();
    });
});

describe("checkScan", () => {
    it("refuses a partial graph and an empty one, and passes a healthy scan", () => {
        const partial = {
            summary:
                "Scanned 710 files into 0 nodes and 0 relationships. PARTIAL GRAPH: knossos.typescript failed, so nothing it scans is represented. Re-run before trusting this graph.",
            data: { nodes: 0 },
        };
        expect(() => checkScan("web", partial)).toThrow(
            /scan of web is not usable.*PARTIAL GRAPH: knossos\.typescript failed/,
        );
        expect(() =>
            checkScan("web", {
                summary: "Scanned 3 files into 0 nodes and 0 relationships.",
                data: { nodes: 0 },
            }),
        ).toThrow(/web/);
        expect(() =>
            checkScan("web", {
                summary: "Scanned 1 files into 1 nodes and 0 relationships.",
                data: { nodes: 1 },
            }),
        ).not.toThrow();
    });
});

const commitAll = async (dir, message) => {
    const id = ["-c", "user.name=t", "-c", "user.email=t@t"];
    await git("git", ["-C", dir, ...id, "add", "."]);
    await git("git", ["-C", dir, ...id, "commit", "-qm", message]);
};
const setPluginVersion = (pluginDir, version) =>
    writeFile(
        path.join(pluginDir, ".claude-plugin", "plugin.json"),
        JSON.stringify({ version }),
    );
const readMeta = async (dir) =>
    JSON.parse(await readFile(path.join(dir, "meta.json"), "utf8"));

describe("provenance", () => {
    it("records the commit, the dirty flag and the plugin version at start", async () => {
        const { corpus, env, origin, pluginDir } = await fixture();
        await setPluginVersion(pluginDir, "1.2.3");
        await writeFile(path.join(origin, "local.txt"), "change\n");
        const log = vi.spyOn(console, "log").mockImplementation(() => {});
        await main(["start", "--corpus", corpus, "--repeats", "1"], env);
        const runId = log.mock.calls.at(-1)[0];
        log.mockRestore();
        const head = (
            await git("git", ["-C", origin, "rev-parse", "HEAD"])
        ).stdout.trim();
        expect(
            await readMeta(path.join(env.KNOSSOS_EVAL_RUNS, runId)),
        ).toMatchObject({
            knossosCommit: head,
            knossosDirty: true,
            pluginVersion: "1.2.3",
        });
    });
    it("records unknown provenance outside a git checkout", async () => {
        const { corpus, env } = await fixture();
        const log = vi.spyOn(console, "log").mockImplementation(() => {});
        const bare = {
            ...env,
            KNOSSOS_EVAL_SOURCE_ROOT: await tmp("eval-nogit-"),
        };
        await main(["start", "--corpus", corpus, "--repeats", "1"], bare);
        const runId = log.mock.calls.at(-1)[0];
        log.mockRestore();
        expect(
            await readMeta(path.join(env.KNOSSOS_EVAL_RUNS, runId)),
        ).toMatchObject({
            knossosCommit: null,
            knossosDirty: null,
            pluginVersion: null,
        });
    });
    it("records the commit on every row", async () => {
        const { env, runId, dir, origin } = await started([
            "--arms",
            "baseline",
        ]);
        const log = vi.spyOn(console, "log").mockImplementation(() => {});
        await main(["continue", runId, "--batch", "1"], env);
        log.mockRestore();
        const head = (
            await git("git", ["-C", origin, "rev-parse", "HEAD"])
        ).stdout.trim();
        const [row] = await readLedger(path.join(dir, "ledger.jsonl"));
        expect(row.knossosCommit).toBe(head);
    });
    it("refuses to continue on another commit or plugin version unless forced", async () => {
        const { env, runId, origin, pluginDir } = await started([
            "--arms",
            "baseline",
        ]);
        await writeFile(path.join(origin, "b.php"), "<?php\n");
        await commitAll(origin, "two");
        await expect(main(["continue", runId], env)).rejects.toThrow(
            /different build \(knossos commit \w+ -> \w+\).*--force-provenance/,
        );
        await setPluginVersion(pluginDir, "9.9.9");
        await expect(main(["continue", runId], env)).rejects.toThrow(
            /plugin version null -> 9\.9\.9/,
        );
        const log = vi.spyOn(console, "log").mockImplementation(() => {});
        const code = await main(
            ["continue", runId, "--batch", "1", "--force-provenance"],
            env,
        );
        log.mockRestore();
        expect(code).toBe(0);
    });
    it("warns from prepare when the plugin is not this checkout's version", async () => {
        const { env, runId, origin, pluginDir } = await started();
        await writeFile(path.join(origin, "version.txt"), "0.27.1\n");
        await setPluginVersion(pluginDir, "0.27.0");
        const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
        expect(await main(["prepare", runId, "--no-scan"], env)).toBe(0);
        const said = warn.mock.calls.map((c) => c[0]).join("\n");
        warn.mockRestore();
        expect(said).toMatch(/version 0\.27\.0, but this checkout is 0\.27\.1/);
    });
});

describe("checkProvenance", () => {
    const meta = { runId: "r", knossosCommit: "a", pluginVersion: "1" };
    it("passes the same build and only warns for a run without provenance", () => {
        expect(() =>
            checkProvenance(meta, { knossosCommit: "a", pluginVersion: "1" }),
        ).not.toThrow();
        const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
        checkProvenance(
            { runId: "old" },
            { knossosCommit: "a", pluginVersion: "1" },
        );
        expect(warn.mock.calls[0][0]).toMatch(/run old recorded no provenance/);
        warn.mockRestore();
    });
});

describe("dirtyPaths", () => {
    it("ignores run output and the answer keys, and reads rename targets", () => {
        expect(
            dirtyPaths(
                [
                    "?? .superpowers/",
                    " M tools/eval/corpus/tasks/demo/a.json",
                    " M src/Core.php",
                    "R  old.php -> tools/eval/corpus/x.json",
                    'R  a.php -> "b c.php"',
                    "",
                ].join("\n"),
            ),
        ).toEqual(["src/Core.php", "b c.php"]);
    });
});

describe("ablate", () => {
    it("refuses while a base-arm session is still pending", async () => {
        const { env, runId } = await started();
        const log = vi.spyOn(console, "log").mockImplementation(() => {});
        await main(["continue", runId, "--batch", "5"], env);
        log.mockRestore();
        await expect(main(["ablate", runId], env)).rejects.toThrow(
            /1 baseline, tools or full sessions are still pending/,
        );
    });
});
