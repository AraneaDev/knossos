/**
 * Dev-only. Turns a run's ledger into report.md: per-arm and per-category
 * verdicts, per-tool usage and ablation, the tasks Knossos made worse, and
 * the change since the previous run.
 */
import { SCORABLE } from "./ledger.mjs";
import { parseArm } from "./arms.mjs";
import {
    bootstrapCi,
    METRICS,
    MIN_PAIRED_TASKS,
    pairedDeltas,
    taskMedians,
    verdict,
} from "./stats.mjs";

const COMPARISONS = [
    ["tools", "baseline"],
    ["full", "baseline"],
    ["full", "tools"],
];
const EXCLUDED = ["contaminated", "failed"];
const COUNTED = ["no-answer", "timeout", "budget"];

const avg = (xs) =>
    xs.length === 0 ? NaN : xs.reduce((s, x) => s + x, 0) / xs.length;
const fmt = (x, digits = 2) => (Number.isFinite(x) ? x.toFixed(digits) : "-");
const signed = (x, digits = 2) =>
    Number.isFinite(x) ? `${x >= 0 ? "+" : ""}${x.toFixed(digits)}` : "-";
const pct = (x) => (Number.isFinite(x) ? `${Math.round(x * 100)}%` : "-");
const table = (head, body) => [
    `| ${head.join(" | ")} |`,
    `|${head.map(() => "---").join("|")}|`,
    ...body.map((r) => `| ${r.join(" | ")} |`),
];

const tally = (rows, arm, statuses) =>
    statuses
        .map(
            (s) =>
                `${s} ${rows.filter((r) => r.arm === arm && r.status === s).length}`,
        )
        .join(", ");

function armMeans(rows, arm, metric) {
    return avg([...(taskMedians(rows, metric).get(arm) ?? new Map()).values()]);
}

function summary(meta, rows) {
    const arms = [...new Set(rows.map((r) => r.arm))].filter(
        (a) => parseArm(a).minus === null,
    );
    const lines = ["## Summary", ""];
    lines.push(
        ...table(
            ["arm", "tasks", "F1", "cost (USD)", "turns", "time (s)"],
            arms.map((arm) => [
                arm,
                String((taskMedians(rows, "f1").get(arm) ?? new Map()).size),
                fmt(armMeans(rows, arm, "f1")),
                fmt(armMeans(rows, arm, "costUsd"), 3),
                fmt(armMeans(rows, arm, "turns"), 1),
                fmt(armMeans(rows, arm, "durationMs") / 1000, 0),
            ]),
        ),
    );
    lines.push(
        "",
        ...arms.map(
            (arm) =>
                `- ${arm}: excluded: ${tally(rows, arm, EXCLUDED)}; counted, scored as 0 or partial: ${tally(rows, arm, COUNTED)}`,
        ),
    );
    lines.push("", ...comparisons(rows, meta.seed));
    return lines;
}

function comparisons(rows, seed) {
    const body = [];
    let detect = null;
    for (const [a, b] of COMPARISONS) {
        for (const m of METRICS) {
            const deltas = pairedDeltas(taskMedians(rows, m.key), a, b);
            const ci = bootstrapCi(deltas, { seed });
            if (ci === null) continue;
            if (a === "full" && b === "baseline" && m.key === "f1")
                detect = { n: deltas.length, half: (ci.high - ci.low) / 2 };
            body.push([
                `${a} vs ${b}`,
                m.label,
                String(deltas.length),
                signed(ci.mean, 3),
                `[${signed(ci.low, 3)}, ${signed(ci.high, 3)}]`,
                verdict(ci, m, deltas.length),
            ]);
        }
    }
    const lines = table(
        [
            "comparison",
            "metric",
            "tasks",
            "mean delta",
            "90% interval",
            "verdict",
        ],
        body,
    );
    if (detect)
        lines.push(
            "",
            (detect.n < MIN_PAIRED_TASKS
                ? `Too few paired tasks (${detect.n}) to call an effect.`
                : `With ${detect.n} paired tasks the F1 interval for full vs baseline is about +/-${fmt(detect.half)} wide: smaller effects stay invisible in this run.`) +
                ` Notes and the band fire mostly on edits, so full vs tools on question tasks undersells the plugin layer.`,
        );
    return lines;
}

