import { describe, expect, it } from "vitest";
import {
    bootstrapCi,
    comparisonSeed,
    median,
    METRICS,
    MIN_PAIRED_TASKS,
    pairedDeltas,
    selectAblations,
    taskMedians,
    verdict,
} from "./stats.mjs";

const row = (task, arm, f1, status = "scored", knossosCalls = {}) => ({
    task,
    arm,
    repeat: 1,
    f1,
    status,
    knossosCalls,
});

describe("median", () => {
    it("handles odd, even and empty", () => {
        expect(median([3, 1, 2])).toBe(2);
        expect(median([1, 2, 3, 4])).toBe(2.5);
        expect(median([])).toBeNaN();
    });
});

describe("taskMedians and pairedDeltas", () => {
    const rows = [
        row("t1", "full", 1),
        row("t1", "full", 0.5),
        row("t1", "full", 0.8),
        row("t1", "baseline", 0.5),
        row("t2", "full", 1),
        row("t2", "baseline", 0, "contaminated"),
    ];
    it("takes the median per task and arm, leaving unscorable rows out", () => {
        const m = taskMedians(rows, "f1");
        expect(m.get("full").get("t1")).toBe(0.8);
        expect(m.get("baseline").has("t2")).toBe(false);
    });
    it("leaves out rows whose metric is not a finite number", () => {
        const m = taskMedians(
            [
                row("t1", "full", NaN),
                row("t1", "full", undefined),
                row("t1", "full", 0.4),
                row("t2", "full", NaN),
            ],
            "f1",
        );
        expect(m.get("full").get("t1")).toBe(0.4);
        expect(m.get("full").has("t2")).toBe(false);
    });
    it("pairs only tasks both arms have", () => {
        expect(
            pairedDeltas(taskMedians(rows, "f1"), "full", "baseline"),
        ).toEqual([0.30000000000000004]);
    });
});

describe("bootstrapCi and verdict", () => {
    it("is deterministic under a seed", () => {
        const values = [0.1, 0.2, 0.3, 0.25, 0.15];
        expect(bootstrapCi(values, { seed: 3 })).toEqual(
            bootstrapCi(values, { seed: 3 }),
        );
    });
    it("finds a clear positive effect and calls it", () => {
        const ci = bootstrapCi(Array(20).fill(0.3), { seed: 1 });
        expect(ci.low).toBeCloseTo(0.3);
        expect(verdict(ci, { higherIsBetter: true }, 20)).toBe("helps");
        expect(verdict(ci, { higherIsBetter: false }, 20)).toBe("hurts");
    });
    it("calls a negative interval the other way round", () => {
        const ci = bootstrapCi(Array(20).fill(-0.3), { seed: 1 });
        expect(verdict(ci, { higherIsBetter: true }, 20)).toBe("hurts");
        expect(verdict(ci, { higherIsBetter: false }, 20)).toBe("helps");
    });
    it("refuses a verdict on fewer paired tasks than the minimum", () => {
        const ci = bootstrapCi([0.3, 0.3, 0.3, 0.3], { seed: 1 });
        expect(MIN_PAIRED_TASKS).toBe(5);
        expect(verdict(ci, { higherIsBetter: true }, 4)).toBe("too few tasks");
        expect(verdict(ci, { higherIsBetter: true }, 5)).toBe("helps");
    });
    it("calls an interval across zero no detectable effect", () => {
        const ci = bootstrapCi([-0.5, 0.5, -0.4, 0.4, 0.1, -0.1], { seed: 1 });
        expect(verdict(ci, { higherIsBetter: true }, 6)).toBe(
            "no detectable effect",
        );
    });
    it("throws when the number of paired tasks is missing", () => {
        const ci = bootstrapCi(Array(20).fill(0.3), { seed: 1 });
        expect(() => verdict(ci, { higherIsBetter: true })).toThrow(TypeError);
        expect(() =>
            verdict(null, { higherIsBetter: true }, undefined),
        ).toThrow(TypeError);
    });
    it("returns null without data", () => {
        expect(bootstrapCi([], {})).toBeNull();
        expect(verdict(null, { higherIsBetter: true }, 0)).toBe("no data");
    });
});

