import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";
import { SCAN_LIMITS } from "./support/scan-limits.mjs";

// `export const select = (function () { ...; return select; })();` builds a
// function once, in a closure, and binds the module name to it. Callers use
// it like any function, so the graph must hold it as one: without that, every
// call to it resolved to nothing and the calls inside it belonged to no one.

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function scan(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-iife-")),
    );
    created.push(root);
    for (const [path, contents] of Object.entries(files)) {
        fs.mkdirSync(dirname(join(root, path)), { recursive: true });
        fs.writeFileSync(join(root, path), contents);
    }
    const contributions = [];
    new TypeScriptScanner().scan(
        { limits: SCAN_LIMITS, root, files: Object.keys(files) },
        (c) => contributions.push(c),
    );
    return {
        nodes: contributions.flatMap((c) => c.nodes),
        edges: contributions.flatMap((c) => c.edges),
    };
}

const project = (selection) => ({
    "package.json": '{"name":"fixture"}',
    "tsconfig.json": '{"compilerOptions":{"strict":false},"include":["src"]}',
    "src/selection.ts": selection,
    "src/use.ts": [
        "import { select } from './selection';",
        "export function caller(): number {",
        "  return select(1);",
        "}",
        "",
    ].join("\n"),
});

/** Whether `from` reaches `to` over calls edges. */
function reaches(edges, from, to) {
    const seen = new Set([from]);
    const queue = [from];
    while (queue.length > 0) {
        const at = queue.shift();
        for (const e of edges) {
            if (e.kind !== "calls" || e.source !== at || seen.has(e.target))
                continue;
            if (e.target === to) return true;
            seen.add(e.target);
            queue.push(e.target);
        }
    }
    return false;
}

describe("a function built by an immediately invoked function", () => {
    it.each([
        [
            "function expression returning an arrow it declares",
            [
                "export const select = (function () {",
                "  const memo = new Map<number, number>();",
                "  const select = (id: number): number => helper(id) + memo.size;",
                "  return select;",
                "})();",
                "export function helper(id: number): number { return id; }",
                "",
            ].join("\n"),
        ],
        [
            "arrow returning a function it declares",
            [
                "export const select = (() => {",
                "  function inner(id: number): number { return helper(id); }",
                "  return inner;",
                "})();",
                "export function helper(id: number): number { return id; }",
                "",
            ].join("\n"),
        ],
    ])("is a function its callers reach (%s)", (_label, selection) => {
        const { nodes, edges } = scan(project(selection));
        const named = (name) => nodes.find((n) => n.display_name === name);

        const select = named("select");
        expect(select?.kind).toBe("function");
        const caller = named("caller");
        const helper = named("helper");
        // The call to it resolves, and the calls inside it are reached from it:
        // its own for an arrow, through the nested function a declaration is.
        expect(
            edges.some(
                (e) =>
                    e.kind === "calls" &&
                    e.source === caller.local_id &&
                    e.target === select.local_id,
            ),
        ).toBe(true);
        expect(reaches(edges, select.local_id, helper.local_id)).toBe(true);
    });

    it("leaves an immediately invoked function that returns no function a plain value", () => {
        const { nodes } = scan(
            project(
                [
                    "export const select = (() => {",
                    "  const table = [1, 2, 3];",
                    "  return table;",
                    "})();",
                    "",
                ].join("\n"),
            ),
        );
        expect(nodes.find((n) => n.display_name === "select")?.kind).not.toBe(
            "function",
        );
    });
});