function byCategory(rows, tasks, seed) {
    const lines = ["## By category", ""];
    const body = [];
    for (const cat of [...new Set(tasks.map((t) => t.category))].sort()) {
        const ids = new Set(
            tasks.filter((t) => t.category === cat).map((t) => t.id),
        );
        const subset = rows.filter((r) => ids.has(r.task));
        const deltas = pairedDeltas(
            taskMedians(subset, "f1"),
            "full",
            "baseline",
        );
        const ci = bootstrapCi(deltas, { seed });
        body.push([
            cat,
            String(deltas.length),
            fmt(armMeans(subset, "baseline", "f1")),
            fmt(armMeans(subset, "full", "f1")),
            ci ? `[${signed(ci.low)}, ${signed(ci.high)}]` : "-",
            verdict(ci, { higherIsBetter: true }, deltas.length),
        ]);
    }
    return [
        ...lines,
        ...table(
            [
                "category",
                "tasks",
                "baseline F1",
                "full F1",
                "90% interval",
                "verdict",
            ],
            body,
        ),
    ];
}

function perTool(rows, seed) {
    const full = rows.filter((r) => r.arm === "full" && SCORABLE.has(r.status));
    const tools = [
        ...new Set(full.flatMap((r) => Object.keys(r.knossosCalls ?? {}))),
    ].sort();
    const body = tools.map((tool) => {
        const using = full.filter((r) => (r.knossosCalls?.[tool] ?? 0) > 0);
        const calls = using.reduce((s, r) => s + r.knossosCalls[tool], 0);
        const errors = using.reduce(
            (s, r) => s + (r.knossosErrors?.[tool] ?? 0),
            0,
        );
        const ablated = rows.filter((r) => r.arm === `full-minus-${tool}`);
        const ids = new Set(ablated.map((r) => r.task));
        const deltas = pairedDeltas(
            taskMedians(
                rows.filter((r) => ids.has(r.task)),
                "f1",
            ),
            "full",
            `full-minus-${tool}`,
        );
        const ci = bootstrapCi(deltas, { seed });
        return [
            tool,
            pct(using.length / full.length),
            pct(calls === 0 ? NaN : errors / calls),
            pct(using.filter((r) => r.grepAfter > 0).length / using.length),
            ci ? signed(ci.mean) : "not ablated",
            ci ? verdict(ci, { higherIsBetter: true }, deltas.length) : "-",
        ];
    });
    return [
        "## Per tool",
        "",
        ...table(
            [
                "tool",
                "used in",
                "error rate",
                "grep after",
                "ablation F1 delta",
                "verdict",
            ],
            body,
        ),
    ];
}

function belowBaseline(rows, defects) {
    const f1 = taskMedians(rows, "f1");
    const full = f1.get("full") ?? new Map();
    const base = f1.get("baseline") ?? new Map();
    const worse = [...full].filter(([t, v]) => base.has(t) && v < base.get(t));
    const lines = ["## Below baseline and key disagreements", ""];
    if (worse.length === 0)
        lines.push("No task scored lower with Knossos than without.");
    for (const [task, value] of worse) {
        const links = rows
            .filter(
                (r) =>
                    r.task === task &&
                    (r.arm === "full" || r.arm === "baseline"),
            )
            .map((r) => r.transcript);
        lines.push(
            `- ${task}: full ${fmt(value)} vs baseline ${fmt(base.get(task))}; ${links.join(", ")}`,
        );
    }
    lines.push("");
    if (defects.length === 0)
        lines.push(
            "No disagreements between answer keys and Knossos were recorded.",
        );
    for (const d of defects) {
        lines.push(
            `- ${d.task} (${d.tool}): missing ${d.missing.join(", ") || "none"}; extra ${d.extra.join(", ") || "none"}. Re-check the key before calling it a Knossos defect.`,
        );
    }
    return lines;
}

function sincePrevious(rows, previous) {
    const lines = ["## Since the previous run", ""];
    if (previous === null)
        return [...lines, "No earlier run on the same corpus to compare with."];
    const arms = [...new Set(rows.map((r) => r.arm))].filter(
        (a) => parseArm(a).minus === null,
    );
    const body = arms.map((arm) => [
        arm,
        fmt(armMeans(previous.rows, arm, "f1")),
        fmt(armMeans(rows, arm, "f1")),
        fmt(armMeans(previous.rows, arm, "costUsd"), 3),
        fmt(armMeans(rows, arm, "costUsd"), 3),
    ]);
    return [
        ...lines,
        `Compared with run ${previous.runId}.`,
        "",
        ...table(
            ["arm", "F1 before", "F1 now", "cost before", "cost now"],
            body,
        ),
    ];
}

export function buildReport({
    meta,
    rows,
    tasks,
    previous = null,
    defects = [],
}) {
    return [
        `# Eval run ${meta.runId}`,
        "",
        `Model ${meta.model}, arms ${meta.arms.join(", ")}, ${meta.repeats} repeats, seed ${meta.seed}, ${tasks.length} tasks.`,
        "",
        ...summary(meta, rows),
        "",
        ...byCategory(rows, tasks, meta.seed),
        "",
        ...perTool(rows, meta.seed),
        "",
        ...belowBaseline(rows, defects),
        "",
        ...sincePrevious(rows, previous),
        "",
    ].join("\n");
}
