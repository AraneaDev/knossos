/**
 * Dev-only. The run's plan and ledger. Every finished session is appended at
 * once, so a run stopped by a usage limit or a kill loses only the sessions
 * still in flight.
 */
import { appendFile, readFile } from "node:fs/promises";
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

export const sessionKey = ({ task, arm, repeat }) => `${task}|${arm}|${repeat}`;

export function makePlan({ tasks, arms, repeats, seed }) {
    const plan = [];
    for (const task of tasks) {
        for (const arm of arms) {
            for (let repeat = 1; repeat <= repeats; repeat += 1)
                plan.push({ task: task.id, repo: task.repo, arm, repeat });
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
                return [JSON.parse(l)];
            } catch {
                return [];
            }
        });
}

/** One write per row, newline first if needed, so a cut-off line never merges with the next row. */
export async function appendRow(file, row) {
    let prefix = "";
    try {
        const text = await readFile(file, "utf8");
        if (text !== "" && !text.endsWith("\n")) prefix = "\n";
    } catch (error) {
        if (error.code !== "ENOENT") throw error;
    }
    await appendFile(file, `${prefix}${JSON.stringify(row)}\n`);
}

export function pending(plan, rows) {
    const done = new Set(
        rows.filter((r) => TERMINAL.has(r.status)).map(sessionKey),
    );
    return plan.filter((e) => !done.has(sessionKey(e)));
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
