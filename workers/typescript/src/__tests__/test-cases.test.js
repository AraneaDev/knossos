import { describe, expect, it } from "vitest";

import {
    assertionAt,
    doubleAt,
    fixture,
    scan,
    testAt,
    testCases,
} from "./support/test-case-facts.mjs";

// A test file's tests are facts of their own: each test's assertions, with
// where each side's value comes from, so the core can find the assertions
// that cannot fail. The fixtures are the ones Momus was built against, with
// its expected lines.

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
            { source: "mock-config", value: "42", double: eachMock },
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
            assertionAt(nodes, path, line).operands.map(
                ({ source, text, value }) => ({ source, text, value }),
            );

        // beforeAll at module level, then the nested describes' beforeEach.
        expect(configured(22)).toEqual([
            { source: "mock-config", value: "7" },
            { source: "literal", text: "7" },
        ]);
        expect(configured(39)).toEqual([
            { source: "mock-config", value: "11" },
            { source: "literal", text: "11" },
        ]);
        expect(configured(49)).toEqual([
            { source: "mock-config", value: "22" },
            { source: "literal", text: "22" },
        ]);
        // A stub configured in the test body itself.
        expect(
            assertionAt(nodes, "tests/ledger.test.ts", 11).operands,
        ).toMatchObject([
            { source: "mock-config", value: "42" },
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
                "  const got = svc.total();",
                "  expect(got).toBe(5);",
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
                value: "5",
                double: doubleAt(nodes, path, 5).canonical_name,
            },
            { source: "literal", text: "5" },
        ]);
        // A variable holding the stub's answer reads as written, and carries
        // the value pinned.
        expect(assertionAt(nodes, path, 8).operands[0]).toEqual({
            source: "mock-config",
            text: "got",
            value: "5",
            double: doubleAt(nodes, path, 5).canonical_name,
        });
        expect(assertionAt(nodes, path, 13).operands).toEqual([
            { source: "production" },
            { source: "literal", text: "1" },
        ]);
    });
});

describe("which calls define a test", () => {
    // G1-10: every Vitest form of `it`/`test` is read; `it.fails` says that
    // it passes only when its body fails; two tests on one line are two.
    it("reads concurrent, todo, fails, skipIf and runIf tests, and both tests of a line", () => {
        const { nodes } = scan({
            "tests/forms.test.ts": [
                "import { describe, expect, it, test } from 'vitest';",
                "describe.concurrent('forms', () => {",
                "  it.concurrent('concurrent', () => { expect(1).toBe(1); });",
                "  test.concurrent.only('concurrent only', () => { expect(1).toBe(1); });",
                "  it.todo('todo', () => { expect(1).toBe(1); });",
                "  it.todo('todo without a body');",
                "  it.fails('fails', () => { expect(1).toBe(2); });",
                "  it.skipIf(process.env.CI)('skip if', () => { expect(1).toBe(1); });",
                "  test.runIf(true)('run if', () => { expect(1).toBe(1); });",
                "  it.each([1])('each %i', (n) => { expect(n).toBe(n); });",
                "  it('one', () => { expect(2).toBe(2); }); it('two', () => { expect(3).toBe(3); });",
                "  it.sideways('no test', () => { expect(1).toBe(1); });",
                "  describe.sideways('no suite', () => {});",
                "});",
                "",
            ].join("\n"),
        });
        const path = "tests/forms.test.ts";
        const cases = testCases(nodes, path).map((node) => [
            node.canonical_name,
            node.display_name,
            node.attributes.fails ?? false,
        ]);

        expect(cases).toEqual([
            [`${path}#test:3`, "concurrent", false],
            [`${path}#test:4`, "concurrent only", false],
            [`${path}#test:5`, "todo", false],
            [`${path}#test:7`, "fails", true],
            [`${path}#test:8`, "skip if", false],
            [`${path}#test:9`, "run if", false],
            [`${path}#test:10`, "each %i", false],
            [`${path}#test:11`, "one", false],
            [`${path}#test:11.2`, "two", false],
        ]);
        expect(assertionAt(nodes, path, 3).operands).toEqual([
            { source: "literal", text: "1" },
            { source: "literal", text: "1" },
        ]);
        expect(
            testCases(nodes, path)
                .filter((node) => node.evidence.start_line === 11)
                .map((node) => node.attributes.assertions[0].operands[0].text),
        ).toEqual(["2", "3"]);
    });

    it("names on each double the test of its line it was made in", () => {
        const { nodes } = scan({
            "tests/line.test.ts": [
                "import { it, vi } from 'vitest';",
                "it('a', () => { vi.fn(); }); it('b', () => { vi.fn().mockReturnValue(1); });",
                "",
            ].join("\n"),
        });

        expect(
            nodes
                .filter((node) => node.kind === "test_double")
                .map((node) => node.attributes.test_case),
        ).toEqual(["tests/line.test.ts#test:2", "tests/line.test.ts#test:2.2"]);
    });
});