describe("selectAblations", () => {
    const tasks = [
        { id: "c1", category: "callers" },
        { id: "c2", category: "callers" },
        { id: "c3", category: "callers" },
        { id: "c4", category: "callers" },
        { id: "c5", category: "callers" },
        { id: "i1", category: "impact" },
    ];
    it("picks tools full used on at least the threshold share of a category's tasks", () => {
        const rows = [
            row("c1", "full", 1, "scored", { list_usages: 2 }),
            row("c2", "full", 1, "scored", { find_component: 1 }),
            row("i1", "full", 1, "scored", { impact_analysis: 1 }),
            row("c3", "baseline", 1, "scored", { list_usages: 1 }),
        ];
        expect(selectAblations(rows, tasks, { threshold: 0.2 })).toEqual([
            {
                category: "callers",
                tool: "find_component",
                taskIds: ["c1", "c2", "c3", "c4", "c5"],
            },
            {
                category: "callers",
                tool: "list_usages",
                taskIds: ["c1", "c2", "c3", "c4", "c5"],
            },
            { category: "impact", tool: "impact_analysis", taskIds: ["i1"] },
        ]);
        expect(selectAblations(rows, tasks, { threshold: 0.4 })).toEqual([
            { category: "impact", tool: "impact_analysis", taskIds: ["i1"] },
        ]);
    });
});

describe("selectAblations edge cases", () => {
    const tasks = [{ id: "c1", category: "callers" }];
    it("picks from first passes only: a second pass started with notes", () => {
        const second = {
            ...row("c1", "full", 1, "scored", { list_annotations: 1 }),
            pass: 2,
        };
        expect(selectAblations([second], tasks)).toEqual([]);
    });
    it("ignores a session of a task that is not in the corpus", () => {
        const rows = [row("gone", "full", 1, "scored", { list_usages: 1 })];
        expect(selectAblations(rows, tasks)).toEqual([]);
    });
    it("does not count a tool with zero calls as used", () => {
        const rows = [row("c1", "full", 1, "scored", { list_usages: 0 })];
        expect(selectAblations(rows, tasks)).toEqual([]);
    });
});

describe("METRICS", () => {
    it("has tokens excluding cache writes, lower is better", () => {
        expect(METRICS.find((m) => m.key === "workTokens")).toEqual({
            key: "workTokens",
            label: "tokens excl. cache writes",
            higherIsBetter: false,
        });
    });
    it("skips a session whose cost is unknown rather than counting it free", () => {
        const rows = [
            { task: "a", arm: "full", status: "timeout", costUsd: null },
            { task: "a", arm: "full", status: "scored", costUsd: 0.4 },
        ];
        expect(taskMedians(rows, "costUsd").get("full").get("a")).toBe(0.4);
    });
});

describe("comparisonSeed", () => {
    it("is repeatable and differs per comparison and per run seed", () => {
        const a = comparisonSeed(1, "overall", "full", "baseline", "f1");
        expect(comparisonSeed(1, "overall", "full", "baseline", "f1")).toBe(a);
        expect(
            comparisonSeed(1, "overall", "full", "baseline", "costUsd"),
        ).not.toBe(a);
        expect(
            comparisonSeed(1, "overall", "tools", "baseline", "f1"),
        ).not.toBe(a);
        expect(comparisonSeed(2, "overall", "full", "baseline", "f1")).not.toBe(
            a,
        );
        expect(Number.isInteger(a) && a >= 0 && a < 2 ** 32).toBe(true);
    });
    it("does not confuse part boundaries", () => {
        expect(comparisonSeed(1, "ab", "c")).not.toBe(
            comparisonSeed(1, "a", "bc"),
        );
    });
});
