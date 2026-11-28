import { describe, expect, it } from "vitest";

import { scan } from "./support/test-case-facts.mjs";

// A module mock's third argument: `{ virtual: true }` mocks a module that
// exists only as the mock, so its specifier names no file to judge it against.

/** The one double made through `pattern` on `line` of `path`. */
function doubleAt(nodes, path, line, pattern) {
    const found = nodes.filter(
        (node) =>
            node.kind === "test_double" &&
            node.evidence.path === path &&
            node.evidence.start_line === line &&
            node.attributes.pattern === pattern,
    );
    expect(found).toHaveLength(1);
    return found[0];
}

describe("a module mock's options", () => {
    it("keeps no specifier for a virtual module, which no file holds", () => {
        const { nodes, edges } = scan({
            "tests/virtual.test.ts":
                "import { vi } from 'vitest';\n" +
                "import { jest } from '@jest/globals';\n" +
                "vi.mock('./virtual-thing', () => ({ run: vi.fn() }), { virtual: true });\n" +
                "jest.mock('virtual-pkg', () => ({}), { virtual: true } as any);\n" +
                "vi.mock('./real-thing', () => ({}), { virtual: false });\n",
        });
        const virtual = doubleAt(nodes, "tests/virtual.test.ts", 3, "vi.mock");
        const cast = doubleAt(nodes, "tests/virtual.test.ts", 4, "jest.mock");
        const real = doubleAt(nodes, "tests/virtual.test.ts", 5, "vi.mock");

        expect(virtual.attributes.target).toEqual({ kind: "module" });
        expect(virtual.attributes.members.map((member) => member.name)).toEqual(
            ["run"],
        );
        expect(cast.attributes.target).toEqual({ kind: "module" });
        expect(real.attributes.target).toEqual({
            kind: "module",
            specifier: "./real-thing",
        });
        expect(
            edges.filter(
                (edge) =>
                    edge.kind === "doubles" &&
                    [virtual.local_id, cast.local_id].includes(edge.source),
            ),
        ).toEqual([]);
    });
});