describe("the value an implementation pins", () => {
    // G1-09: a `return` inside a nested function is not the stub's, and a
    // body that returns more than one value pins none of them.
    it("reads the one literal every return of the body itself hands back", () => {
        const { nodes } = scan({
            "tests/echo.test.ts": [
                "import { describe, it, expect, vi } from 'vitest';",
                "",
                "describe('echo', () => {",
                "  it('nested return via impl', () => {",
                "    const total = vi.fn();",
                "    total.mockImplementation((xs: number[]) => { const ys = xs.map(() => { return 1; }); return ys.length * 5; });",
                "    expect(total([3, 4])).toBe(1);",
                "  });",
                "  it('conditional impl', () => {",
                "    const total = vi.fn();",
                "    total.mockImplementation((xs: number[]) => { if (xs.length === 0) { return 0; } return xs.length * 3; });",
                "    expect(total([])).toBe(0);",
                "  });",
                "  it('every return the same literal', () => {",
                "    const total = vi.fn();",
                "    total.mockImplementation(function (xs: number[]) { if (xs.length === 0) { return 4; } return 4; });",
                "    expect(total([])).toBe(4);",
                "  });",
                "  it('a bare return', () => {",
                "    const total = vi.fn();",
                "    total.mockImplementation((xs: number[]) => { if (xs.length === 0) { return; } return 4; });",
                "    expect(total([])).toBe(4);",
                "  });",
                "});",
                "",
            ].join("\n"),
        });
        const pinned = (line) =>
            assertionAt(nodes, "tests/echo.test.ts", line).operands[0].value;

        expect(pinned(7)).toBe(
            "(xs: number[]) => { const ys = xs.map(() => { return 1; }); return ys.length * 5; }",
        );
        expect(pinned(12)).toBe(
            "(xs: number[]) => { if (xs.length === 0) { return 0; } return xs.length * 3; }",
        );
        expect(pinned(17)).toBe("4");
        expect(pinned(22)).toBe(
            "(xs: number[]) => { if (xs.length === 0) { return; } return 4; }",
        );
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
        // G1-11: the `import()` of the module and the call of what it hands
        // back are two calls into production.
        expect(testAt(nodes, path, 14).attributes).toMatchObject({
            production_calls: 2,
            subjects: ["src/engine.ts"],
            assertions: [{ operands: [{ source: "production" }, {}] }],
        });
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

    it("reads an assertion through .resolves and .rejects, and marks one through .not", () => {
        const { nodes } = scan({
            "tests/promise.test.ts": [
                "import { expect, it } from 'vitest';",
                "it('settles', async () => {",
                "  await expect(Promise.reject(new Error('x'))).rejects.toThrow('x');",
                "  await expect(Promise.resolve(1)).resolves.not.toBe(2);",
                "});",
                "",
            ].join("\n"),
        });
        const path = "tests/promise.test.ts";

        expect(assertionAt(nodes, path, 3)).toMatchObject({
            api: "toThrow",
            operands: [
                { source: "unknown" },
                { source: "literal", text: "'x'" },
            ],
        });
        expect(assertionAt(nodes, path, 3)).not.toHaveProperty("negated");
        expect(assertionAt(nodes, path, 4)).toMatchObject({
            api: "toBe",
            negated: true,
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
