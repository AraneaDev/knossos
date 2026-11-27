import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

import { TypeScriptScanner } from "../scanner.js";
import { SCAN_LIMITS } from "./support/scan-limits.mjs";

// A test file's mocks, spies and stubs are facts of their own: the core
// checks each against the production code it stands in for. The fixtures are
// the ones Momus and Nemesis were built against, with their expected lines.

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
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-doubles-")),
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

function doubles(nodes, path) {
    return nodes.filter(
        (node) => node.kind === "test_double" && node.evidence.path === path,
    );
}

function doubleAt(nodes, path, line, pattern) {
    const found = doubles(nodes, path).filter(
        (node) =>
            node.evidence.start_line === line &&
            (pattern === undefined || node.attributes.pattern === pattern),
    );
    expect(found).toHaveLength(1);
    return found[0];
}

describe("a spy", () => {
    it("records a spyOn double with its member, edge and pinned value", () => {
        const { nodes, edges } = scan(fixture("momus"));
        const spy = doubleAt(nodes, "tests/ledger.test.ts", 16);

        expect(spy.canonical_name).toBe("tests/ledger.test.ts#double:16:17");
        expect(spy.attributes).toMatchObject({
            framework: "vitest",
            pattern: "vi.spyOn",
            target: {
                kind: "instance-member",
                export: "LedgerService",
                member: "totalForX",
            },
            automock: false,
        });
        expect(spy.attributes.members).toEqual([
            { name: "totalForX", line: 16, api: "spyOn", returns: [] },
        ]);
        expect(
            edges.filter(
                (edge) =>
                    edge.kind === "doubles" && edge.source === spy.local_id,
            ),
        ).toMatchObject([
            { target: "ts:class:src/services/ledger.ts#LedgerService" },
        ]);
        expect(
            edges.some(
                (edge) =>
                    edge.kind === "contains" &&
                    edge.source === "ts:module:tests/ledger.test.ts" &&
                    edge.target === spy.local_id,
            ),
        ).toBe(true);
    });

    it("marks a pinned string not assignable to number", () => {
        const { nodes } = scan(fixture("momus"));
        const path = "tests/assignability.test.ts";
        const returns = (line) =>
            doubleAt(nodes, path, line).attributes.members[0].returns;

        expect(returns(7)).toEqual([
            {
                api: "mockReturnValue",
                line: 9,
                once: false,
                assignable: false,
                typed: true,
            },
        ]);
        expect(returns(15)).toMatchObject([
            { line: 16, assignable: true, typed: true },
        ]);
        // A rejection reason is no return value; nothing declares its type.
        expect(returns(22)).toMatchObject([
            { api: "mockRejectedValue", line: 23, assignable: "unknown" },
        ]);
        expect(returns(29)).toMatchObject([
            // `() => 'nope'` declares no return type: an inferred side.
            {
                api: "mockImplementation",
                line: 30,
                assignable: false,
                typed: false,
            },
        ]);
        expect(returns(36)).toMatchObject([
            {
                api: "mockReturnValueOnce",
                line: 37,
                once: true,
                assignable: false,
            },
        ]);
    });
});

