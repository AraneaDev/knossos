import { appendFile, mkdtemp } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { describe, expect, it } from "vitest";
import {
    appendRow,
    limitState,
    makePlan,
    pending,
    readLedger,
    sessionKey,
} from "./ledger.mjs";
import { rng } from "./rng.mjs";

const tasks = [
    { id: "t1", repo: "a" },
    { id: "t2", repo: "b" },
];

describe("rng", () => {
    it("is deterministic per seed", () => {
        const a = rng(7);
        const b = rng(7);
        expect([a(), a(), a()]).toEqual([b(), b(), b()]);
    });
    it("stays in [0, 1) and spreads over the range", () => {
        const next = rng(42);
        const draws = Array.from({ length: 2000 }, next);
        expect(draws.every((x) => x >= 0 && x < 1)).toBe(true);
        expect(Math.min(...draws)).toBeLessThan(0.05);
        expect(Math.max(...draws)).toBeGreaterThan(0.95);
        const mean = draws.reduce((sum, x) => sum + x, 0) / draws.length;
        expect(mean).toBeGreaterThan(0.45);
        expect(mean).toBeLessThan(0.55);
    });
});

describe("makePlan", () => {
    it("has every task, arm and repeat once, shuffled by the seed", () => {
        const plan = makePlan({
            tasks,
            arms: ["baseline", "full"],
            repeats: 3,
            seed: 1,
        });
        expect(plan).toHaveLength(12);
        expect(new Set(plan.map(sessionKey)).size).toBe(12);
        expect(
            makePlan({
                tasks,
                arms: ["baseline", "full"],
                repeats: 3,
                seed: 1,
            }),
        ).toEqual(plan);
        expect(
            makePlan({
                tasks,
                arms: ["baseline", "full"],
                repeats: 3,
                seed: 2,
            }),
        ).not.toEqual(plan);
    });
});

describe("makePlan order", () => {
    const many = Array.from({ length: 10 }, (_, i) => ({
        id: `t${i}`,
        repo: "r",
    }));
    const unshuffled = many.flatMap((t) =>
        ["baseline", "full"].map((arm) =>
            sessionKey({ task: t.id, arm, repeat: 1 }),
        ),
    );
    it("does not leave the plan in task, arm, repeat order", () => {
        for (const seed of [1, 2, 3]) {
            const plan = makePlan({
                tasks: many,
                arms: ["baseline", "full"],
                repeats: 1,
                seed,
            });
            expect(plan.map(sessionKey)).not.toEqual(unshuffled);
            expect([...plan.map(sessionKey)].sort()).toEqual(
                [...unshuffled].sort(),
            );
        }
    });
    it("moves most sessions away from where they started", () => {
        const plan = makePlan({
            tasks: many,
            arms: ["baseline", "full"],
            repeats: 1,
            seed: 5,
        }).map(sessionKey);
        const stayed = plan.filter((k, i) => unshuffled[i] === k).length;
        expect(stayed).toBeLessThan(unshuffled.length / 2);
    });
});

describe("ledger", () => {
    it("skips lines that are JSON but not a row", async () => {
        const file = path.join(
            await mkdtemp(path.join(os.tmpdir(), "ledger-")),
            "ledger.jsonl",
        );
        await appendFile(
            file,
            ["1", '"x"', "null", "[1]", '{"task":"a"}', "{broken"].join("\n") +
                "\n",
        );
        expect(await readLedger(file)).toEqual([{ task: "a" }]);
    });
    it("appends to a large ledger without reading it back", async () => {
        const file = path.join(
            await mkdtemp(path.join(os.tmpdir(), "ledger-")),
            "ledger.jsonl",
        );
        await appendFile(
            file,
            `${JSON.stringify({ task: "big", pad: "x".repeat(2e6) })}\n{"task":"cut"`,
        );
        await appendRow(file, { task: "next" });
        expect((await readLedger(file)).map((r) => r.task)).toEqual([
            "big",
            "next",
        ]);
    });
    it("appends rows and reads them back", async () => {
        const file = path.join(
            await mkdtemp(path.join(os.tmpdir(), "ledger-")),
            "ledger.jsonl",
        );
        expect(await readLedger(file)).toEqual([]);
        await appendRow(file, {
            task: "t1",
            arm: "full",
            repeat: 1,
            status: "scored",
        });
        expect(await readLedger(file)).toHaveLength(1);
    });
    it("skips a line cut off by a kill mid-write", async () => {
        const file = path.join(
            await mkdtemp(path.join(os.tmpdir(), "ledger-")),
            "ledger.jsonl",
        );
        await appendRow(file, {
            task: "t1",
            arm: "full",
            repeat: 1,
            status: "scored",
        });
        await appendFile(file, '{"task":"t2","arm":"fu');
        expect(await readLedger(file)).toHaveLength(1);
    });
    it("starts a new row on its own line after a cut-off fragment", async () => {
        const file = path.join(
            await mkdtemp(path.join(os.tmpdir(), "ledger-")),
            "ledger.jsonl",
        );
        await appendRow(file, {
            task: "t1",
            arm: "full",
            repeat: 1,
            status: "scored",
        });
        await appendFile(file, '{"task":"t2","arm":"fu');
        await appendRow(file, {
            task: "t3",
            arm: "full",
            repeat: 1,
            status: "scored",
        });
        expect((await readLedger(file)).map((r) => r.task)).toEqual([
            "t1",
            "t3",
        ]);
    });
});

describe("pending", () => {
    const plan = makePlan({ tasks, arms: ["full"], repeats: 1, seed: 1 });
    it("drops terminal sessions and keeps limited and errored ones", () => {
        const rows = [
            { task: "t1", arm: "full", repeat: 1, status: "scored" },
            { task: "t2", arm: "full", repeat: 1, status: "limited" },
        ];
        expect(pending(plan, rows).map((e) => e.task)).toEqual(["t2"]);
        expect(
            pending(plan, [
                ...rows,
                { task: "t2", arm: "full", repeat: 1, status: "error" },
            ]),
        ).toHaveLength(1);
    });
});

describe("limitState", () => {
    const rec = (status, utilization, apiErrorStatus = null) => ({
        rateLimit: { status, fiveHourUtilization: utilization },
        result: { apiErrorStatus },
    });
    it("stops on a rejected request or a 429", () => {
        expect(limitState(rec("rejected", 1), { maxUtilization: 0.85 })).toBe(
            "limited",
        );
        expect(
            limitState(rec("allowed", 0.5, 429), { maxUtilization: 0.85 }),
        ).toBe("limited");
    });
    it("warns near the threshold and is fine below it or without data", () => {
        expect(limitState(rec("allowed", 0.85), { maxUtilization: 0.85 })).toBe(
            "near-limit",
        );
        expect(limitState(rec("allowed", 0.4), { maxUtilization: 0.85 })).toBe(
            "ok",
        );
        expect(
            limitState(
                { rateLimit: null, result: null },
                { maxUtilization: 0.85 },
            ),
        ).toBe("ok");
    });
});
