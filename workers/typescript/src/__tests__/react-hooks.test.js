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

/** Every uses_hook edge the scan emitted. */
function hookEdges(root, files) {
    const contributions = [];
    new TypeScriptScanner().scan({ limits: SCAN_LIMITS, root, files }, (c) =>
        contributions.push(c),
    );
    return contributions
        .flatMap((c) => c.edges)
        .filter((edge) => edge.kind === "uses_hook");
}

/** The target of every uses_hook edge the scan emitted. */
function hookTargets(root, files) {
    return hookEdges(root, files)
        .map((edge) => edge.target)
        .sort();
}

/** The framework each hook target was attributed to, by target. */
function hookFrameworks(root, files) {
    return Object.fromEntries(
        hookEdges(root, files).map((edge) => [
            edge.target,
            edge.attributes.framework,
        ]),
    );
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
                "import { useState } from 'react';",
                "export function useCounter() { return useState(1); }",
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

describe("hook edge framework", () => {
    // A hook is React's or Vue's by what the file declaring it imports, as
    // the declaration's own react.hook or vue.composable role is; every
    // edge used to say react, so a Vue composable read as a React hook.
    it("attributes a composable declared beside a vue import to vue", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "src/counter.ts": [
                "import { ref } from 'vue';",
                "export function useCounter() { return ref(0); }",
                "",
            ].join("\n"),
            "src/hooks.ts": [
                "import { useState } from 'react';",
                "export function useToggle() { return useState(false); }",
                "",
            ].join("\n"),
            "src/panel.ts": [
                "import { useCounter } from './counter';",
                "import { useToggle } from './hooks';",
                "export function setup() {",
                "  useCounter();",
                "  useToggle();",
                "}",
                "",
            ].join("\n"),
        });

        expect(
            hookFrameworks(root, [
                "src/panel.ts",
                "src/counter.ts",
                "src/hooks.ts",
            ]),
        ).toEqual({
            "ts:function:src/counter.ts#useCounter": "vue",
            "ts:function:src/hooks.ts#useToggle": "react",
        });
    });

    it("falls back to what the calling file imports", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "src/shared.ts": "export function useShared() { return 1; }\n",
            "src/panel.ts": [
                "import { computed } from 'vue';",
                "import { useShared } from './shared';",
                "export function setup() {",
                "  return computed(() => useShared());",
                "}",
                "",
            ].join("\n"),
        });

        expect(hookFrameworks(root, ["src/panel.ts", "src/shared.ts"])).toEqual(
            { "ts:function:src/shared.ts#useShared": "vue" },
        );
    });

    it("draws no hook edge where neither file names a framework", () => {
        const root = fixture({
            "package.json": '{"name":"fixture"}',
            "src/format.ts":
                "export function useFormatter() { return String; }\n",
            "src/report.ts": [
                "import { useFormatter } from './format';",
                "export function report() { return useFormatter(); }",
                "",
            ].join("\n"),
        });

        expect(hookTargets(root, ["src/report.ts", "src/format.ts"])).toEqual(
            [],
        );
    });
});
