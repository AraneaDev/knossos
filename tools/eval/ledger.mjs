/**
 * Dev-only. The run's plan and ledger. Every finished session is appended at
 * once, so a run stopped by a usage limit or a kill loses only the sessions
 * still in flight.
 */
import { Buffer } from "node:buffer";
import { appendFile, open, readFile } from "node:fs/promises";
import { rng } from "./rng.mjs";

export const TERMINAL = new Set([
    "scored",
    "no-answer",
    "contaminated",
    "timeout",
    "budget",
    "failed",
]);
export const SCORABLE = new Set(["scored", "no-answer", "timeout", "budget"]);

/** A session's key; a second pass is keyed apart, so a first pass and every run before passes keep the key they had. */
export const sessionKey = ({ task, arm, repeat, pass }) =>
    `${task}|${arm}|${repeat}${pass === 2 ? "|p2" : ""}`;

/**
 * Every session of a run, shuffled. With `passes: 2`, each session also gets
 * a second pass (`pass: 2`), which starts with the notes its first left.
 */
export function makePlan({ tasks, arms, repeats, seed, passes = 1 }) {
    const plan = [];
    for (const task of tasks) {
        for (const arm of arms) {
            for (let repeat = 1; repeat <= repeats; repeat += 1) {
                plan.push({ task: task.id, repo: task.repo, arm, repeat });
                if (passes === 2)
                    plan.push({
                        task: task.id,
                        repo: task.repo,
                        arm,
                        repeat,
                        pass: 2,
                    });
            }
        }
    }
    const next = rng(seed);
    for (let i = plan.length - 1; i > 0; i -= 1) {
        const j = Math.floor(next() * (i + 1));
        [plan[i], plan[j]] = [plan[j], plan[i]];
    }
    return plan;
}

export async function readLedger(file) {
    let text;
    try {
        text = await readFile(file, "utf8");
    } catch (error) {
        if (error.code === "ENOENT") return [];
        throw error;
    }
    return text
        .split("\n")
        .filter((l) => l.trim() !== "")
        .flatMap((l) => {
            try {
                const row = JSON.parse(l);
                // A line that parses to a number, string, array or null is not a row.
                return typeof row === "object" &&
                    row !== null &&
                    !Array.isArray(row)
                    ? [row]
                    : [];
            } catch {
                return [];
            }
        });
}

/** True when the file is not empty and its last byte is not a newline. Reads that one byte only. */
async function endsMidLine(file) {
    let handle;
    try {
        handle = await open(file, "r");
    } catch (error) {
        if (error.code === "ENOENT") return false;
        throw error;
    }
    try {
        const { size } = await handle.stat();
        if (size === 0) return false;
        const last = Buffer.alloc(1);
        await handle.read(last, 0, 1, size - 1);
        return last[0] !== 0x0a;
    } finally {
        await handle.close();
    }
}

/** One write per row, newline first if needed, so a cut-off line never merges with the next row. */
export async function appendRow(file, row) {
    const prefix = (await endsMidLine(file)) ? "\n" : "";
    await appendFile(file, `${prefix}${JSON.stringify(row)}\n`);
}

/**
 * The rows of first passes. A second pass started with its first pass's
 * notes, so anything comparing arms, choosing tasks or choosing tools reads
 * only these; a run of one pass has nothing else.
 */
export const firstPasses = (rows) => rows.filter((r) => (r.pass ?? 1) === 1);

export function pending(plan, rows) {
    const done = new Set(
        rows.filter((r) => TERMINAL.has(r.status)).map(sessionKey),
    );
    return plan.filter((e) => !done.has(sessionKey(e)));
}

/** The pending sessions that can start now: a second pass waits until its first is done, since it needs that pass's notes. */
export function runnable(plan, rows) {
    const done = new Set(
        rows.filter((r) => TERMINAL.has(r.status)).map(sessionKey),
    );
    return pending(plan, rows).filter(
        (e) => e.pass !== 2 || done.has(sessionKey({ ...e, pass: undefined })),
    );
}

export function limitState(record, { maxUtilization }) {
    if (
        record.rateLimit?.status === "rejected" ||
        record.result?.apiErrorStatus === 429
    )
        return "limited";
    const utilization = record.rateLimit?.fiveHourUtilization;
    if (typeof utilization === "number" && utilization >= maxUtilization)
        return "near-limit";
    return "ok";
}
