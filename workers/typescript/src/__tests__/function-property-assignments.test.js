import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";
import { SCAN_LIMITS } from "./support/scan-limits.mjs";

// `register({ name: "ungroup", perform: (elements) => { ... } })` writes an
// object's methods as properties holding arrows. They are called like methods,
// `action.perform(...)`, so the graph must hold them as methods of the literal:
// without that the calls inside them belonged to the module, and a question
// about who calls `ungroup` was answered with a file instead of the action.

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function scan(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-assigned-")),
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

const calls = (edges, source, target) =>
    edges.some(
        (e) => e.kind === "calls" && e.source === source && e.target === target,
    );

describe("an object literal's property that holds a function", () => {
    const { nodes, edges } = scan({
        "package.json": '{"name":"fixture"}',
        "tsconfig.json":
            '{"compilerOptions":{"strict":false},"include":["src"]}',
        "src/groups.ts": [
            "export function ungroup(x: number): number { return x; }",
            "export function regroup(x: number): number { return x; }",
            "",
        ].join("\n"),
        "src/actions.ts": [
            "import { ungroup, regroup } from './groups';",
            "const register = <T>(action: T): T => action;",
            "export const actionUngroup = register({",
            '  name: "ungroup",',
            "  perform: (x: number) => ungroup(x),",
            "});",
            "export const tools = {",
            "  label: 'tools',",
            "  redo: function (x: number) { return regroup(x); },",
            "};",
            "",
        ].join("\n"),
        "src/manager.ts": [
            "import { actionUngroup, tools } from './actions';",
            "export function run(): number {",
            "  return actionUngroup.perform(1) + tools.redo(2);",
            "}",
            "",
        ].join("\n"),
    });
    const method = (name) =>
        nodes.find((n) => n.kind === "method" && n.display_name === name);
    const named = (name) => nodes.find((n) => n.display_name === name);

    it.each([
        ["perform", "ungroup", "passed to a call"],
        ["redo", "regroup", "bound to a name"],
    ])(
        "holds %s as a method that owns its calls and that its callers reach (%s)",
        (name, callee) => {
            const held = method(name);
            expect(held).toBeDefined();
            expect(calls(edges, held.local_id, named(callee).local_id)).toBe(
                true,
            );
            expect(calls(edges, named("run").local_id, held.local_id)).toBe(
                true,
            );
        },
    );

    it("leaves a literal a function builds to that function, whose calls stay its own", () => {
        const { nodes: inner, edges: innerEdges } = scan({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false},"include":["src"]}',
            "src/blocks.ts": [
                "export function helper(): number { return 1; }",
                "export function block() {",
                "  return { make: () => helper() };",
                "}",
                "",
            ].join("\n"),
        });
        const block = inner.find((n) => n.display_name === "block");
        const helper = inner.find((n) => n.display_name === "helper");
        expect(
            inner.find((n) => n.kind === "method" && n.display_name === "make"),
        ).toBeUndefined();
        expect(calls(innerEdges, block.local_id, helper.local_id)).toBe(true);
    });

    it("marks a table's method that fulfils a declared type's member as overriding it", () => {
        const { nodes: typed } = scan({
            "package.json": '{"name":"fixture"}',
            "tsconfig.json":
                '{"compilerOptions":{"strict":false},"include":["src"]}',
            "src/action.ts": [
                "export interface Action { name: string; perform: (x: number) => number }",
                "const register = (action: Action): Action => action;",
                "export const actionDouble = register({",
                '  name: "double",',
                "  perform: (x: number) => x * 2,",
                "});",
                "",
            ].join("\n"),
        });
        const perform = typed.find(
            (n) => n.kind === "method" && n.display_name === "perform",
        );
        expect(perform?.attributes.overrides).toBe(true);
    });

    it("leaves a property that holds no function out of the graph's methods", () => {
        expect(method("name")).toBeUndefined();
        expect(method("label")).toBeUndefined();
    });
});
