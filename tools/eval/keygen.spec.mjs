import { access, mkdtemp, readFile, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { describe, expect, it } from "vitest";
import {
    diffAnswers,
    knossosAnswer,
    recordDefect,
    rgLines,
} from "./keygen.mjs";

const usages = {
    data: {
        usages: [
            {
                kind: "calls",
                source: { canonical_name: "App\\Billing::summarise" },
                path: "app/Billing.php",
            },
            {
                kind: "imports",
                source: { canonical_name: "App\\Other" },
                path: "app/Other.php",
            },
            {
                kind: "dispatches",
                source: { canonical_name: "App\\Jobs\\Send::handle" },
                path: "app/Jobs/Send.php",
            },
        ],
    },
};
const impact = {
    data: {
        dependants: [
            {
                node: {
                    canonical_name: "App\\Http\\Show::__invoke",
                    via: { evidence: { path: "app/Http/Show.php" } },
                },
            },
        ],
    },
};

describe("knossosAnswer", () => {
    it("reads callers from call and dispatch usages only", () => {
        expect(knossosAnswer("callers", "symbol", usages)).toEqual([
            "App\\Billing::summarise",
            "App\\Jobs\\Send::handle",
        ]);
    });
    it("reads impact as symbols and dependents as files", () => {
        expect(knossosAnswer("impact", "symbol", impact)).toEqual([
            "App\\Http\\Show::__invoke",
        ]);
        expect(knossosAnswer("dependents", "file", impact)).toEqual([
            "app/Http/Show.php",
        ]);
    });
    it("has no CLI answer for location and flow", () => {
        expect(knossosAnswer("location", "file", {})).toBeNull();
        expect(knossosAnswer("flow", "symbol", {})).toBeNull();
    });
});

describe("diffAnswers", () => {
    it("lists what Knossos missed and what it added, normalised", () => {
        const opts = { unit: "symbol", language: "php" };
        expect(
            diffAnswers(
                ["App\\Billing::summarise", "App\\X::y"],
                ["\\App\\Billing::summarise", "App\\Z::w"],
                opts,
            ),
        ).toEqual({
            missing: ["x::y"],
            extra: ["z::w"],
        });
    });
});

describe("knossosAnswer with partial data", () => {
    it("skips usages without a source or a canonical name", () => {
        const json = {
            data: {
                usages: [
                    { kind: "calls", path: "a.php" },
                    { kind: "calls", source: {}, path: "b.php" },
                    { kind: "calls", source: { canonical_name: "A::b" } },
                ],
            },
        };
        expect(knossosAnswer("callers", "symbol", json)).toEqual(["A::b"]);
        expect(
            knossosAnswer("impact", "symbol", {
                data: { dependants: [{ node: {} }, {}] },
            }),
        ).toEqual([]);
    });
});

describe("rgLines", () => {
    it("returns matches, an empty list for no match, and throws on any other failure", async () => {
        const dir = await mkdtemp(path.join(os.tmpdir(), "eval-rg-"));
        await writeFile(
            path.join(dir, "a.php"),
            "<?php\nfunction total() {}\n",
        );
        expect(await rgLines("total", dir)).toEqual([
            "./a.php:2:function total() {}",
        ]);
        expect(await rgLines("absent", dir)).toEqual([]);
        await expect(
            rgLines("total", path.join(dir, "missing")),
        ).rejects.toThrow(/rg failed in/);
    });
});

describe("recordDefect", () => {
    it("writes a disagreement and deletes it once Knossos agrees", async () => {
        const root = await mkdtemp(path.join(os.tmpdir(), "eval-defects-"));
        const paths = { defects: path.join(root, "defects") };
        const file = path.join(paths.defects, "t1.json");
        expect(
            await recordDefect(paths, "t1", "list-usages", {
                missing: ["a"],
                extra: [],
            }),
        ).toBe(true);
        expect(JSON.parse(await readFile(file, "utf8"))).toMatchObject({
            task: "t1",
            missing: ["a"],
        });
        expect(
            await recordDefect(paths, "t1", "list-usages", {
                missing: [],
                extra: [],
            }),
        ).toBe(false);
        await expect(access(file)).rejects.toThrow();
        expect(
            await recordDefect(paths, "never", "list-usages", {
                missing: [],
                extra: [],
            }),
        ).toBe(false);
    });
});
