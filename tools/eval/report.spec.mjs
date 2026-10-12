import { execFile } from "node:child_process";
import { fileURLToPath } from "node:url";
import { promisify } from "node:util";
import { describe, expect, it } from "vitest";
import { buildReport } from "./report.mjs";

const tasks = [
    { id: "c1", category: "callers" },
    { id: "c2", category: "callers" },
    { id: "i1", category: "impact" },
];
const row = (task, arm, f1, extra = {}) => ({
    task,
    arm,
    repeat: 1,
    status: "scored",
    f1,
    costUsd: 0.1,
    turns: 4,
    durationMs: 30000,
    knossosCalls: {},
    knossosErrors: {},
    grepAfter: 0,
    transcript: `transcripts/${task}--${arm}--1.jsonl`,
    ...extra,
});
const rows = [
    row("c1", "baseline", 0.5),
    row("c2", "baseline", 0.6),
    row("i1", "baseline", 0.9),
    row("c1", "tools", 0.9, { knossosCalls: { list_usages: 1 } }),
    row("c2", "tools", 0.9),
    row("i1", "tools", 0.9),
    row("c1", "full", 1, {
        knossosCalls: { list_usages: 2 },
        knossosErrors: { list_usages: 1 },
        grepAfter: 1,
    }),
    row("c2", "full", 1),
    row("i1", "full", 0.4),
    row("c1", "full-minus-list_usages", 0.5),
    row("c2", "full", 0, { status: "contaminated", repeat: 2 }),
];
const meta = {
    runId: "r1",
    model: "sonnet",
    arms: ["baseline", "tools", "full"],
    repeats: 1,
    seed: 1,
};

describe("buildReport", () => {
    const md = buildReport({
        meta,
        rows,
        tasks,
        defects: [
            { task: "c1", tool: "list_usages", missing: ["A::x"], extra: [] },
        ],
    });
    it("has every section", () => {
        for (const heading of [
            "## Summary",
            "## By category",
            "## Per tool",
            "## Below baseline and key disagreements",
            "## Since the previous run",
        ]) {
            expect(md).toContain(heading);
        }
    });
    it("summarises each arm and counts excluded sessions", () => {
        expect(md).toMatch(/\| full \| 3 \|/);
        expect(md).toMatch(
            /- full: excluded: contaminated 1, failed 0; counted, scored as 0 or partial: no-answer 0, timeout 0, budget 0/,
        );
    });
    it("gives each comparison a verdict", () => {
        expect(md).toMatch(/\| full vs baseline \| F1 \|/);
        expect(md).toMatch(
            /\| full vs baseline \| F1 \| 3 \|.*\| too few tasks \|/,
        );
        expect(md).toMatch(/Too few paired tasks \(3\) to call an effect/);
    });
    it("reports tool usage, errors, grep-after and the ablation delta", () => {
        expect(md).toMatch(/\| list_usages \| 33% \| 50% \| 100% \| \+0\.50/);
    });
    it("links tasks where full scored below baseline, and lists key disagreements", () => {
        expect(md).toContain("i1");
        expect(md).toContain("transcripts/i1--full--1.jsonl");
        expect(md).toMatch(/c1.*list_usages.*missing A::x/);
    });
    it("says there is no previous run to compare with", () => {
        expect(md).toMatch(/No earlier run/);
    });
    it("compares with a previous run when given one", () => {
        const withPrev = buildReport({
            meta,
            rows,
            tasks,
            previous: { runId: "r0", rows: rows.map((r) => ({ ...r, f1: 0 })) },
        });
        expect(withPrev).toMatch(/r0/);
    });
});

describe("buildReport with enough paired tasks", () => {
    const many = Array.from({ length: 6 }, (_, i) => ({
        id: `k${i}`,
        category: "callers",
    }));
    const big = many.flatMap((t) => [
        row(t.id, "baseline", 0.4, { costUsd: 0.1 }),
        row(t.id, "full", 0.9, { costUsd: 0.2 }),
    ]);
    const md = buildReport({
        meta: { ...meta, arms: ["baseline", "full"] },
        rows: big,
        tasks: many,
    });
    it("calls a clear F1 gain helps", () => {
        expect(md).toMatch(/\| full vs baseline \| F1 \| 6 \|.*\| helps \|/);
        expect(md).not.toMatch(/Too few paired tasks/);
    });
    it("calls a higher cost hurts, since lower is better", () => {
        expect(md).toMatch(
            /\| full vs baseline \| cost \(USD\) \| 6 \|.*\| hurts \|/,
        );
    });
    it("says so when no task scored lower with Knossos", () => {
        expect(md).toContain("No task scored lower with Knossos than without.");
    });
    it("prints no data for a category without paired tasks", () => {
        const other = buildReport({
            meta,
            rows: [row("a1", "baseline", 0.5)],
            tasks: [{ id: "a1", category: "x" }],
        });
        expect(other).toMatch(
            /\| x \| full vs baseline \| F1 \| 0 \| - \| - \| no data \|/,
        );
    });
});

describe("buildReport with an arm missing", () => {
    it("prints a no data row for a comparison without pairs", () => {
        const md = buildReport({
            meta: { ...meta, arms: ["baseline", "full"] },
            rows: rows.filter((r) => r.arm !== "tools"),
            tasks,
        });
        expect(md).toMatch(
            /\| tools vs baseline \| F1 \| 0 \| - \| - \| no data \|/,
        );
    });
});

describe("report CLI", () => {
    it("prints a usage line and exits 2 without a run id", async () => {
        const file = fileURLToPath(new URL("./report.mjs", import.meta.url));
        const result = await promisify(execFile)("node", [file]).catch(
            (e) => e,
        );
        expect(result.code).toBe(2);
        expect(result.stderr).toContain(
            "usage: node tools/eval/report.mjs <run-id>",
        );
    });
});

