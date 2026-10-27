import { describe, it, expect, afterEach } from "vitest";
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { TypeScriptScanner } from "../scanner.js";
import { SCAN_LIMITS } from "./support/scan-limits.mjs";

const created = [];

/** Materialize a { relativePath: contents } map into a fresh temp project root. */
function fixture(files) {
    const root = mkdtempSync(join(tmpdir(), "knossos-hooks-"));
    created.push(root);
    for (const [rel, contents] of Object.entries(files)) {
        const abs = join(root, rel);
        mkdirSync(dirname(abs), { recursive: true });
        writeFileSync(abs, contents);
    }
    return root;
}

/** The target of every uses_hook edge the scan emitted. */
function hookTargets(root, files) {
    const contributions = [];
    new TypeScriptScanner().scan({ limits: SCAN_LIMITS, root, files }, (c) =>
        contributions.push(c),
    );
    return contributions
        .flatMap((c) => c.edges)
        .filter((edge) => edge.kind === "uses_hook")
        .map((edge) => edge.target)
        .sort();
}

afterEach(() => {
    while (created.length > 0) {
        rmSync(created.pop(), { recursive: true, force: true });
    }
});

describe("react hook edges", () => {
    // The whole dotted chain was tested for a `use` prefix, so a receiver
    // named user or users turned user.push() and users.join() into hooks.
    it("does not take a method on a receiver named user for a hook", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "src/list.ts": [
                "export class List {",
                "  push(item: string) {}",
                "  join(separator: string) { return separator; }",
                "}",
                "",
            ].join("\n"),
            "src/run.ts": [
                "import { List } from './list';",
                "export function run(user: List, users: List) {",
                "  user.push('x');",
                "  users.join(',');",
                "}",
                "",
            ].join("\n"),
        });

        expect(hookTargets(root, ["src/run.ts", "src/list.ts"])).toEqual([]);
    });

    it("keeps a hook called by name or through a namespace", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "src/hooks.ts": [
                "export function useCounter() { return 1; }",
                "export function user() { return 2; }",
                "",
            ].join("\n"),
            "src/Counter.ts": [
                "import * as Hooks from './hooks';",
                "import { useCounter, user } from './hooks';",
                "export function Counter() {",
                "  useCounter();",
                "  Hooks.useCounter();",
                "  user();",
                "}",
                "",
            ].join("\n"),
        });

        expect(hookTargets(root, ["src/Counter.ts", "src/hooks.ts"])).toEqual([
            "ts:function:src/hooks.ts#useCounter",
            "ts:function:src/hooks.ts#useCounter",
        ]);
    });
});
