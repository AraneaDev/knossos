import { describe, expect, it } from "vitest";

import { ReadTable } from "../read-table.js";

const A = "a".repeat(64);
const B = "b".repeat(64);

describe("ReadTable", () => {
    it("sends a path and its hash once and refers to it by row after that", () => {
        const table = new ReadTable();

        expect(
            table.refer({ "package.json": A, "src/missing.ts": null }),
        ).toEqual({
            refs: [0, 1],
            parts: [
                [
                    ["package.json", A],
                    ["src/missing.ts", null],
                ],
            ],
        });
        expect(
            table.refer({ "src/missing.ts": null, "tsconfig.json": B }),
        ).toEqual({
            refs: [1, 2],
            parts: [[["tsconfig.json", B]]],
        });
        expect(table.refer({ "package.json": A })).toEqual({
            refs: [0],
            parts: [],
        });
    });

    it("has nothing to send for a file that read nothing", () => {
        expect(new ReadTable().refer({})).toEqual({ refs: [], parts: [] });
    });

    it("gives a path read again with another value a new row", () => {
        const table = new ReadTable();
        table.refer({ "package.json": A });

        expect(table.refer({ "package.json": null })).toEqual({
            refs: [1],
            parts: [[["package.json", null]]],
        });
        expect(table.refer({ "package.json": null }).refs).toEqual([1]);
        expect(table.refer({ "package.json": A })).toEqual({
            refs: [2],
            parts: [[["package.json", A]]],
        });
    });

    it("splits new rows into parts that stay well under the line cap", () => {
        const reads = {};
        for (let index = 0; index < 6000; index++)
            reads[`src/${"deep/".repeat(30)}file-${index}.ts`] = A;

        const { refs, parts } = new ReadTable().refer(reads);

        expect(refs).toEqual([...Array(6000).keys()]);
        expect(parts.length).toBeGreaterThan(1);
        expect(parts.flat().map(([path]) => path)).toEqual(Object.keys(reads));
        for (const part of parts)
            expect(Buffer.byteLength(JSON.stringify(part))).toBeLessThan(
                600_000,
            );
    });

    it("counts a path by the bytes it takes on the wire", () => {
        const reads = {};
        for (let index = 0; index < 3000; index++)
            reads[`src/${"é ".repeat(40)}-${index}.ts`] = null;

        const { parts } = new ReadTable().refer(reads);

        for (const part of parts)
            expect(Buffer.byteLength(JSON.stringify(part))).toBeLessThan(
                600_000,
            );
    });
});