describe("an implementation that declares no return type", () => {
    it("does not read a widened literal as drift", () => {
        const { nodes } = scan(fixture("momus"));
        // The healthy twin: `status: 'open'` widens to `string` in the
        // inferred return, yet fits `'open' | 'closed'` as written.
        expect(
            doubleAt(nodes, "tests/signature.fixture.ts", 12).attributes
                .members[0].returns,
        ).toMatchObject([{ api: "mockImplementation", assignable: true }]);
    });

    it("holds each returned expression of an inferred implementation", () => {
        const { nodes } = scan({
            "src/row.ts":
                "export interface Row { id: string; status: 'open' | 'closed' }\n" +
                "export class Repo {\n" +
                "  load(): Row { throw new Error(); }\n" +
                "  async fetch(): Promise<Row> { throw new Error(); }\n" +
                "}\n",
            "tests/widen.test.ts":
                "import { vi } from 'vitest';\n" +
                "import { Repo } from '../src/row';\n" +
                "const repo = new Repo();\n" +
                "vi.spyOn(repo, 'load').mockImplementation(() => ({ id: 'a', status: 'open' }));\n" +
                "vi.spyOn(repo, 'load').mockImplementation(() => { if (repo) return { id: 'a', status: 'closed' }; return { id: 'b', status: 'open' }; });\n" +
                "vi.spyOn(repo, 'load').mockImplementation(() => ({ id: 'a', status: 'gone' }));\n" +
                "vi.spyOn(repo, 'fetch').mockImplementation(async () => ({ id: 'a', status: 'open' }));\n" +
                "vi.spyOn(repo, 'load').mockImplementation(() => ({ id: 1, status: 'open' }));\n" +
                "vi.spyOn(repo, 'load').mockImplementation(() => ({ status: 'open' }));\n",
        });
        const assignable = (line) =>
            doubleAt(nodes, "tests/widen.test.ts", line).attributes.members[0]
                .returns[0].assignable;

        expect([4, 5, 6, 7, 8, 9].map(assignable)).toEqual([
            true,
            true,
            false,
            true,
            false,
            false,
        ]);
    });
});

const ROW =
    "export interface Row { id: string; status: 'open' | 'closed' }\n" +
    "export interface Stamp { at: number }\n" +
    "export class Repo {\n" +
    "  load(): Row { throw new Error(); }\n" +
    "  fetch(): Promise<Row> { throw new Error(); }\n" +
    "  both(): Row & Stamp { throw new Error(); }\n" +
    "}\n";

describe("a pinned value held as written", () => {
    it("fits a literal union whatever form pins it", () => {
        const { nodes } = scan({
            "src/row.ts": ROW,
            "tests/pins.test.ts":
                "import { vi } from 'vitest';\n" +
                "import { Repo } from '../src/row';\n" +
                "const repo = new Repo();\n" +
                "vi.spyOn(repo, 'load').mockReturnValue({ id: 'a', status: 'open' });\n" +
                "vi.spyOn(repo, 'fetch').mockResolvedValue({ id: 'a', status: 'open' });\n" +
                "vi.spyOn(repo, 'fetch').mockImplementation(() => Promise.resolve({ id: 'a', status: 'open' }));\n" +
                "vi.spyOn(repo, 'both').mockImplementation(() => ({ id: 'a', status: 'open', at: 1 }));\n" +
                "vi.spyOn(repo, 'load').mockReturnValue({ id: 'a', status: 'gone' });\n" +
                "vi.spyOn(repo, 'both').mockImplementation(() => ({ id: 'a', status: 'open' }));\n" +
                "vi.spyOn(repo, 'fetch').mockResolvedValue({ id: 'a', status: 'gone' });\n",
        });
        const pinned = (line) =>
            doubleAt(nodes, "tests/pins.test.ts", line).attributes.members[0]
                .returns[0];

        // Healthy twins: no drift from a literal the checker would widen.
        expect(pinned(4)).toMatchObject({ assignable: true, typed: true });
        expect(pinned(5)).toMatchObject({ assignable: true, typed: true });
        expect(pinned(6).assignable).toBe(true);
        expect(pinned(7).assignable).toBe(true);
        // Planted: a status the union lacks, a property the intersection needs.
        expect(pinned(8)).toMatchObject({ assignable: false, typed: true });
        expect(pinned(9).assignable).toBe(false);
        expect(pinned(10)).toMatchObject({ assignable: false, typed: true });
    });

    it("holds a literal cast to any as written, to no declared type", () => {
        const { nodes } = scan({
            "src/row.ts": ROW,
            "tests/cast.test.ts":
                "import { vi } from 'vitest';\n" +
                "import { Repo, Row } from '../src/row';\n" +
                "const repo = new Repo();\n" +
                "const loose: any = {};\n" +
                "vi.spyOn(repo, 'fetch').mockResolvedValue({ id: 'a', status: 'open' } as any);\n" +
                "vi.spyOn(repo, 'load').mockReturnValue(loose as any);\n" +
                "vi.spyOn(repo, 'fetch').mockResolvedValue({ id: 'a' } as any);\n" +
                "vi.spyOn(repo, 'load').mockReturnValue({ id: 'a', status: 'gone' } as unknown as Row);\n",
        });
        const pinned = (line) =>
            doubleAt(nodes, "tests/cast.test.ts", line).attributes.members[0]
                .returns[0];

        // Healthy twins: a whole literal, and a value with nothing written to hold.
        expect(pinned(5)).toMatchObject({ assignable: true, typed: false });
        expect(pinned(6)).toMatchObject({ assignable: true, typed: false });
        // Planted (Nemesis UserService.spec.ts:17): the literal lacks `status`.
        expect(pinned(7)).toMatchObject({ assignable: false, typed: false });
        // A cast to a named type is that type, as the checker reads it.
        expect(pinned(8)).toMatchObject({ assignable: true, typed: true });
    });
});

