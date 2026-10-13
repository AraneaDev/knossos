/**
 * Dev-only. Turns a run's ledger into report.md: per-arm and per-category
 * verdicts, per-tool usage and ablation, the tasks Knossos made worse, and
 * the change since the previous run.
 */
import { readdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { SCORABLE, readLedger } from "./ledger.mjs";
import { parseArm } from "./arms.mjs";
import { corpusHashFor, plannedCorpus, tierOf } from "./corpus.mjs";
import { evalPaths } from "./env.mjs";
import { openRun, settings } from "./run.mjs";
import {
    bootstrapCi,
    comparisonSeed,
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
const metric = (key) => METRICS.find((m) => m.key === key);
// F1 for every pair of arms, cost only where it matters: what the full plugin adds over no Knossos.
const CATEGORY_COMPARISONS = [
    ...COMPARISONS.map(([a, b]) => [a, b, metric("f1")]),
    ["full", "baseline", metric("costUsd")],
];
/** A task counts as below baseline only when full's median F1 trails baseline's by more than this. */
export const BELOW_BASELINE_TOLERANCE = 0.01;
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

function summary(meta, rows, heading = "## Summary") {
    const arms = [...new Set(rows.map((r) => r.arm))].filter(
        (a) => parseArm(a).minus === null,
    );
    const lines = [heading, ""];
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
    lines.push("", ...comparisons(rows, meta.seed, meta.arms));
    return lines;
}

/**
 * The comparisons whose two arms the run planned: a run without `tools` has
 * nothing to say about it. A planned arm with no scored pairs still gets its
 * `no data` row, so an arm that failed throughout stays visible.
 */
const plannedPairs = (pairs, arms) =>
    pairs.filter(([a, b]) => arms.includes(a) && arms.includes(b));

/** A metric's value in the unit its label names: durations are kept in ms and shown in seconds. */
const shown = (m, x) => x * (m.scale ?? 1);

function comparisons(rows, seed, arms) {
    const body = [];
    let detect = null;
    for (const [a, b] of plannedPairs(COMPARISONS, arms)) {
        for (const m of METRICS) {
            const deltas = pairedDeltas(taskMedians(rows, m.key), a, b);
            const ci = bootstrapCi(deltas, {
                seed: comparisonSeed(seed, "overall", a, b, m.key),
            });
            if (
                ci !== null &&
                a === "full" &&
                b === "baseline" &&
                m.key === "f1"
            )
                detect = { n: deltas.length, half: (ci.high - ci.low) / 2 };
            body.push([
                `${a} vs ${b}`,
                m.label,
                String(deltas.length),
                ci ? signed(shown(m, ci.mean), 3) : "-",
                ci
                    ? `[${signed(shown(m, ci.low), 3)}, ${signed(shown(m, ci.high), 3)}]`
                    : "-",
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
    lines.push(
        "",
        "Cache warm-up makes the first session of an arm costlier, so cost alone is confounded; tokens excl. cache writes leaves that warm-up out.",
    );
    if (detect)
        lines.push(
            "",
            (detect.n < MIN_PAIRED_TASKS
                ? `Too few paired tasks (${detect.n}) to call an effect.`
                : `With ${detect.n} paired tasks the F1 interval for full vs baseline is about +/-${fmt(detect.half)} wide: smaller effects stay invisible in this run.`) +
                (arms.includes("tools")
                    ? ` Notes and the band fire mostly on edits, so full vs tools on question tasks undersells the plugin layer.`
                    : ""),
        );
    return lines;
}

function byCategory(rows, tasks, seed, arms) {
    const body = [];
    for (const cat of [...new Set(tasks.map((t) => t.category))].sort()) {
        const ids = new Set(
            tasks.filter((t) => t.category === cat).map((t) => t.id),
        );
        const subset = rows.filter((r) => ids.has(r.task));
        for (const [a, b, m] of plannedPairs(CATEGORY_COMPARISONS, arms)) {
            const deltas = pairedDeltas(taskMedians(subset, m.key), a, b);
            const ci = bootstrapCi(deltas, {
                seed: comparisonSeed(seed, "category", cat, a, b, m.key),
            });
            const digits = m.key === "costUsd" ? 3 : 2;
            body.push([
                cat,
                `${a} vs ${b}`,
                m.label,
                String(deltas.length),
                ci ? signed(ci.mean, digits) : "-",
                ci
                    ? `[${signed(ci.low, digits)}, ${signed(ci.high, digits)}]`
                    : "-",
                verdict(ci, m, deltas.length),
            ]);
        }
    }
    return [
        "## By category",
        "",
        ...table(
            [
                "category",
                "comparison",
                "metric",
                "tasks",
                "mean delta",
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
        const ci = bootstrapCi(deltas, {
            seed: comparisonSeed(seed, "tool", tool),
        });
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
    const worse = [...full].filter(
        ([t, v]) => base.has(t) && v < base.get(t) - BELOW_BASELINE_TOLERANCE,
    );
    const lines = ["## Below baseline and key disagreements", ""];
    if (worse.length === 0)
        lines.push(
            `No task's median F1 was more than ${BELOW_BASELINE_TOLERANCE} lower with full than with baseline.`,
        );
    for (const [task, value] of worse) {
        const links = rows
            .filter(
                (r) =>
                    r.task === task &&
                    (r.arm === "full" || r.arm === "baseline") &&
                    !EXCLUDED.includes(r.status) &&
                    typeof r.transcript === "string" &&
                    r.transcript !== "",
            )
            .map((r) => r.transcript);
        lines.push(
            `- ${task}: full ${fmt(value)} vs baseline ${fmt(base.get(task))}; ${links.join(", ")}`,
        );
    }
    lines.push("");
    // A defect written before kinds existed is a disagreement.
    const unresolved = defects.filter((d) => d.kind === "unresolved");
    const disagreements = defects.filter((d) => d.kind !== "unresolved");
    if (disagreements.length === 0)
        lines.push(
            "No disagreements between answer keys and Knossos were recorded.",
        );
    for (const d of disagreements) {
        lines.push(
            `- ${d.task} (${d.tool}): missing ${(d.missing ?? []).join(", ") || "none"}; extra ${(d.extra ?? []).join(", ") || "none"}. Re-check the key before calling it a Knossos defect.`,
        );
    }
    if (unresolved.length > 0) {
        lines.push(
            "",
            "Knossos did not resolve the subject of these tasks, so its answer was never compared with the key:",
            "",
        );
        for (const d of unresolved)
            lines.push(
                `- ${d.task} (${d.tool}): query ${d.query ?? "unknown"}; candidates ${(d.candidates ?? []).join(", ") || "none"}`,
            );
    }
    return lines;
}

/** Per arm, F1 and cost against the previous run over the tasks both runs scored. */
function sincePrevious(rows, previous) {
    const lines = ["## Since the previous run", ""];
    if (previous === null)
        return [...lines, "No earlier run on the same corpus to compare with."];
    const arms = [...new Set(rows.map((r) => r.arm))].filter(
        (a) => parseArm(a).minus === null,
    );
    const body = [];
    const skipped = [];
    for (const arm of arms) {
        for (const m of [metric("f1"), metric("costUsd")]) {
            const now = taskMedians(rows, m.key).get(arm) ?? new Map();
            const before =
                taskMedians(previous.rows, m.key).get(arm) ?? new Map();
            const paired = [...now.keys()].filter((t) => before.has(t));
            const deltas = pairedDeltas(
                new Map([
                    ["now", now],
                    ["before", before],
                ]),
                "now",
                "before",
            );
            if (deltas.length < MIN_PAIRED_TASKS) {
                skipped.push(
                    `- ${arm} ${m.label}: ${deltas.length} tasks pair with the previous run, fewer than ${MIN_PAIRED_TASKS}, so it is not compared.`,
                );
                continue;
            }
            const digits = m.key === "costUsd" ? 3 : 2;
            body.push([
                arm,
                m.label,
                String(deltas.length),
                fmt(avg(paired.map((t) => before.get(t))), digits),
                fmt(avg(paired.map((t) => now.get(t))), digits),
                signed(avg(deltas), digits),
            ]);
        }
    }
    return [
        ...lines,
        `Compared with run ${previous.runId}, task by task over the tasks both runs scored.`,
        "",
        ...(body.length > 0
            ? table(
                  ["arm", "metric", "tasks", "before", "now", "mean delta"],
                  body,
              )
            : []),
        ...(body.length > 0 && skipped.length > 0 ? [""] : []),
        ...skipped,
    ];
}

/** The build a run measured and, when prepare recorded it, how long the scans took. */
function provenanceLines(meta, prepare) {
    const dirty =
        meta.knossosDirty === true
            ? " with local changes"
            : meta.knossosDirty === false
              ? ", clean"
              : "";
    const lines = [
        `Knossos commit ${meta.knossosCommit ?? "unknown"}${dirty}, plugin version ${meta.pluginVersion ?? "unknown"}.`,
    ];
    if (prepare !== null) {
        const ms = Object.values(prepare).filter(Number.isFinite);
        lines.push(
            `Scanning ${ms.length} repos took ${fmt(ms.reduce((s, x) => s + x, 0) / 1000, 1)} s in all.`,
        );
    }
    return lines;
}

/** The metrics a second pass is read for: what the notes might save, and whether the answer held. */
const LEARNING_METRICS = ["turns", "durationMs", "costUsd", "f1"];

/**
 * What a second pass made of the notes its first left, for a two-pass run:
 * per arm, how often a first pass left a note, and the second pass against
 * the first, paired by task. `baseline` has no Knossos and so no notes: its
 * second pass shows what repeating a task alone does, the control.
 */
function learning(meta, rows) {
    const label = (arm) => (arm === "baseline" ? "baseline (control)" : arm);
    const arms = meta.arms.filter((a) => parseArm(a).minus === null);
    const firsts = (arm) =>
        rows.filter(
            (r) =>
                r.arm === arm && (r.pass ?? 1) === 1 && SCORABLE.has(r.status),
        );
    const wrote = table(
        ["arm", "first passes that left a note"],
        arms.map((arm) => {
            const first = firsts(arm);
            return [
                label(arm),
                first.length === 0
                    ? "-"
                    : pct(
                          first.filter((r) => (r.notesWritten ?? 0) > 0)
                              .length / first.length,
                      ),
            ];
        }),
    );
    const body = [];
    for (const arm of arms) {
        const byPass = rows
            .filter((r) => r.arm === arm)
            .map((r) => ({
                ...r,
                arm: (r.pass ?? 1) === 2 ? "second" : "first",
            }));
        for (const key of LEARNING_METRICS) {
            const m = metric(key);
            const deltas = pairedDeltas(
                taskMedians(byPass, key),
                "second",
                "first",
            );
            const ci = bootstrapCi(deltas, {
                seed: comparisonSeed(meta.seed, "learning", arm, key),
            });
            body.push([
                label(arm),
                m.label,
                String(deltas.length),
                ci ? signed(shown(m, ci.mean), 3) : "-",
                ci
                    ? `[${signed(shown(m, ci.low), 3)}, ${signed(shown(m, ci.high), 3)}]`
                    : "-",
                verdict(ci, m, deltas.length),
            ]);
        }
    }
    return [
        "## Learning",
        "",
        "Each session ran twice; the second pass started with the notes its first left.",
        "",
        ...wrote,
        "",
        ...table(
            [
                "arm",
                "metric",
                "tasks",
                "second minus first",
                "90% interval",
                "verdict",
            ],
            body,
        ),
    ];
}

export function buildReport({
    meta,
    rows: allRows,
    tasks,
    previous = null,
    defects = [],
    prepare = null,
}) {
    // A two-pass run's second passes started with notes: every section but
    // the Learning one reads the first passes, so the arms compare as in any run.
    const twoPass = meta.passes === 2;
    const rows = twoPass ? allRows.filter((r) => (r.pass ?? 1) === 1) : allRows;
    // A run that holds both tiers gets a summary per tier: pooled, an easy
    // tier at F1 1.0 would hide what the hard one shows.
    const tierOfTask = new Map(tasks.map((t) => [t.id, tierOf(t)]));
    const tierOfRow = (r) => tierOfTask.get(r.task) ?? "easy";
    const tiers = [...new Set(rows.map(tierOfRow))].sort();
    const summaries =
        tiers.length > 1
            ? tiers.flatMap((tier, i) => [
                  ...(i > 0 ? [""] : []),
                  ...summary(
                      meta,
                      rows.filter((r) => tierOfRow(r) === tier),
                      `## Summary, ${tier} tier`,
                  ),
              ])
            : summary(meta, rows);
    return [
        `# Eval run ${meta.runId}`,
        "",
        `Model ${meta.model}, arms ${meta.arms.join(", ")}, ${meta.repeats} repeats, seed ${meta.seed}, ${tasks.length} tasks.`,
        ...provenanceLines(meta, prepare),
        "",
        ...summaries,
        "",
        ...byCategory(rows, tasks, meta.seed, meta.arms),
        "",
        ...perTool(rows, meta.seed),
        "",
        ...belowBaseline(rows, defects),
        "",
        ...sincePrevious(rows, previous),
        "",
        ...(twoPass ? [...learning(meta, allRows), ""] : []),
    ].join("\n");
}

export async function previousRun(run, s) {
    const ids = (await readdir(s.runsDir))
        .filter((id) => id < run.meta.runId)
        .sort()
        .reverse();
    for (const id of ids) {
        try {
            const meta = JSON.parse(
                await readFile(path.join(s.runsDir, id, "meta.json"), "utf8"),
            );
            // The tasks the other run planned, as this corpus has them now; a
            // run without a readable plan is held to the whole corpus.
            const otherPlan = await readFile(
                path.join(s.runsDir, id, "plan.json"),
                "utf8",
            ).then(JSON.parse, () => null);
            const theirs = Array.isArray(otherPlan)
                ? plannedCorpus(
                      run.corpus,
                      otherPlan.map((e) => e.task),
                  )
                : run.corpus;
            if (
                // Like with like: hash those tasks the way the other run's meta was written.
                meta.corpusHash === corpusHashFor(theirs, meta) &&
                meta.model === run.meta.model &&
                // A run of another tier shares no tasks to pair with.
                (meta.tier ?? null) === (run.meta.tier ?? null)
            ) {
                return {
                    runId: id,
                    rows: await readLedger(
                        path.join(s.runsDir, id, "ledger.jsonl"),
                    ),
                };
            }
        } catch {
            // Not a run directory.
        }
    }
    return null;
}

async function readPrepare(dir) {
    try {
        return JSON.parse(
            await readFile(path.join(dir, "prepare.json"), "utf8"),
        );
    } catch {
        return null;
    }
}

export async function readDefects(dir) {
    let files;
    try {
        files = (await readdir(dir)).filter((f) => f.endsWith(".json")).sort();
    } catch (error) {
        if (error.code === "ENOENT") return [];
        throw error;
    }
    return Promise.all(
        files.map(async (f) => {
            try {
                return JSON.parse(await readFile(path.join(dir, f), "utf8"));
            } catch (error) {
                // One unreadable key disagreement must not make the others vanish silently.
                throw new Error(`${path.join(dir, f)}: ${error.message}`, {
                    cause: error,
                });
            }
        }),
    );
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    if (!process.argv[2]) {
        console.error("usage: node tools/eval/report.mjs <run-id>");
        process.exit(2);
    }
    const s = settings();
    const run = await openRun(process.argv[2], s);
    const md = buildReport({
        meta: run.meta,
        rows: await readLedger(run.ledger),
        tasks: run.tasks,
        previous: await previousRun(run, s),
        defects: await readDefects(evalPaths(s.evalRoot).defects),
        prepare: await readPrepare(run.dir),
    });
    await writeFile(path.join(run.dir, "report.md"), md);
    console.log(path.join(run.dir, "report.md"));
}
