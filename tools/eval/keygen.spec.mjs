import { access, mkdtemp, readFile, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { describe, expect, it } from "vitest";
import {
    clearDefect,
    diffAnswers,
    isTestPath,
    isTruncated,
    knossosAnswer,
    recordDefect,
    rgLines,
    unresolved,
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

describe("isTestPath", () => {
    it("recognises test directories and files, and nothing else", () => {
        for (const f of [
            "tests/Foo.php",
            "app/tests/Foo.php",
            "pkg/test/a.go",
            "src/__tests__/a.ts",
            "src/a.test.ts",
            "src/a.test.tsx",
            "src/a.spec.ts",
            "src/testutil.rs",
            "py/conftest.py",
        ])
            expect(isTestPath(f), f).toBe(true);
        for (const f of [
            "src/contest/a.php",
            "src/latest.ts",
            "src/mytestutil.rs",
            "app/Billing.php",
            undefined,
        ])
            expect(isTestPath(f), String(f)).toBe(false);
    });
    it("drops test callers and test dependants from the Knossos answer", () => {
        const usages = {
            data: {
                usages: [
                    {
                        kind: "calls",
                        source: { canonical_name: "A::a" },
                        path: "src/A.php",
                    },
                    {
                        kind: "calls",
                        source: { canonical_name: "T::t" },
                        path: "tests/T.php",
                    },
                ],
            },
        };
        expect(knossosAnswer("callers", "symbol", usages)).toEqual(["A::a"]);
        const impact = {
            data: {
                dependants: [
                    {
                        node: {
                            canonical_name: "A",
                            via: { evidence: { path: "src/A.php" } },
                        },
                    },
                    {
                        node: {
                            canonical_name: "T",
                            via: { evidence: { path: "src/x.spec.ts" } },
                        },
                    },
                ],
            },
        };
        expect(knossosAnswer("impact", "symbol", impact)).toEqual(["A"]);
    });
});

describe("unresolved and isTruncated", () => {
    it("detects a query Knossos could not resolve, with up to five candidates", () => {
        const json = {
            data: {
                query: "X::y",
                ambiguous: false,
                candidates: [
                    "A",
                    { canonical_name: "B" },
                    {},
                    "C",
                    "D",
                    "E",
                    "F",
                ],
            },
        };
        expect(unresolved("callers", json)).toEqual({
            query: "X::y",
            candidates: ["A", "B", "C", "D", "E"],
        });
        expect(unresolved("impact", json)).toEqual({
            query: "X::y",
            candidates: ["A", "B", "C", "D", "E"],
        });
        expect(unresolved("callers", { data: { usages: [] } })).toBeNull();
        expect(unresolved("impact", { data: { dependants: [] } })).toBeNull();
        expect(unresolved("impact", { data: { usages: [] } })).not.toBeNull();
    });
    it("reads the result_limit truncation reason", () => {
        expect(
            isTruncated({ bounds: { truncation_reasons: ["result_limit"] } }),
        ).toBe(true);
        expect(isTruncated({ bounds: { truncation_reasons: [] } })).toBe(false);
        expect(isTruncated({})).toBe(false);
    });
});

describe("clearDefect", () => {
    it("deletes a stale defect and tolerates a missing one", async () => {
        const root = await mkdtemp(path.join(os.tmpdir(), "eval-defects-"));
        const paths = { defects: path.join(root, "defects") };
        await recordDefect(paths, "t2", "list-usages", {
            missing: ["a"],
            extra: [],
        });
        await clearDefect(paths, "t2");
        await expect(
            access(path.join(paths.defects, "t2.json")),
        ).rejects.toThrow();
        await expect(clearDefect(paths, "t2")).resolves.toBeUndefined();
    });
});
