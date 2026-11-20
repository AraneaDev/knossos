import { describe, expect, it } from "vitest";

import { scan } from "./support/test-case-facts.mjs";

// A relative mock specifier resolves as an import of it does: a JavaScript
// extension names the TypeScript source it compiles from (NodeNext writes
// `./x.js` for `x.ts`), then the file itself.

/** The target of the one `doubles` edge the project's first mock makes. */
function mockTarget(files) {
    const { nodes, edges } = scan(files);
    const mock = nodes.find((node) => node.kind === "test_double");
    return edges
        .filter(
            (edge) => edge.kind === "doubles" && edge.source === mock.local_id,
        )
        .map((edge) => edge.target);
}

describe("a module mock's JavaScript specifier", () => {
    it.each([
        ["vi.mock('../src/X.js', () => ({}))", "src/X.ts"],
        ["vi.mock('../src/X.js', () => ({}))", "src/X.tsx"],
        ["vi.mock('../src/X.jsx', () => ({}))", "src/X.tsx"],
        ["vi.mock('../src/X.mjs', () => ({}))", "src/X.mts"],
        ["vi.mock('../src/X.cjs', () => ({}))", "src/X.cts"],
        ["jest.mock('../src/X.js')", "src/X.ts"],
    ])("%s resolves to the TypeScript source %s", (call, source) => {
        expect(
            mockTarget({
                [source]: "export const x = 1;\n",
                "tests/x.test.ts": `${call};\n`,
            }),
        ).toEqual([`ts:module:${source}`]);
    });

    it("prefers the TypeScript source to a .js file beside it", () => {
        expect(
            mockTarget({
                "src/X.ts": "export const x = 1;\n",
                "src/X.js": "exports.x = 1;\n",
                "tests/x.test.ts": "vi.mock('../src/X.js');\n",
            }),
        ).toEqual(["ts:module:src/X.ts"]);
    });

    it("resolves to the .js file when no TypeScript source stands behind it", () => {
        expect(
            mockTarget({
                "src/X.js": "exports.x = 1;\n",
                "tests/x.test.ts": "vi.mock('../src/X.js');\n",
            }),
        ).toEqual(["ts:module:src/X.js"]);
    });

    it("leaves a specifier that names no source unresolved", () => {
        expect(
            mockTarget({
                "src/Other.ts": "export const x = 1;\n",
                "tests/x.test.ts": "vi.mock('../src/Gone.js', () => ({}));\n",
            }),
        ).toEqual([]);
    });
});
