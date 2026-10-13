import { access, readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { afterAll, describe, expect, it } from "vitest";
import { removeTemp, tmp } from "./fixtures/tmp.mjs";
import {
    clearDefect,
    diffAnswers,
    isTestPath,
    isTruncated,
    knossosAnswer,
    recordDefect,
    recordUnresolved,
    rgLines,
    unresolved,
} from "./keygen.mjs";

afterAll(removeTemp);

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
                node: { canonical_name: "App\\Http\\Show::__invoke" },
                distance: 1,
                via: { evidence: { path: "app/Http/Show.php" } },
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
    it("lists what Knossos missed and what it added, matched as answers are", () => {
        const opts = { unit: "symbol", language: "php" };
        expect(
            diffAnswers(
                ["App\\Billing::summarise", "App\\X::y"],
                ["\\App\\Billing::summarise", "App\\Z::w"],
                opts,
            ),
        ).toEqual({
            missing: ["App\\X::y"],
            extra: ["App\\Z::w"],
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

describe("knossosAnswer without the subject's own file", () => {
    const dependant = (file) => ({
        distance: 1,
        node: { canonical_name: `${file}#x` },
        via: { evidence: { path: file } },
    });
    const dependants = [dependant("src/collision.ts"), dependant("src/b.ts")];
    it("drops the file named before # in the target's canonical name", () => {
        const json = {
            data: {
                target: {
                    canonical_name: "src/collision.ts#hit",
                    kind: "function",
                },
                dependants,
            },
        };
        expect(knossosAnswer("dependents", "file", json)).toEqual(["src/b.ts"]);
        expect(knossosAnswer("impact", "file", json)).toEqual(["src/b.ts"]);
    });
    it("prefers target.path and treats a module target as its own file", () => {
        expect(
            knossosAnswer("impact", "file", {
                data: { target: { path: "src/b.ts" }, dependants },
            }),
        ).toEqual(["src/collision.ts"]);
        expect(
            knossosAnswer("impact", "file", {
                data: {
                    target: { canonical_name: "src/b.ts", kind: "module" },
                    dependants,
                },
            }),
        ).toEqual(["src/collision.ts"]);
    });
    it("keeps everything when the target has no file, and for symbol answers", () => {
        const noFile = {
            data: {
                target: { canonical_name: "App\\X::y", kind: "method" },
                dependants,
            },
        };
        expect(knossosAnswer("impact", "file", noFile)).toHaveLength(2);
        expect(
            knossosAnswer("impact", "symbol", {
                data: {
                    target: { canonical_name: "src/collision.ts#hit" },
                    dependants,
                },
            }),
        ).toHaveLength(2);
    });
});

describe("rgLines", () => {
    it("returns matches, an empty list for no match, and throws on any other failure", async () => {
        const dir = await tmp("eval-rg-");
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
        const root = await tmp("eval-defects-");
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
            kind: "disagreement",
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
                        node: { canonical_name: "A" },
                        distance: 1,
                        via: { evidence: { path: "src/A.php" } },
                    },
                    {
                        node: { canonical_name: "T" },
                        distance: 1,
                        via: { evidence: { path: "src/x.spec.ts" } },
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
            summary: 'No component matched "X::y".',
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
        expect(unresolved(json)).toEqual({
            query: "X::y",
            candidates: ["A", "B", "C", "D", "E"],
            summary: 'No component matched "X::y".',
        });
    });
    it("treats impact-analysis's empty no-match shape and an error shape as unresolved", () => {
        const noMatch = {
            summary: "No component matched.",
            data: {
                query: "Z",
                candidates: [],
                dependants: [],
                counts: {},
                entry_points: [],
            },
        };
        expect(unresolved(noMatch)).toMatchObject({
            query: "Z",
            candidates: [],
        });
        expect(unresolved({ summary: "boom" })).toEqual({
            query: null,
            candidates: [],
            summary: "boom",
        });
    });
    it("passes a resolved answer from either command", () => {
        expect(
            unresolved({ data: { target: { id: 1 }, usages: [] } }),
        ).toBeNull();
        expect(
            unresolved({ data: { target: { id: 1 }, dependants: [] } }),
        ).toBeNull();
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
        const root = await tmp("eval-defects-");
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

describe("dependants distance", () => {
    const deps = {
        data: {
            dependants: [
                {
                    node: { canonical_name: "Near" },
                    distance: 1,
                    via: { evidence: { path: "src/near.php" } },
                },
                {
                    node: { canonical_name: "Far" },
                    distance: 2,
                    via: { evidence: { path: "src/far.php" } },
                },
            ],
        },
    };
    it("keeps only direct dependants for dependents, every distance for impact", () => {
        expect(knossosAnswer("dependents", "symbol", deps)).toEqual(["Near"]);
        expect(knossosAnswer("dependents", "file", deps)).toEqual([
            "src/near.php",
        ]);
        expect(knossosAnswer("impact", "symbol", deps)).toEqual([
            "Near",
            "Far",
        ]);
        expect(knossosAnswer("impact", "file", deps)).toEqual([
            "src/near.php",
            "src/far.php",
        ]);
    });
});

describe("recordUnresolved", () => {
    it("writes an unresolved defect in place of any earlier one", async () => {
        const root = await tmp("eval-defects-");
        const paths = { defects: path.join(root, "defects") };
        await recordDefect(paths, "t3", "list-usages", {
            missing: ["a"],
            extra: [],
        });
        await recordUnresolved(paths, "t3", "list-usages", {
            query: "Foo::bar",
            candidates: ["App\\Foo::bar", "Lib\\Foo::bar"],
        });
        expect(
            JSON.parse(
                await readFile(path.join(paths.defects, "t3.json"), "utf8"),
            ),
        ).toEqual({
            task: "t3",
            tool: "list-usages",
            kind: "unresolved",
            query: "Foo::bar",
            candidates: ["App\\Foo::bar", "Lib\\Foo::bar"],
        });
    });
});
