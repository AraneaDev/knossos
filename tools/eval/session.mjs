/**
 * Dev-only. Runs one eval session as its own process group, streams its
 * transcript to disk, and turns the result into a ledger row.
 */
import { spawn } from "node:child_process";
import { createWriteStream } from "node:fs";
import { extractAnswer, scoreAnswer } from "./answer.mjs";
import { contamination } from "./arms.mjs";
import { limitState } from "./ledger.mjs";
import { finalText, grepAfterGraph, knossosCounts } from "./transcript.mjs";

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const signalGroup = (pid, signal) => {
    try {
        process.kill(-pid, signal);
        return true;
    } catch {
        return false;
    }
};

/** SIGTERM to each process group, SIGKILL to any still there after `graceMs`. */
export async function terminateGroups(pids, graceMs = 5000) {
    let alive = pids.filter((pid) => signalGroup(pid, "SIGTERM"));
    const end = Date.now() + graceMs;
    while (alive.length > 0 && Date.now() < end) {
        await sleep(50);
        alive = alive.filter((pid) => signalGroup(pid, 0));
    }
    for (const pid of alive) signalGroup(pid, "SIGKILL");
}

export function runSession({
    claudeBin,
    argv,
    prompt,
    cwd,
    env,
    transcriptPath,
    timeoutMs,
    graceMs = 5000,
    onSpawn = () => {},
    onClose = () => {},
}) {
    return new Promise((resolve, reject) => {
        const started = Date.now();
        const child = spawn(claudeBin, argv, {
            cwd,
            env,
            detached: true,
            stdio: ["pipe", "pipe", "pipe"],
        });
        onSpawn(child.pid);
        const out = createWriteStream(transcriptPath);
        const lines = [];
        let buffer = "";
        let stderr = "";
        let timedOut = false;
        const kill = (signal) => signalGroup(child.pid, signal);
        let killTimer = null;
        const timer = setTimeout(() => {
            timedOut = true;
            kill("SIGTERM");
            killTimer = setTimeout(() => kill("SIGKILL"), graceMs);
            killTimer.unref();
        }, timeoutMs);
        out.on("error", (error) => {
            clearTimeout(timer);
            kill("SIGKILL");
            onClose(child.pid);
            reject(error);
        });
        child.stdout.setEncoding("utf8");
        child.stdout.on("data", (chunk) => {
            out.write(chunk);
            buffer += chunk;
            for (
                let nl = buffer.indexOf("\n");
                nl !== -1;
                nl = buffer.indexOf("\n")
            ) {
                lines.push(buffer.slice(0, nl));
                buffer = buffer.slice(nl + 1);
            }
        });
        child.stderr.setEncoding("utf8");
        child.stderr.on("data", (chunk) => {
            stderr = (stderr + chunk).slice(-4000);
        });
        child.on("error", (error) => {
            clearTimeout(timer);
            out.end();
            reject(error);
        });
        child.on("close", (exitCode) => {
            clearTimeout(timer);
            clearTimeout(killTimer);
            onClose(child.pid);
            if (buffer.trim() !== "") lines.push(buffer);
            const wallMs = Date.now() - started;
            out.end(() =>
                resolve({ lines, timedOut, exitCode, stderr, wallMs }),
            );
        });
        child.stdin.on("error", () => {});
        child.stdin.end(prompt);
    });
}

const BUDGET = /budget/;

function statusOf({ record, timedOut, limit, reasons, score, priorErrors }) {
    // A usage cut-off outranks a hang after it: the session must be rerun, not scored.
    if (limit === "limited") return "limited";
    // A session that saw what its arm must not see is excluded however it ended.
    // Without an init event nothing was seen yet, so a hang or a crash keeps its own status.
    if (reasons.length > 0 && record.init !== null) return "contaminated";
    if (timedOut) return "timeout";
    const r = record.result;
    // A budget stop, whatever is_error says.
    if (r !== null && BUDGET.test(`${r.subtype} ${r.terminalReason}`))
        return "budget";
    if (r === null || r.isError) return priorErrors >= 1 ? "failed" : "error";
    if (reasons.length > 0) return "contaminated";
    return score.status;
}

/** A field of the result event, or null when the session never produced one. */
const fromResult = (record, key) =>
    record.result === null ? null : (record.result[key] ?? 0);

export function buildRow({
    entry,
    task,
    language,
    workspace,
    record,
    timedOut,
    maxUtilization,
    priorErrors,
    transcript,
    model,
    knossosCommit = null,
    wallMs = null,
    exitCode = null,
    stderr = "",
    now = new Date(),
}) {
    const limit = limitState(record, { maxUtilization });
    const reasons = contamination(record, entry.arm);
    const answer = extractAnswer(finalText(record));
    const score = scoreAnswer(task.answer.expected, answer, {
        unit: task.answer.unit,
        language,
        workspace,
    });
    const { calls, errors } = knossosCounts(record);
    const input = fromResult(record, "inputTokens");
    const output = fromResult(record, "outputTokens");
    const cacheRead = fromResult(record, "cacheReadTokens");
    const cacheCreation = fromResult(record, "cacheCreationTokens");
    const tokenTotal = (...parts) =>
        record.result === null ? null : parts.reduce((s, x) => s + x, 0);
    return {
        task: entry.task,
        repo: entry.repo,
        arm: entry.arm,
        repeat: entry.repeat,
        model,
        status: statusOf({
            record,
            timedOut,
            limit,
            reasons,
            score,
            priorErrors,
        }),
        limit,
        reasons,
        answer,
        precision: score.precision,
        recall: score.recall,
        f1: score.f1,
        costUsd: fromResult(record, "costUsd"),
        turns: fromResult(record, "turns"),
        // A timed-out session has no result event, yet its time was spent.
        durationMs: record.result?.durationMs ?? wallMs,
        tokens: tokenTotal(input, output, cacheRead, cacheCreation),
        // Leaves out cache writes: the first session of an arm pays them for the ones after it.
        workTokens: tokenTotal(input, output, cacheRead),
        inputTokens: input,
        outputTokens: output,
        cacheReadTokens: cacheRead,
        cacheCreationTokens: cacheCreation,
        knossosCalls: calls,
        knossosErrors: errors,
        grepAfter: grepAfterGraph(record, task.subject),
        fiveHourUtilization: record.rateLimit?.fiveHourUtilization ?? null,
        transcript,
        knossosCommit,
        exitCode,
        stderr: stderr.slice(-2000),
        finishedAt: now.toISOString(),
    };
}
