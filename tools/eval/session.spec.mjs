import { spawn } from "node:child_process";
import { mkdtemp, readFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import { armArgv } from "./arms.mjs";
import { buildRow, runSession, terminateGroups } from "./session.mjs";
import { parseTranscript } from "./transcript.mjs";

const FAKE = fileURLToPath(
    new URL("./fixtures/fake-claude.mjs", import.meta.url),
);
const opts = {
    model: "sonnet",
    budgetUsd: 1,
    mcpConfigPath: "/e/mcp.json",
    pluginDir: "/k/.plugin",
};
const task = {
    id: "t1",
    subject: "Invoice::total",
    answer: { unit: "symbol", expected: ["App\\Billing::summarise"] },
};
const entry = { task: "t1", repo: "demo", arm: "full", repeat: 1 };

async function session(arm, env = {}, timeoutMs = 10000) {
    const dir = await mkdtemp(path.join(os.tmpdir(), "eval-session-"));
    const transcriptPath = path.join(dir, "t.jsonl");
    const outcome = await runSession({
        claudeBin: FAKE,
        argv: armArgv(arm, opts),
        prompt: "question",
        cwd: dir,
        env: { ...process.env, ...env },
        transcriptPath,
        timeoutMs,
        graceMs: 200,
    });
    return { outcome, transcriptPath };
}

const row = (outcome, arm, overrides = {}) =>
    buildRow({
        entry: { ...entry, arm },
        task,
        language: "php",
        workspace: "/w",
        record: parseTranscript(outcome.lines),
        timedOut: outcome.timedOut,
        maxUtilization: 0.85,
        priorErrors: 0,
        transcript: "transcripts/x.jsonl",
        model: "sonnet",
        now: new Date("2026-10-13T00:00:00Z"),
        ...overrides,
    });

describe("runSession", () => {
    it("streams the transcript to disk and returns its lines", async () => {
        const { outcome, transcriptPath } = await session("full");
        expect(outcome.timedOut).toBe(false);
        expect(
            (await readFile(transcriptPath, "utf8")).trim().split("\n"),
        ).toEqual(outcome.lines);
    });
    it("kills a session that runs past its timeout", async () => {
        const { outcome } = await session(
            "full",
            { FAKE_SLEEP_MS: "5000" },
            300,
        );
        expect(outcome.timedOut).toBe(true);
    });
});

describe("buildRow", () => {
    it("scores a clean session and counts its knossos calls", async () => {
        const r = row((await session("full")).outcome, "full");
        expect(r).toMatchObject({
            status: "scored",
            f1: 1,
            limit: "ok",
            knossosCalls: { list_usages: 1 },
            finishedAt: "2026-10-13T00:00:00.000Z",
        });
    });
    it("marks a rate-limited session limited, so it is rerun", async () => {
        const r = row(
            (
                await session("full", {
                    FAKE_LIMIT_STATUS: "rejected",
                    FAKE_RESULT_SUBTYPE: "error_during_execution",
                })
            ).outcome,
            "full",
        );
        expect(r.status).toBe("limited");
    });
    it("marks a session near the threshold as finished but stops the batch", async () => {
        const r = row(
            (await session("full", { FAKE_UTILIZATION: "0.9" })).outcome,
            "full",
        );
        expect(r.status).toBe("scored");
        expect(r.limit).toBe("near-limit");
    });
    it("gives a budget stop its own status and fails a second error", async () => {
        const budget = row(
            (
                await session("full", {
                    FAKE_RESULT_SUBTYPE: "error_max_budget_usd",
                })
            ).outcome,
            "full",
        );
        expect(budget.status).toBe("budget");
        const error = (
            await session("full", {
                FAKE_RESULT_SUBTYPE: "error_during_execution",
            })
        ).outcome;
        expect(row(error, "full").status).toBe("error");
        expect(row(error, "full", { priorErrors: 1 }).status).toBe("failed");
    });
    it("marks a baseline session that saw knossos as contaminated", async () => {
        const { outcome } = await session("full");
        expect(row(outcome, "baseline").status).toBe("contaminated");
    });
    it("records a timeout", async () => {
        const { outcome } = await session(
            "full",
            { FAKE_SLEEP_MS: "5000" },
            300,
        );
        expect(row(outcome, "full").status).toBe("timeout");
    });
});

describe("status order and diagnostics", () => {
    it("reruns a usage cut-off even when the session then hung", async () => {
        const { outcome } = await session("full", {
            FAKE_LIMIT_STATUS: "rejected",
        });
        expect(row(outcome, "full", { timedOut: true }).status).toBe("limited");
    });
    it("keeps the exit code and the tail of stderr on the row", async () => {
        const { outcome } = await session("full");
        const r = row(outcome, "full", {
            exitCode: 7,
            stderr: `${"x".repeat(3000)}tail`,
        });
        expect(r.exitCode).toBe(7);
        expect(r.stderr).toHaveLength(2000);
        expect(r.stderr.endsWith("tail")).toBe(true);
    });
});

/** A transcript built line by line, for outcomes the fake cannot produce. */
const lines = ({ servers = [], result = null } = {}) =>
    [
        {
            type: "system",
            subtype: "init",
            tools: ["Read"],
            mcp_servers: servers,
            plugins: [],
        },
        ...(result ? [{ type: "result", ...result }] : []),
    ].map((e) => JSON.stringify(e));
const knossosServer = [{ name: "knossos", status: "connected" }];

describe("contamination comes before how the session ended", () => {
    it("excludes a contaminated session that then timed out", () => {
        const r = row(
            { lines: lines({ servers: knossosServer }), timedOut: true },
            "baseline",
        );
        expect(r.status).toBe("contaminated");
    });
    it("excludes a contaminated session that ended in an error or a budget stop", () => {
        for (const subtype of [
            "error_during_execution",
            "error_max_budget_usd",
        ]) {
            const r = row(
                {
                    lines: lines({
                        servers: knossosServer,
                        result: { subtype, is_error: true },
                    }),
                    timedOut: false,
                },
                "baseline",
            );
            expect(r.status).toBe("contaminated");
        }
    });
    it("keeps a hang before the init event a timeout", () => {
        expect(row({ lines: [], timedOut: true }, "baseline").status).toBe(
            "timeout",
        );
    });
});

describe("budget stops", () => {
    it("detects a budget stop from the subtype when is_error is false", () => {
        const r = row(
            {
                lines: lines({
                    result: {
                        subtype: "error_max_budget_usd",
                        is_error: false,
                    },
                }),
                timedOut: false,
            },
            "baseline",
        );
        expect(r.status).toBe("budget");
    });
    it("detects a budget stop from the terminal reason alone", () => {
        const r = row(
            {
                lines: lines({
                    result: {
                        subtype: "success",
                        is_error: false,
                        terminal_reason: "max_budget_reached",
                    },
                }),
                timedOut: false,
            },
            "baseline",
        );
        expect(r.status).toBe("budget");
    });
});

describe("cost and time of a session without a result", () => {
    it("measures wall time and leaves cost, turns and tokens unknown", async () => {
        const { outcome } = await session(
            "full",
            { FAKE_SLEEP_MS: "5000" },
            300,
        );
        expect(outcome.wallMs).toBeGreaterThanOrEqual(300);
        const r = row(outcome, "full", { wallMs: outcome.wallMs });
        expect(r.status).toBe("timeout");
        expect(r.durationMs).toBe(outcome.wallMs);
        for (const key of [
            "costUsd",
            "turns",
            "tokens",
            "workTokens",
            "inputTokens",
            "outputTokens",
            "cacheReadTokens",
            "cacheCreationTokens",
        ])
            expect(r[key]).toBeNull();
    });
    it("prefers the result's duration over the wall time", async () => {
        const { outcome } = await session("full");
        expect(row(outcome, "full", { wallMs: 99999 }).durationMs).toBe(1234);
    });
    it("falls back to the wall time when the result has no duration", () => {
        const r = row(
            {
                lines: lines({ result: { subtype: "success" } }),
                timedOut: false,
            },
            "baseline",
            { wallMs: 4321 },
        );
        expect(r.durationMs).toBe(4321);
    });
});

describe("token fields", () => {
    it("keeps each token count and a total that leaves out cache writes", () => {
        const r = row(
            {
                lines: lines({
                    result: {
                        subtype: "success",
                        usage: {
                            input_tokens: 10,
                            output_tokens: 20,
                            cache_read_input_tokens: 300,
                            cache_creation_input_tokens: 4000,
                        },
                    },
                }),
                timedOut: false,
            },
            "baseline",
            { knossosCommit: "abc123" },
        );
        expect(r).toMatchObject({
            inputTokens: 10,
            outputTokens: 20,
            cacheReadTokens: 300,
            cacheCreationTokens: 4000,
            tokens: 4330,
            workTokens: 330,
            knossosCommit: "abc123",
        });
    });
});

describe("runSession failures", () => {
    it("kills the group and rejects when the transcript cannot be written", async () => {
        await expect(
            runSession({
                claudeBin: FAKE,
                argv: [],
                prompt: "q",
                cwd: os.tmpdir(),
                env: { ...process.env, FAKE_SLEEP_MS: "5000" },
                transcriptPath: "/nonexistent-eval-dir/t.jsonl",
                timeoutMs: 10000,
            }),
        ).rejects.toThrow(/ENOENT/);
    });
    it("reports the pid it spawned and the pid that closed", async () => {
        const seen = [];
        const dir = await mkdtemp(path.join(os.tmpdir(), "eval-session-"));
        await runSession({
            claudeBin: FAKE,
            argv: [],
            prompt: "q",
            cwd: dir,
            env: process.env,
            transcriptPath: path.join(dir, "t.jsonl"),
            timeoutMs: 10000,
            onSpawn: (pid) => seen.push(["spawn", pid]),
            onClose: (pid) => seen.push(["close", pid]),
        });
        expect(seen.map(([k]) => k)).toEqual(["spawn", "close"]);
        expect(seen[0][1]).toBe(seen[1][1]);
    });
});

describe("terminateGroups", () => {
    const exited = (child) =>
        new Promise((resolve) =>
            child.once("exit", (code, signal) => resolve(signal)),
        );

    it("stops a process group with SIGTERM", async () => {
        const child = spawn("sleep", ["30"], {
            detached: true,
            stdio: "ignore",
        });
        const gone = exited(child);
        await terminateGroups([child.pid], 2000);
        expect(await gone).toBe("SIGTERM");
    });
    it("escalates to SIGKILL for a group that ignores SIGTERM", async () => {
        const child = spawn("sh", ["-c", 'trap "" TERM; sleep 30 & wait'], {
            detached: true,
            stdio: "ignore",
        });
        const gone = exited(child);
        await new Promise((resolve) => setTimeout(resolve, 200));
        await terminateGroups([child.pid], 300);
        expect(await gone).toBe("SIGKILL");
    });
    it("ignores a group that is already gone", async () => {
        await expect(
            terminateGroups([2 ** 22 + 12345], 100),
        ).resolves.toBeUndefined();
    });
});
