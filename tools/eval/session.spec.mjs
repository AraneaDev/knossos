import { mkdtemp, readFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import { armArgv } from "./arms.mjs";
import { buildRow, runSession } from "./session.mjs";
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
