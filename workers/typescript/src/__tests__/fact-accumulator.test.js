import { describe, it, expect } from "vitest";
import { FactAccumulator } from "../fact-accumulator.js";

// The accumulator calls `evidence(sourceFile, relative, node)` for every node
// and edge; a stub is enough since we only assert what is kept.
const stubEvidence = () => ({ path: "f.ts", start_line: 1, end_line: 1 });
const make = () => new FactAccumulator({}, "f.ts", stubEvidence);

describe("FactAccumulator nodes", () => {
    it("keeps the first node per id and ignores later duplicates", () => {
        const acc = make();
        acc.addNode("id1", "class", "Foo", "Foo", {});
        acc.addNode("id1", "class", "FooAgain", "FooAgain", {});
        expect(acc.nodes).toHaveLength(1);
        expect(acc.nodes[0]).toMatchObject({
            local_id: "id1",
            kind: "class",
            canonical_name: "Foo",
            origin: "ast",
            confidence: "certain",
        });
    });

    // A later pass of the file (the tests' facts naming a double's test)
    // writes onto a kept node through these, never into the node map.
    it("reads and sets one attribute of a kept node, and says when there is none", () => {
        const acc = make();
        acc.addNode("id1", "test_double", "D", "D", {}, { pattern: "vi.fn" });

        expect(acc.attribute("id1", "pattern")).toBe("vi.fn");
        expect(acc.attribute("id1", "test_case")).toBeUndefined();
        expect(acc.attribute("gone", "pattern")).toBeUndefined();
        expect(acc.setAttribute("id1", "test_case", "f.ts#test:3")).toBe(true);
        expect(acc.setAttribute("gone", "test_case", "f.ts#test:3")).toBe(
            false,
        );
        expect(acc.nodes).toHaveLength(1);
        expect(acc.nodes[0].attributes).toEqual({
            pattern: "vi.fn",
            test_case: "f.ts#test:3",
        });
    });

    it("preserves a passed origin (e.g. framework_convention)", () => {
        const acc = make();
        acc.addNode("id2", "route", "R", "R", {}, {}, "framework_convention");
        expect(acc.nodes[0].origin).toBe("framework_convention");
    });
});

describe("FactAccumulator edges", () => {
    // The core collapses repeated edges (one rule for every worker), so the
    // accumulator reports each site as it was seen.
    it("reports every occurrence of an edge, in order", () => {
        const acc = make();
        acc.addEdge("imports", "m", "t", {}, { type_only: true });
        acc.addEdge(
            "imports",
            "m",
            "t",
            {},
            { dynamic: true },
            "ast",
            "probable",
        );
        expect(acc.edges).toHaveLength(2);
        expect(acc.edges[0].attributes).toEqual({ type_only: true });
        expect(acc.edges[1]).toMatchObject({
            confidence: "probable",
            attributes: { dynamic: true },
        });
    });

    it("keeps edges that differ only by kind or endpoint", () => {
        const acc = make();
        acc.addEdge("calls", "a", "b", {});
        acc.addEdge("references", "a", "b", {});
        acc.addEdge("calls", "a", "c", {});
        expect(acc.edges).toHaveLength(3);
    });
});

describe("FactAccumulator declarations past a file's own text", () => {
    const sourceFile = {
        getLineAndCharacterOfPosition: () => ({ line: 1, character: 0 }),
    };
    const at = (start) => ({ getStart: () => start });

    it("says whether a node was kept, so its enrichment can be skipped", () => {
        const acc = new FactAccumulator(sourceFile, "f.svelte", stubEvidence, {
            end: 10,
            moduleId: "m",
        });

        expect(acc.addNode("own", "function", "own", "own", at(2))).toBe(true);
        expect(acc.addNode("own", "function", "own", "own", at(2))).toBe(true);
        expect(acc.addNode("tail", "type_alias", "T", "T", at(12))).toBe(false);
        expect(acc.nodes.map((node) => node.local_id)).toEqual(["own"]);
    });
});