describe("a spy on a module namespace", () => {
    it("targets the module and names no absolute path", () => {
        const { root, nodes, edges } = scan({
            "src/util.ts": "export function f(): number { return 1; }\n",
            "tests/n.test.ts":
                "import { vi } from 'vitest';\n" +
                "import * as util from '../src/util';\n" +
                "vi.spyOn(util, 'f').mockReturnValue(2);\n" +
                "util.f();\n",
        });
        const spy = doubleAt(nodes, "tests/n.test.ts", 3);

        expect(spy.attributes).toMatchObject({
            target: { kind: "module", specifier: "../src/util", member: "f" },
            invocations: 1,
            members: [
                { name: "f", returns: [{ assignable: true, typed: true }] },
            ],
        });
        expect(spy.attributes.target.export).toBeUndefined();
        expect(JSON.stringify(spy)).not.toContain(root);
        expect(
            edges
                .filter(
                    (edge) =>
                        edge.kind === "doubles" && edge.source === spy.local_id,
                )
                .map((edge) => edge.target),
        ).toEqual(["ts:module:src/util.ts"]);
    });
});

describe("a module mock", () => {
    it("records module mocks with their factory keys", () => {
        const { nodes, edges } = scan({
            ...fixture("momus"),
            "tests/partial.test.ts":
                "import { jest } from '@jest/globals';\n" +
                "jest.doMock('../src/services/invoice', async (importOriginal) => {\n" +
                "  const actual = await importOriginal();\n" +
                "  return { ...actual, InvoiceService: jest.fn(), [key]: jest.fn() };\n" +
                "});\n" +
                "jest.mock('axios');\n",
        });
        const mock = doubleAt(nodes, "tests/ledger.test.ts", 5, "vi.mock");

        expect(mock.attributes).toMatchObject({
            framework: "vitest",
            target: { kind: "module", specifier: "../src/services/db" },
            automock: false,
        });
        expect(mock.attributes.members).toEqual([
            { name: "Db", line: 5, api: "mockFactoryKey", returns: [] },
        ]);
        expect(
            edges.find(
                (edge) =>
                    edge.kind === "doubles" && edge.source === mock.local_id,
            )?.target,
        ).toBe("ts:module:src/services/db.ts");

        const partial = doubleAt(nodes, "tests/partial.test.ts", 2);
        expect(partial.attributes.framework).toBe("jest");
        expect(partial.attributes.pattern).toBe("jest.doMock");
        expect(partial.attributes.members.map((member) => member.name)).toEqual(
            ["InvoiceService"],
        );
        const automock = doubleAt(nodes, "tests/partial.test.ts", 6);
        expect(automock.attributes).toMatchObject({
            pattern: "jest.mock",
            target: { kind: "module", specifier: "axios" },
            automock: true,
            members: [],
        });
    });
});

