import { describe, expect, it } from "vitest";

import { assertionAt, scan, testCases } from "./support/test-case-facts.mjs";

// Healthy tests that read like tautologies when a fact says more than the
// source does: a call hidden inside an operand, a variable the test keeps
// changing, an assertion that expects the opposite. Each must give the core
// nothing to report.

const PRODUCT = {
    "src/ids.ts": [
        "export function make() { return { id: Math.random() }; }",
        "export function next(): number { return 0; }",
        "export function gen(): string { return 'x'; }",
        "",
    ].join("\n"),
};

describe("a call inside an operand", () => {
    it("gives no text to an operand that calls anything", () => {
        const { nodes } = scan({
            ...PRODUCT,
            "tests/ids.test.ts": [
                "import { expect, it } from 'vitest';",
                "import { gen, make, next } from '../src/ids';",
                "it('makes unique ids', () => {",
                "  expect(make().id).not.toBe(make().id);",
                "  expect(`${gen()}`).not.toBe(`${gen()}`);",
                "  const arr = [1, 2];",
                "  expect(arr[next()]).toBe(arr[next()]);",
                "  expect(arr[0]).toBe(arr[0]);",
                "});",
                "",
            ].join("\n"),
        });
        const path = "tests/ids.test.ts";
        const texts = (line) =>
            assertionAt(nodes, path, line).operands.map(
                (operand) => operand.text,
            );

        expect(texts(4)).toEqual([undefined, undefined]);
        expect(texts(5)).toEqual([undefined, undefined]);
        expect(texts(7)).toEqual([undefined, undefined]);
        // Nothing in it is evaluated afresh: the same expression twice.
        expect(texts(8)).toEqual(["arr[0]", "arr[0]"]);
    });
});

describe("a variable the test reassigns", () => {
    it("proves nothing through a member of it", () => {
        const { nodes } = scan({
            ...PRODUCT,
            "tests/join.test.ts": [
                "import { expect, it } from 'vitest';",
                "import { gen } from '../src/ids';",
                "it('joins', () => {",
                "  let out = '';",
                "  for (const c of gen()) out += c;",
                "  expect(out.length).toBe(3);",
                "  const fixed = 'abc';",
                "  expect(fixed.length).toBe(3);",
                "});",
                "",
            ].join("\n"),
        });
        const path = "tests/join.test.ts";

        expect(assertionAt(nodes, path, 6).operands).toEqual([
            { source: "unknown", text: "out.length" },
            { source: "literal", text: "3" },
        ]);
        // A constant's member is as constant as the constant.
        expect(assertionAt(nodes, path, 8).operands[0]).toEqual({
            source: "literal",
            text: "fixed.length",
        });
    });

    it("answers unknown for bindings that name each other", () => {
        const { nodes, diagnostics } = scan({
            "tests/cycle.test.ts": [
                "import { expect, it } from 'vitest';",
                "it('loops', () => {",
                "  const a = b;",
                "  const b = a;",
                "  expect(a).toBe(1);",
                "  expect(b.x).toBe(1);",
                "});",
                "",
            ].join("\n"),
        });
        const path = "tests/cycle.test.ts";

        expect(
            diagnostics.filter(
                (diagnostic) => diagnostic.code === "TS_INTERNAL_ERROR",
            ),
        ).toEqual([]);
        expect(assertionAt(nodes, path, 5).operands[0]).toEqual({
            source: "unknown",
            text: "a",
        });
        expect(assertionAt(nodes, path, 6).operands[0]).toEqual({
            source: "unknown",
            text: "b.x",
        });
    });
});

describe("an assertion that expects the opposite", () => {
    it("says it is negated", () => {
        const { nodes } = scan({
            "tests/negated.test.ts": [
                "import { expect, it, vi } from 'vitest';",
                "it('differs from the stub', async () => {",
                "  const stub = { get: vi.fn() };",
                "  stub.get.mockReturnValue(42);",
                "  expect(stub.get()).not.toBe(42);",
                "  expect(stub.get()).toBe(42);",
                "  await expect(stub.get()).resolves.not.toBe(42);",
                "  expect(stub.get()).not.resolves;",
                "});",
                "",
            ].join("\n"),
        });
        const path = "tests/negated.test.ts";

        expect(assertionAt(nodes, path, 5)).toMatchObject({
            api: "toBe",
            negated: true,
            operands: [
                { source: "mock-config", value: "42" },
                { source: "literal", text: "42" },
            ],
        });
        expect(assertionAt(nodes, path, 6).negated).toBeUndefined();
        expect(assertionAt(nodes, path, 7).negated).toBe(true);
        // A property read with no matcher called is no assertion.
        expect(
            testCases(nodes, path)[0].attributes.assertions.map(
                (assertion) => assertion.line,
            ),
        ).toEqual([5, 6, 7]);
    });
});
