import { execFile } from "node:child_process";
import { fileURLToPath } from "node:url";
import { promisify } from "node:util";
import { describe, expect, it } from "vitest";
import { calibrate, KEEP_AT_MOST } from "./calibrate.mjs";

const row = (task, arm, f1) => ({
    task,
    arm,
    repeat: 1,
    status: "scored",
    f1,
});
const tasks = [
    { id: "h1", tier: "hard" },
    { id: "h2", tier: "hard" },
    { id: "h3", tier: "hard" },
    { id: "e1" },
];

describe("calibrate", () => {
    it("reads first passes only: a second pass started with the first's notes", () => {
        const rows = [
            row("h1", "baseline", 0.5),
            row("h1", "full", 0.5),
            { ...row("h1", "baseline", 1), pass: 2 },
            { ...row("h1", "full", 1), pass: 2 },
            { ...row("h1", "full", 1), pass: 2 },
        ];
        expect(calibrate(rows, [tasks[0]])[0]).toMatchObject({
            full: 0.5,
            verdict: "keep",
        });
    });
    it("keeps a hard task whose arms average at most the threshold and drops the rest", () => {
        const rows = [
            row("h1", "baseline", 0.5),
            row("h1", "full", 1),
            row("h2", "baseline", 1),
            row("h2", "full", 0.9),
            row("e1", "baseline", 0.1),
            row("e1", "full", 0.1),
        ];
        expect(KEEP_AT_MOST).toBe(0.85);
        expect(calibrate(rows, tasks)).toEqual([
            {
                task: "h1",
                baseline: 0.5,
                full: 1,
                average: 0.75,
                verdict: "keep",
            },
            {
                task: "h2",
                baseline: 1,
                full: 0.9,
                average: 0.95,
                verdict: "drop",
            },
            {
                task: "h3",
                baseline: null,
                full: null,
                average: null,
                verdict: "missing both",
            },
        ]);
    });

    it("keeps a task exactly at the threshold", () => {
        const rows = [row("h1", "baseline", 0.8), row("h1", "full", 0.9)];
        expect(calibrate(rows, [tasks[0]])[0].verdict).toBe("keep");
    });

    it("says which arm is missing", () => {
        expect(
            calibrate([row("h1", "baseline", 0.2)], [tasks[0]])[0].verdict,
        ).toBe("missing full");
        expect(calibrate([], [tasks[0]])[0].verdict).toBe("missing both");
    });

    it("judges the exact average, not the rounded one", () => {
        // 0.8504 rounds to 0.850 for display but is above the threshold.
        const rows = [row("h1", "baseline", 0.8008), row("h1", "full", 0.9)];
        const [line] = calibrate(rows, [tasks[0]]);
        expect(line.average).toBe(0.85);
        expect(line.verdict).toBe("drop");
    });
});

describe("calibrate CLI", () => {
    it("prints a usage line and exits 2 without a run id", async () => {
        const file = fileURLToPath(new URL("./calibrate.mjs", import.meta.url));
        const result = await promisify(execFile)("node", [file]).catch(
            (e) => e,
        );
        expect(result.code).toBe(2);
        expect(result.stderr).toContain(
            "usage: node tools/eval/calibrate.mjs <run-id>",
        );
    });
});
