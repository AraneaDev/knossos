import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

import { TypeScriptScanner } from "../scanner.js";
import { SCAN_LIMITS } from "./support/scan-limits.mjs";

// A test file's tests are facts of their own: each test's assertions, with
// where each side's value comes from, so the core can find the assertions
// that cannot fail. The fixtures are the ones Momus was built against, with
// its expected lines.

const FIXTURES = join(
    dirname(fileURLToPath(import.meta.url)),
    "../../../../tests/Fixtures/test-fidelity/typescript",
);

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

function scan(files) {
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
    };
}

/** Every file of a fixture project, by its path inside the project. */
function fixture(name) {
    const base = join(FIXTURES, name);
    const files = {};
    for (const entry of fs.readdirSync(base, { recursive: true })) {
        const path = join(base, entry);
        if (fs.statSync(path).isFile())
            files[relative(base, path)] = fs.readFileSync(path, "utf8");
    }
    return files;
}

function testCases(nodes, path) {
    return nodes.filter(
        (node) => node.kind === "test_case" && node.evidence.path === path,
    );
}

function testAt(nodes, path, line) {
    const found = testCases(nodes, path).filter(
        (node) => node.evidence.start_line === line,
    );
    expect(found).toHaveLength(1);
    return found[0];
}

/** The one assertion of a file on a line, with the test it belongs to. */
function assertionAt(nodes, path, line) {
    const found = testCases(nodes, path).flatMap((node) =>
        node.attributes.assertions
            .filter((assertion) => assertion.line === line)
            .map((assertion) => ({ test: node, ...assertion })),
    );
    expect(found).toHaveLength(1);
    return found[0];
}

