import { appendFile } from "node:fs/promises";
import path from "node:path";
import * as fsp from "node:fs/promises";
import { afterAll, describe, expect, it, vi } from "vitest";
import { removeTemp, tmp } from "./fixtures/tmp.mjs";
import {
    appendRow,
    limitState,
    makePlan,
    pending,
    runnable,
    readLedger,
    sessionKey,
} from "./ledger.mjs";
import { rng } from "./rng.mjs";

vi.mock("node:fs/promises", async (importOriginal) => {
    const actual = await importOriginal();
    return {
        ...actual,
        readFile: vi.fn(actual.readFile),
        open: vi.fn(actual.open),
    };
});

afterAll(removeTemp);

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
        const file = path.join(await tmp("ledger-"), "ledger.jsonl");
        await appendFile(
            file,
            ["1", '"x"', "null", "[1]", '{"task":"a"}', "{broken"].join("\n") +
                "\n",
        );
        expect(await readLedger(file)).toEqual([{ task: "a" }]);
    });
    it("appends to a large ledger without reading it back", async () => {
        const file = path.join(await tmp("ledger-"), "ledger.jsonl");
        await appendFile(
            file,
            `${JSON.stringify({ task: "big", pad: "x".repeat(2e6) })}\n{"task":"cut"`,
        );
        const reads = [];
        const realOpen = fsp.open.getMockImplementation();
        fsp.open.mockImplementationOnce(async (...args) => {
            const handle = await realOpen(...args);
            const read = handle.read.bind(handle);
            handle.read = (buffer, ...rest) => {
                reads.push(buffer.length);
                return read(buffer, ...rest);
            };
            return handle;
        });
        fsp.readFile.mockClear();
        await appendRow(file, { task: "next" });
        // Only the last byte is read, and the file is never read whole.
        expect(reads).toEqual([1]);
        expect(fsp.readFile).not.toHaveBeenCalled();
        expect((await readLedger(file)).map((r) => r.task)).toEqual([
            "big",
            "next",
        ]);
    });
    it("appends rows and reads them back", async () => {
        const file = path.join(await tmp("ledger-"), "ledger.jsonl");
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
        const file = path.join(await tmp("ledger-"), "ledger.jsonl");
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
        const file = path.join(await tmp("ledger-"), "ledger.jsonl");
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

describe("two passes", () => {
    const tasks = [
        { id: "t1", repo: "r" },
        { id: "t2", repo: "r" },
    ];

    it("plans a second pass of every session, keyed apart, with the first pass keyed as before", () => {
        const plan = makePlan({
            tasks,
            arms: ["full"],
            repeats: 1,
            seed: 1,
            passes: 2,
        });
        expect(plan).toHaveLength(4);
        expect(plan.filter((e) => e.pass === 2)).toHaveLength(2);
        const first = plan.find((e) => e.task === "t1" && e.pass !== 2);
        expect(sessionKey(first)).toBe("t1|full|1");
        expect(sessionKey({ ...first, pass: 2 })).toBe("t1|full|1|p2");
        expect(
            makePlan({ tasks, arms: ["full"], repeats: 1, seed: 1 }).every(
                (e) => e.pass === undefined,
            ),
        ).toBe(true);
    });

    it("holds a second pass back until its first is done", () => {
        const plan = makePlan({
            tasks,
            arms: ["full"],
            repeats: 1,
            seed: 1,
            passes: 2,
        });
        const ready = (rows) => runnable(plan, rows).map(sessionKey).sort();
        expect(ready([])).toEqual(["t1|full|1", "t2|full|1"]);
        expect(
            ready([{ task: "t1", arm: "full", repeat: 1, status: "scored" }]),
        ).toEqual(["t1|full|1|p2", "t2|full|1"]);
    });
});