describe("report header", () => {
    it("shows the commit, the dirty flag, the plugin version and the scan time", () => {
        const md = buildReport({
            meta: {
                ...meta,
                knossosCommit: "abc123",
                knossosDirty: true,
                pluginVersion: "0.27.0",
            },
            rows,
            tasks,
            prepare: { demo: 1500, other: 2500 },
        });
        expect(md).toContain(
            "Knossos commit abc123 with local changes, plugin version 0.27.0.",
        );
        expect(md).toContain("Scanning 2 repos took 4.0 s in all.");
    });
    it("says unknown for a run without provenance and omits a missing scan time", () => {
        const md = buildReport({ meta, rows, tasks });
        expect(md).toContain("Knossos commit unknown, plugin version unknown.");
        expect(md).not.toContain("Scanning");
        expect(
            buildReport({
                meta: { ...meta, knossosCommit: "a", knossosDirty: false },
                rows,
                tasks,
            }),
        ).toContain("Knossos commit a, clean,");
    });
});

describe("report metrics", () => {
    it("compares tokens excluding cache writes and says why", () => {
        const md = buildReport({
            meta,
            rows: rows.map((r) => ({ ...r, workTokens: 100 })),
            tasks,
        });
        expect(md).toMatch(
            /\| full vs baseline \| tokens excl\. cache writes \| 3 \|/,
        );
        expect(md).toMatch(
            /Cache warm-up makes the first session of an arm costlier, so cost alone is confounded/,
        );
    });
    it("prints an unknown cost as - rather than as free", () => {
        const md = buildReport({
            meta,
            rows: rows.map((r) =>
                r.arm === "tools" ? { ...r, costUsd: null, turns: null } : r,
            ),
            tasks,
        });
        expect(md).toMatch(/\| tools \| 3 \| 0\.90 \| - \| - \| 30 \|/);
    });
});

describe("by category", () => {
    it("shows all three F1 comparisons and full vs baseline cost", () => {
        const md = buildReport({ meta, rows, tasks });
        for (const [cmp, m] of [
            ["tools vs baseline", "F1"],
            ["full vs baseline", "F1"],
            ["full vs tools", "F1"],
            ["full vs baseline", "cost \\(USD\\)"],
        ])
            expect(md).toMatch(
                new RegExp(`\\| callers \\| ${cmp} \\| ${m} \\| 2 \\|`),
            );
        expect(md).not.toMatch(/\| callers \| tools vs baseline \| cost/);
    });
});

describe("since the previous run", () => {
    const many = Array.from({ length: 6 }, (_, i) => ({
        id: `k${i}`,
        category: "callers",
    }));
    const now = many.flatMap((t) => [
        row(t.id, "baseline", 0.5),
        row(t.id, "full", 0.9, { costUsd: 0.2 }),
    ]);
    it("pairs the runs task by task and leaves out tasks only one run scored", () => {
        // The previous run also scored an easy task this run lacks; pooling would hide the change.
        const before = [
            ...many.map((t) => row(t.id, "full", 0.6, { costUsd: 0.1 })),
            row("easy", "full", 1, { costUsd: 0 }),
        ];
        const md = buildReport({
            meta,
            rows: now,
            tasks: many,
            previous: { runId: "r0", rows: before },
        });
        expect(md).toMatch(
            /\| full \| F1 \| 6 \| 0\.60 \| 0\.90 \| \+0\.30 \|/,
        );
        expect(md).toMatch(
            /\| full \| cost \(USD\) \| 6 \| 0\.100 \| 0\.200 \| \+0\.100 \|/,
        );
        expect(md).toMatch(
            /- baseline F1: 0 tasks pair with the previous run, fewer than 5, so it is not compared\./,
        );
    });
    it("skips an arm with fewer paired tasks than the minimum", () => {
        const md = buildReport({
            meta,
            rows: now,
            tasks: many,
            previous: {
                runId: "r0",
                rows: [row("k0", "full", 0.6), row("k1", "full", 0.6)],
            },
        });
        expect(md).toMatch(/- full F1: 2 tasks pair with the previous run/);
        expect(md).not.toMatch(/\| full \| F1 \| 2 \|/);
    });
});

describe("key disagreements and unresolved subjects", () => {
    it("lists unresolved subjects apart, and treats a defect without a kind as a disagreement", () => {
        const md = buildReport({
            meta,
            rows,
            tasks,
            defects: [
                {
                    task: "c1",
                    tool: "list-usages",
                    missing: ["A::x"],
                    extra: [],
                },
                {
                    task: "c2",
                    tool: "impact-analysis",
                    kind: "unresolved",
                    query: "Foo::bar",
                    candidates: ["App\\Foo::bar"],
                },
            ],
        });
        expect(md).toMatch(/- c1 \(list-usages\): missing A::x; extra none/);
        expect(md).toMatch(
            /Knossos did not resolve the subject of these tasks[^\n]*\n\n- c2 \(impact-analysis\): query Foo::bar; candidates App\\Foo::bar/,
        );
        expect(md).not.toMatch(/- c2 \(impact-analysis\): missing/);
    });
    it("says no disagreements when only unresolved subjects were recorded", () => {
        const md = buildReport({
            meta,
            rows,
            tasks,
            defects: [
                {
                    task: "c2",
                    tool: "list-usages",
                    kind: "unresolved",
                    query: "Foo",
                    candidates: [],
                },
            ],
        });
        expect(md).toContain(
            "No disagreements between answer keys and Knossos were recorded.",
        );
        expect(md).toMatch(/- c2 \(list-usages\): query Foo; candidates none/);
    });
});