function doubleAt(nodes, path, line, pattern) {
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

describe("an echoed stub value", () => {
    it("traces an echoed stub value to its mock-config source", () => {
        const { nodes } = scan(fixture("momus"));
        const path = "tests/before-each.test.ts";
        // The object stands in for the stub; its `vi.fn()` is a double too.
        const eachMock = doubleAt(
            nodes,
            path,
            5,
            "object-literal",
        ).canonical_name;

        const echo = assertionAt(nodes, path, 18);
        expect(echo.api).toBe("toBe");
        expect(echo.operands).toEqual([
            { source: "mock-config", text: "42", double: eachMock },
            { source: "literal", text: "42" },
        ]);
        expect(echo.test.canonical_name).toBe(`${path}#test:17`);
        expect(echo.test.attributes).toMatchObject({
            name: "uses the beforeEach configuration (TAUT-002)",
            production_calls: 0,
            subjects: [],
        });
        expect(
            nodes.filter(
                (node) =>
                    node.kind === "test_case" &&
                    node.evidence.path === "tests/ledger.test.ts",
            ),
        ).toHaveLength(4);
    });

    it("reads each setup a test runs, the nearest describe's own", () => {
        const { nodes } = scan(fixture("momus"));
        const path = "tests/before-each.test.ts";
        const configured = (line) =>
            assertionAt(nodes, path, line).operands.map(({ source, text }) => ({
                source,
                text,
            }));

        // beforeAll at module level, then the nested describes' beforeEach.
        expect(configured(22)).toEqual([
            { source: "mock-config", text: "7" },
            { source: "literal", text: "7" },
        ]);
        expect(configured(39)).toEqual([
            { source: "mock-config", text: "11" },
            { source: "literal", text: "11" },
        ]);
        expect(configured(49)).toEqual([
            { source: "mock-config", text: "22" },
            { source: "literal", text: "22" },
        ]);
        // A stub configured in the test body itself.
        expect(
            assertionAt(nodes, "tests/ledger.test.ts", 11).operands,
        ).toMatchObject([
            { source: "mock-config", text: "42" },
            { source: "literal", text: "42" },
        ]);
    });

    it("traces a value pinned on a spy to the member the test calls", () => {
        const { nodes } = scan({
            "src/svc.ts":
                "export class Svc { total(): number { return 1; } }\n",
            "tests/svc.test.ts": [
                "import { expect, it, vi } from 'vitest';",
                "import { Svc } from '../src/svc';",
                "it('echoes', () => {",
                "  const svc = new Svc();",
                "  vi.spyOn(svc, 'total').mockReturnValue(5);",
                "  expect(svc.total()).toBe(5);",
                "});",
                "it('passes through', () => {",
                "  const svc = new Svc();",
                "  vi.spyOn(svc, 'total');",
                "  expect(svc.total()).toBe(1);",
                "});",
                "",
            ].join("\n"),
        });
        const path = "tests/svc.test.ts";

        expect(assertionAt(nodes, path, 6).operands).toEqual([
            {
                source: "mock-config",
                text: "5",
                double: doubleAt(nodes, path, 5).canonical_name,
            },
            { source: "literal", text: "5" },
        ]);
        expect(assertionAt(nodes, path, 11).operands).toEqual([
            { source: "production" },
            { source: "literal", text: "1" },
        ]);
    });
});

describe("what a test exercises", () => {
    it("counts production calls and records their subjects", () => {
        const { nodes } = scan({
            "src/ledger.ts": [
                "export class LedgerService {",
                "  totalCents(): number { return 1; }",
                "}",
                "",
            ].join("\n"),
            "src/db.ts": "export class Db { query(): number { return 1; } }\n",
            "tests/ledger.test.ts": [
                "import { expect, it, vi } from 'vitest';",
                "import { LedgerService } from '../src/ledger';",
                "import { Db } from '../src/db';",
                "vi.mock('../src/db');",
                "it('totals', () => {",
                "  expect(new LedgerService().totalCents()).toBe(1);",
                "});",
                "it('builds a double from the mocked module', () => {",
                "  const db = vi.mocked(new Db());",
                "  expect(db).toBeDefined();",
                "});",
                "",
            ].join("\n"),
        });
        const path = "tests/ledger.test.ts";

        expect(testAt(nodes, path, 5).attributes).toMatchObject({
            name: "totals",
            production_calls: 1,
            subjects: ["src/ledger.ts"],
        });
        // Making a double out of a class runs none of its code.
        expect(testAt(nodes, path, 8).attributes).toMatchObject({
            production_calls: 0,
            subjects: [],
        });
    });

    it("follows setup bindings and local helpers into production", () => {
        const { nodes } = scan({
            "src/engine.ts": [
                "export class Engine { run(): number { return 1; } }",
                "export function start(): number { return 2; }",
                "",
            ].join("\n"),
            "tests/engine.test.ts": [
                "import { beforeEach, expect, it } from 'vitest';",
                "import { Engine, start } from '../src/engine';",
                "let engine: Engine;",
                "beforeEach(() => {",
                "  engine = new Engine();",
                "});",
                "const go = () => start();",
                "it('runs', () => {",
                "  expect(engine.run()).toBe(1);",
                "});",
                "it('starts', () => {",
                "  expect(go()).toBe(2);",
                "});",
                "it('loads', async () => {",
                "  const { start: later } = await import('../src/engine');",
                "  expect(later()).toBe(2);",
                "});",
                "",
            ].join("\n"),
        });
        const path = "tests/engine.test.ts";

        expect(testAt(nodes, path, 8).attributes).toMatchObject({
            production_calls: 1,
            subjects: ["src/engine.ts"],
            assertions: [
                {
                    operands: [
                        { source: "production" },
                        { source: "literal", text: "1" },
                    ],
                },
            ],
        });
        expect(testAt(nodes, path, 11).attributes).toMatchObject({
            production_calls: 1,
            subjects: ["src/engine.ts"],
            assertions: [{ operands: [{ source: "production" }, {}] }],
        });
        expect(testAt(nodes, path, 14).attributes).toMatchObject({
            subjects: ["src/engine.ts"],
            assertions: [{ operands: [{ source: "production" }, {}] }],
        });
        expect(
            testAt(nodes, path, 14).attributes.production_calls,
        ).toBeGreaterThan(0);
    });
});

describe("a healthy test", () => {
    it("keeps the healthy test quiet", () => {
        const { nodes } = scan(fixture("momus"));
        const path = "tests/before-each.test.ts";
        const healthy = testAt(nodes, path, 25);

        expect(healthy.attributes.production_calls).toBeGreaterThan(0);
        expect(healthy.attributes.subjects).toEqual(["src/services/ledger.ts"]);
        const lines = healthy.attributes.assertions.filter(
            (assertion) => assertion.line >= 23 && assertion.line <= 27,
        );
        expect(lines).toEqual([
            {
                api: "toBeDefined",
                line: 27,
                operands: [{ source: "production" }],
            },
        ]);
    });
});

describe("a double's use", () => {
    it("counts a double's invocations", () => {
        const { nodes } = scan({
            "tests/calls.test.ts": [
                "import { expect, it, vi } from 'vitest';",
                "it('calls it twice', () => {",
                "  const f = vi.fn();",
                "  f();",
                "  f();",
                "  expect(f).toHaveBeenCalledTimes(2);",
                "});",
                "",
            ].join("\n"),
        });
        const path = "tests/calls.test.ts";
        const double = doubleAt(nodes, path, 3);

        expect(double.attributes.invocations).toBe(2);
        // The double belongs to the test it was made in.
        expect(double.attributes.test_case).toBe(`${path}#test:2`);
        expect(assertionAt(nodes, path, 6).operands).toEqual([
            {
                source: "mock-call",
                text: "f",
                double: double.canonical_name,
            },
            { source: "literal", text: "2" },
        ]);
    });

    it("marks a spy that nothing configured", () => {
        const { nodes } = scan(fixture("momus"));
        const path = "tests/ledger.test.ts";
        const spy = doubleAt(nodes, path, 38);
        const assertion = assertionAt(nodes, path, 39);

        expect(assertion.api).toBe("toHaveBeenCalled");
        expect(assertion.operands).toEqual([
            {
                source: "mock-call",
                text: "spy",
                double: spy.canonical_name,
                unconfigured_spy: true,
            },
        ]);
        expect(assertion.test.attributes.production_calls).toBe(0);
        expect(spy.attributes.test_case).toBe(`${path}#test:36`);
        // A spy the test pins is configured.
        const pinned = assertionAt(nodes, "tests/assignability.test.ts", 10);
        expect(pinned.operands[0].unconfigured_spy).toBeUndefined();
    });
});

describe("what an assertion compares", () => {
    it("keeps the text that shows an operand compared with itself", () => {
        const { nodes } = scan({
            "tests/same.test.ts": [
                "import { expect, it } from 'vitest';",
                "it('compares', () => {",
                "  const value = 1;",
                "  let later = 2;",
                "  expect(value).toBe(value);",
                "  expect(later).toBe(later);",
                "  expect(Math.random()).toBe(Math.random());",
                "  expect(true).toBe(true);",
                "  expect(-1).not.toEqual(`a`);",
                "});",
                "",
            ].join("\n"),
        });
        const path = "tests/same.test.ts";
        const operands = (line) => assertionAt(nodes, path, line).operands;

        expect(operands(5)).toEqual([
            { source: "literal", text: "value" },
            { source: "literal", text: "value" },
        ]);
        // A binding that can be reassigned is no constant.
        expect(operands(6)).toEqual([
            { source: "unknown", text: "later" },
            { source: "unknown", text: "later" },
        ]);
        // A call is evaluated again on each side: no text to compare.
        expect(operands(7)).toEqual([
            { source: "unknown" },
            { source: "unknown" },
        ]);
        expect(operands(8)).toEqual([
            { source: "literal", text: "true" },
            { source: "literal", text: "true" },
        ]);
        expect(assertionAt(nodes, path, 9)).toMatchObject({
            api: "toEqual",
            operands: [
                { source: "literal", text: "-1" },
                { source: "literal", text: "`a`" },
            ],
        });
    });
});

describe("a parameterised test", () => {
    it("names a parameterised test's row a parameter", () => {
        const { nodes } = scan({
            "tests/rows.test.ts": [
                "import { describe, expect, it, test } from 'vitest';",
                "it.each([[1, 1]])('adds %i', (a, b) => {",
                "  expect(a).toBe(b);",
                "});",
                "test.each`a | b`('table %s', ({ a }) => {",
                "  expect(a.x).toBeDefined();",
                "});",
                "it.only('only', () => { expect(1).toBe(1); });",
                "test.skip('skipped', () => { expect(2).toBe(2); });",
                "describe.each([1])('group %i', (n) => {",
                "  it('inside', () => expect(n).toBe(n));",
                "});",
                "function helper() { expect(3).toBe(3); }",
                "",
            ].join("\n"),
        });
        const path = "tests/rows.test.ts";

        expect(
            testCases(nodes, path).map((node) => [
                node.evidence.start_line,
                node.attributes.name,
            ]),
        ).toEqual([
            [2, "adds %i"],
            [5, "table %s"],
            [8, "only"],
            [9, "skipped"],
            [11, "inside"],
        ]);
        expect(assertionAt(nodes, path, 3).operands).toEqual([
            { source: "parameter", text: "a" },
            { source: "parameter", text: "b" },
        ]);
        expect(assertionAt(nodes, path, 6).operands).toEqual([
            { source: "parameter", text: "a.x" },
        ]);
        // A describe's own parameter is no row of the test.
        expect(assertionAt(nodes, path, 11).operands).toEqual([
            { source: "unknown", text: "n" },
            { source: "unknown", text: "n" },
        ]);
        // An assertion outside every test belongs to no test.
        expect(
            testCases(nodes, path).some((node) =>
                node.attributes.assertions.some(
                    (assertion) => assertion.line === 13,
                ),
            ),
        ).toBe(false);
    });
});

describe("a test's facts", () => {
    it("ties each test to its module and names no absolute path", () => {
        const { root, nodes, edges } = scan(fixture("momus"));
        const tests = nodes.filter((node) => node.kind === "test_case");

        expect(tests.length).toBeGreaterThan(0);
        for (const node of tests) {
            expect(node.local_id).toBe(`ts:test_case:${node.canonical_name}`);
            expect(
                edges.some(
                    (edge) =>
                        edge.kind === "contains" &&
                        edge.source === `ts:module:${node.evidence.path}` &&
                        edge.target === node.local_id,
                ),
            ).toBe(true);
        }
        expect(JSON.stringify(nodes)).not.toContain(root);
        // Product code holds no tests, whatever it calls.
        expect(
            tests.some((node) => node.evidence.path.startsWith("src/")),
        ).toBe(false);
    });
});
