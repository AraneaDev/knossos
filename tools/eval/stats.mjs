/**
 * Dev-only. Paired comparison of arms over tasks: the median of each task's
 * repeats, the per-task difference between two arms, and a seeded bootstrap
 * interval that decides whether that difference is visible at all.
 */
import { SCORABLE } from "./ledger.mjs";
import { rng } from "./rng.mjs";

export const METRICS = [
    { key: "f1", label: "F1", higherIsBetter: true },
    { key: "costUsd", label: "cost (USD)", higherIsBetter: false },
    { key: "turns", label: "turns", higherIsBetter: false },
    { key: "durationMs", label: "time (ms)", higherIsBetter: false },
    {
        key: "workTokens",
        label: "tokens excl. cache writes",
        higherIsBetter: false,
    },
];

/** Fewer paired tasks than this cannot carry a verdict. */
export const MIN_PAIRED_TASKS = 5;

export function median(xs) {
    if (xs.length === 0) return NaN;
    const s = [...xs].sort((a, b) => a - b);
    const m = Math.floor(s.length / 2);
    return s.length % 2 === 1 ? s[m] : (s[m - 1] + s[m]) / 2;
}

export function taskMedians(rows, metric) {
    const values = new Map();
    for (const r of rows.filter(
        (r) => SCORABLE.has(r.status) && Number.isFinite(r[metric]),
    )) {
        if (!values.has(r.arm)) values.set(r.arm, new Map());
        const byTask = values.get(r.arm);
        if (!byTask.has(r.task)) byTask.set(r.task, []);
        byTask.get(r.task).push(r[metric]);
    }
    return new Map(
        [...values].map(([arm, byTask]) => [
            arm,
            new Map([...byTask].map(([t, xs]) => [t, median(xs)])),
        ]),
    );
}

export function pairedDeltas(medians, a, b) {
    const left = medians.get(a) ?? new Map();
    const right = medians.get(b) ?? new Map();
    return [...left]
        .filter(([task]) => right.has(task))
        .map(([task, value]) => value - right.get(task));
}

const mean = (xs) => xs.reduce((s, x) => s + x, 0) / xs.length;

export function bootstrapCi(
    values,
    { resamples = 10000, level = 0.9, seed = 1 } = {},
) {
    if (values.length === 0) return null;
    const next = rng(seed);
    const means = [];
    for (let i = 0; i < resamples; i += 1) {
        let sum = 0;
        for (let j = 0; j < values.length; j += 1)
            sum += values[Math.floor(next() * values.length)];
        means.push(sum / values.length);
    }
    means.sort((a, b) => a - b);
    const tail = (1 - level) / 2;
    return {
        mean: mean(values),
        low: means[Math.floor(tail * (resamples - 1))],
        high: means[Math.ceil((1 - tail) * (resamples - 1))],
    };
}

export function verdict(ci, { higherIsBetter }, n) {
    if (!Number.isFinite(n))
        throw new TypeError(
            `verdict needs the number of paired tasks, got ${n}`,
        );
    if (ci === null) return "no data";
    if (n < MIN_PAIRED_TASKS) return "too few tasks";
    if (ci.low > 0) return higherIsBetter ? "helps" : "hurts";
    if (ci.high < 0) return higherIsBetter ? "hurts" : "helps";
    return "no detectable effect";
}

export function selectAblations(rows, tasks, { threshold = 0.2 } = {}) {
    const category = new Map(tasks.map((t) => [t.id, t.category]));
    const used = new Map();
    for (const r of rows.filter(
        (r) => r.arm === "full" && SCORABLE.has(r.status),
    )) {
        const cat = category.get(r.task);
        if (cat === undefined) continue;
        for (const [tool, count] of Object.entries(r.knossosCalls ?? {})) {
            // A recorded zero is a tool that was offered, not one that was used.
            if (!(count > 0)) continue;
            const key = `${cat}|${tool}`;
            if (!used.has(key)) used.set(key, new Set());
            used.get(key).add(r.task);
        }
    }
    const picked = [];
    for (const [key, taskSet] of used) {
        const [cat, tool] = key.split("|");
        const taskIds = tasks
            .filter((t) => t.category === cat)
            .map((t) => t.id);
        if (taskIds.length > 0 && taskSet.size / taskIds.length >= threshold)
            picked.push({ category: cat, tool, taskIds });
    }
    return picked.sort(
        (a, b) =>
            (a.category < b.category ? -1 : a.category > b.category ? 1 : 0) ||
            (a.tool < b.tool ? -1 : a.tool > b.tool ? 1 : 0),
    );
}
