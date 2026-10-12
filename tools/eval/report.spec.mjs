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
        expect(md).toMatch(/contaminated: 1/);
    });
    it("gives each comparison a verdict", () => {
        expect(md).toMatch(/\| full vs baseline \| F1 \|/);
        expect(md).toMatch(/helps|hurts|no detectable effect/);
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
