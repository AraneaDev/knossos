#!/usr/bin/env node
/**
 * Dev-only. Reads a calibration run of the hard tier and says which candidates
 * stay: a task is kept when the average of its `baseline` and `full` median F1
 * is at most KEEP_AT_MOST. Selecting on both arms, never one, keeps a task one
 * arm fumbled by chance from biasing the main run toward the other. It writes
 * nothing: a dropped candidate is deleted by hand, with its reason recorded.
 */
import { fileURLToPath } from "node:url";
import { tierOf } from "./corpus.mjs";
import { readLedger } from "./ledger.mjs";
import { openRun, settings } from "./run.mjs";
import { taskMedians } from "./stats.mjs";

/** The highest average F1 a hard candidate may have and still be kept. */
export const KEEP_AT_MOST = 0.85;

/**
 * One line per hard task, in id order: each arm's median F1, their average,
 * and `keep`, `drop`, or which arm has no scored session.
 */
export function calibrate(rows, tasks) {
    const medians = taskMedians(rows, "f1");
    const of = (arm, id) => medians.get(arm)?.get(id) ?? null;
    return tasks
        .filter((t) => tierOf(t) === "hard")
        .map((t) => t.id)
        .sort()
        .map((task) => {
            const baseline = of("baseline", task);
            const full = of("full", task);
            if (baseline === null || full === null)
                return {
                    task,
                    baseline,
                    full,
                    average: null,
                    verdict:
                        baseline === null ? "missing baseline" : "missing full",
                };
            const average = Math.round(((baseline + full) / 2) * 1000) / 1000;
            return {
                task,
                baseline,
                full,
                average,
                verdict: average <= KEEP_AT_MOST ? "keep" : "drop",
            };
        });
}

const two = (x) => (x === null ? "-" : x.toFixed(2));

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    if (!process.argv[2]) {
        console.error("usage: node tools/eval/calibrate.mjs <run-id>");
        process.exit(2);
    }
    const run = await openRun(process.argv[2], settings());
    for (const line of calibrate(
        await readLedger(run.ledger),
        run.corpus.tasks,
    )) {
        console.log(
            `${line.verdict.padEnd(16)} ${line.task}  baseline ${two(line.baseline)}  full ${two(line.full)}  average ${two(line.average)}`,
        );
    }
}
