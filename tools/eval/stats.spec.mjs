import { describe, expect, it } from "vitest";
import {
    bootstrapCi,
    median,
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
        expect(verdict(ci, { higherIsBetter: true })).toBe("helps");
        expect(verdict(ci, { higherIsBetter: false })).toBe("hurts");
    });
    it("calls an interval across zero no detectable effect", () => {
        const ci = bootstrapCi([-0.5, 0.5, -0.4, 0.4, 0.1, -0.1], { seed: 1 });
        expect(verdict(ci, { higherIsBetter: true })).toBe(
            "no detectable effect",
        );
    });
    it("returns null without data", () => {
        expect(bootstrapCi([], {})).toBeNull();
        expect(verdict(null, { higherIsBetter: true })).toBe(
            "no detectable effect",
        );
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