describe("what makes no double or member", () => {
    it("skips computed member names", () => {
        const { nodes } = scan({
            "tests/computed.test.ts":
                "import { vi } from 'vitest';\n" +
                "const name = 'run';\n" +
                "const key = 'go';\n" +
                "const obj = { run: () => 1 };\n" +
                "vi.spyOn(obj, name);\n" +
                "const double = { [key]: vi.fn() };\n" +
                "void double;\n",
        });
        const spy = doubleAt(nodes, "tests/computed.test.ts", 5, "vi.spyOn");
        const literal = doubleAt(
            nodes,
            "tests/computed.test.ts",
            6,
            "object-literal",
        );

        expect(spy.attributes.members).toEqual([]);
        expect(spy.attributes.target.member).toBeUndefined();
        expect(literal.attributes.members).toEqual([]);
    });

    it("emits nothing for files outside test modules", () => {
        const { nodes } = scan({
            "src/helpers.ts":
                "import { vi } from 'vitest';\n" +
                "export const stub = { run: vi.fn() };\n" +
                "vi.mock('./other', () => ({ a: vi.fn() }));\n",
        });

        expect(nodes.filter((node) => node.kind === "test_double")).toEqual([]);
    });

    it("recognises test modules by directory and by file name", () => {
        const source = "import { vi } from 'vitest';\nvi.fn();\n";
        // A `.test` that does not end the stem is no test name, as the core
        // reads it (G1-04); `ThingTest` and `test_thing` are.
        const { nodes } = scan({
            "src/a.test.ts": source,
            "src/b.spec.ts": source,
            "src/c.test.helper.ts": source,
            "src/ThingTest.ts": source,
            "src/test_thing.ts": source,
            "src/__tests__/d.ts": source,
            "src/__mocks__/e.ts": source,
            "test/f.ts": source,
            "src/tests/g.ts": source,
            "src/test/h.ts": source,
        });

        expect(
            [
                ...new Set(
                    nodes
                        .filter((node) => node.kind === "test_double")
                        .map((node) => node.evidence.path),
                ),
            ].sort(),
        ).toEqual([
            "src/ThingTest.ts",
            "src/__mocks__/e.ts",
            "src/__tests__/d.ts",
            "src/a.test.ts",
            "src/b.spec.ts",
            "src/test/h.ts",
            "src/test_thing.ts",
            "test/f.ts",
        ]);
    });
});

describe("a double's reach", () => {
    it("counts a double's call sites and hand-offs in its file", () => {
        const { nodes } = scan({
            "tests/calls.test.ts":
                "import { vi } from 'vitest';\n" +
                "const twice = vi.fn();\n" +
                "twice();\n" +
                "twice(1);\n" +
                "const never = vi.fn();\n" +
                "const handed = vi.fn();\n" +
                "run({ handed });\n" +
                "expect(never).not.toHaveBeenCalled();\n" +
                "declare function run(o: object): void;\n" +
                "declare function expect(v: unknown): any;\n",
        });
        const invocations = (line) =>
            doubleAt(nodes, "tests/calls.test.ts", line).attributes.invocations;

        expect(invocations(2)).toBe(2);
        expect(invocations(5)).toBe(0);
        expect(invocations(6)).toBe(1);
    });

    it("tells apart doubles that share a name in different tests", () => {
        const { nodes } = scan(fixture("momus"));
        const path = "tests/assignability.test.ts";

        // Each test's `svc.totalCents()` runs its own `spy`, not the others.
        expect(
            [7, 15, 29, 36].map(
                (line) => doubleAt(nodes, path, line).attributes.invocations,
            ),
        ).toEqual([1, 1, 1, 1]);
    });
});

