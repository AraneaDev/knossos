import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";

// `private onBlur = withBatchedUpdates(() => { ... })` is a handler written as
// a class property and wrapped once, by batching, debouncing or throttling.
// It is called like a method, so the graph must hold it as one: without that,
// `this.onBlur()` resolved to nothing and the calls in it belonged to the class.

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function scan(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-wrapped-")),
    );
    created.push(root);
    for (const [path, contents] of Object.entries(files)) {
        fs.mkdirSync(dirname(join(root, path)), { recursive: true });
        fs.writeFileSync(join(root, path), contents);
    }
    const contributions = [];
    new TypeScriptScanner().scan({ root, files: Object.keys(files) }, (c) =>
        contributions.push(c),
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

describe("a class property that wraps a function", () => {
    const { nodes, edges } = scan({
        "package.json": '{"name":"fixture"}',
        "tsconfig.json":
            '{"compilerOptions":{"strict":false},"include":["src"]}',
        "src/app.ts": [
            "const withBatchedUpdates = <T extends (...args: any[]) => void>(fn: T): T => fn;",
            "const debounce = <T extends (...args: any[]) => void>(fn: T, ms: number): T => fn;",
            "const makeCache = (options: { size: number }) => new Map<string, number>();",
            "export class App {",
            "  private cache = makeCache({ size: 3 });",
            "  private seen = new Map<string, number>();",
            "  private onBlur = withBatchedUpdates(() => {",
            "    this.save();",
            "  });",
            "  private onScroll = debounce(() => {",
            "    this.save();",
            "  }, 300);",
            "  save(): void {}",
            "  run(): void {",
            "    this.onBlur();",
            "    this.onScroll();",
            "  }",
            "}",
            "",
        ].join("\n"),
    });
    const named = (name) => nodes.find((n) => n.display_name === name);

    it.each(["onBlur", "onScroll"])(
        "holds %s as a method its callers reach, owning the calls inside it",
        (name) => {
            const handler = named(name);
            expect(handler?.kind).toBe("method");
            expect(calls(edges, named("run").local_id, handler.local_id)).toBe(
                true,
            );
            expect(calls(edges, handler.local_id, named("save").local_id)).toBe(
                true,
            );
        },
    );

    it("leaves a property built by a call that takes no function a property", () => {
        expect(named("cache")?.kind).toBe("property");
        expect(named("seen")?.kind).toBe("property");
    });
});
