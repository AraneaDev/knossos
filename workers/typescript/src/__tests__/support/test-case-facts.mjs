// Scans a small project, or one of the Momus and Nemesis fixture projects,
// and finds the test facts of its test files by line, for the test-case
// tests. Each scan's directory is removed after the test that made it.

import { afterEach, expect } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

import { TypeScriptScanner } from "../../scanner.js";
import { SCAN_LIMITS } from "./scan-limits.mjs";

const FIXTURES = join(
    dirname(fileURLToPath(import.meta.url)),
    "../../../../../tests/Fixtures/test-fidelity/typescript",
);

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

export function scan(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-cases-")),
    );
    created.push(root);
    for (const [path, contents] of Object.entries(files)) {
        fs.mkdirSync(dirname(join(root, path)), { recursive: true });
        fs.writeFileSync(join(root, path), contents);
    }
    const contributions = [];
    new TypeScriptScanner().scan(
        {
            limits: SCAN_LIMITS,
            root,
            files: Object.keys(files).filter((path) => path.endsWith(".ts")),
        },
        (c) => contributions.push(c),
    );
    return {
        root,
        nodes: contributions.flatMap((c) => c.nodes),
        edges: contributions.flatMap((c) => c.edges),
        diagnostics: contributions.flatMap((c) => c.diagnostics),
    };
}

/** Every file of a fixture project, by its path inside the project. */
export function fixture(name) {
    const base = join(FIXTURES, name);
    const files = {};
    for (const entry of fs.readdirSync(base, { recursive: true })) {
        const path = join(base, entry);
        if (fs.statSync(path).isFile())
            files[relative(base, path)] = fs.readFileSync(path, "utf8");
    }
    return files;
}

export function testCases(nodes, path) {
    return nodes.filter(
        (node) => node.kind === "test_case" && node.evidence.path === path,
    );
}

export function testAt(nodes, path, line) {
    const found = testCases(nodes, path).filter(
        (node) => node.evidence.start_line === line,
    );
    expect(found).toHaveLength(1);
    return found[0];
}

/** The one assertion of a file on a line, with the test it belongs to. */
export function assertionAt(nodes, path, line) {
    const found = testCases(nodes, path).flatMap((node) =>
        node.attributes.assertions
            .filter((assertion) => assertion.line === line)
            .map((assertion) => ({ test: node, ...assertion })),
    );
    expect(found).toHaveLength(1);
    return found[0];
}

export function doubleAt(nodes, path, line, pattern) {
    const found = nodes.filter(
        (node) =>
            node.kind === "test_double" &&
            node.evidence.path === path &&
            node.evidence.start_line === line &&
            (pattern === undefined || node.attributes.pattern === pattern),
    );
    expect(found).toHaveLength(1);
    return found[0];
}