describe("an object standing in for a class", () => {
    it("records object-literal, proxy and mocked-instance doubles", () => {
        const { nodes, edges } = scan(fixture("momus"));
        const target = (node) =>
            edges.find(
                (edge) =>
                    edge.kind === "doubles" && edge.source === node.local_id,
            )?.target;

        const wrongDb = doubleAt(
            nodes,
            "tests/signature.fixture.ts",
            16,
            "object-literal",
        );
        expect(wrongDb.attributes.target).toEqual({
            kind: "class",
            export: "Db",
        });
        expect(wrongDb.attributes.members).toEqual([
            {
                name: "query",
                line: 17,
                api: "objectLiteralKey",
                params: [
                    { name: "sql", type: "string" },
                    { name: "id", type: "string" },
                    { name: "extra", type: "boolean" },
                ],
                returns: [],
            },
        ]);
        expect(target(wrongDb)).toBe("ts:class:src/services/db.ts#Db");

        const overArity = doubleAt(nodes, "tests/signature.fixture.ts", 6);
        expect(overArity.attributes.members[0].params).toEqual([
            { name: "id", type: "string" },
            { name: "extra", type: "boolean" },
        ]);

        const proxy = doubleAt(nodes, "tests/proxy.fixture.ts", 4, "proxy");
        expect(proxy.attributes.target).toEqual({
            kind: "class",
            export: "LedgerService",
        });
        expect(doubles(nodes, "tests/proxy.fixture.ts")).toHaveLength(2);

        const echoed = doubleAt(
            nodes,
            "tests/ledger.test.ts",
            9,
            "object-literal",
        );
        expect(echoed.attributes.members[0].returns).toMatchObject([
            { api: "mockReturnValue", line: 10, assignable: "unknown" },
        ]);

        const mocked = doubleAt(
            nodes,
            "tests/ledger.test.ts",
            22,
            "vi.mocked-instance",
        );
        expect(mocked.attributes.target).toEqual({
            kind: "class",
            export: "Db",
        });
        // `query<T>(): Promise<T[]>` names no type a value can be held to.
        expect(mocked.attributes.members).toMatchObject([
            {
                name: "query",
                line: 23,
                api: "instance-member",
                returns: [
                    {
                        api: "mockResolvedValue",
                        line: 23,
                        assignable: "unknown",
                    },
                ],
            },
        ]);
        expect(target(mocked)).toBe("ts:class:src/services/db.ts#Db");
    });
});

describe("automocks, globals and chained pins", () => {
    it("records automocks and stubbed globals", () => {
        const { nodes } = scan(fixture("momus"));
        const path = "tests/automock.fixture.ts";

        expect(
            doubles(nodes, path)
                .filter((node) => node.attributes.automock)
                .map((node) => node.attributes.pattern),
        ).toEqual([
            "vi.importMock",
            "jest.requireMock",
            "jest.createMockFromModule",
        ]);
        expect(doubleAt(nodes, path, 4).attributes.framework).toBe("jest");
        expect(
            doubleAt(nodes, path, 6, "vi.stubGlobal").attributes.target,
        ).toEqual({ kind: "global", export: "fetch" });
    });

    it("pins chained values and leaves a package's double unknowable", () => {
        const { nodes } = scan(fixture("nemesis"));
        const spec = "tests/UserService.spec.ts";

        expect(doubleAt(nodes, spec, 17).attributes.members).toMatchObject([
            {
                name: "getProfile",
                returns: [
                    {
                        api: "mockResolvedValue",
                        line: 17,
                        // `{ id: '1' } as any` holds the value to no type.
                        typed: false,
                    },
                ],
            },
        ]);
        expect(
            doubleAt(nodes, spec, 37).attributes.members[0].returns,
        ).toMatchObject([{ assignable: true, typed: true }]);

        // `new UserService() as any` is still a UserService.
        expect(doubleAt(nodes, spec, 23).attributes.target).toEqual({
            kind: "instance-member",
            export: "UserService",
            member: "purgeCache",
        });

        const fsSpy = doubleAt(nodes, "tests/packageMock.spec.ts", 11);
        expect(fsSpy.attributes.members).toMatchObject([
            {
                name: "readFileSync",
                returns: [{ line: 11, assignable: "unknown", typed: false }],
            },
        ]);
    });
});
