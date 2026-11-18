import { afterEach, describe, expect, it, vi } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";
import { SCAN_LIMITS } from "./support/scan-limits.mjs";

// A failure reading a test file's doubles or tests costs those facts alone:
// the module, its imports and its calls are what test impact, untested code
// and dead code read. The test-case pass is wrapped so a test can make it
// throw after it ran, when the doubles and tests are already emitted and the
// doubles already name their tests.
const hook = vi.hoisted(() => ({ throwAfterCases: false }));
vi.mock("../test-cases.js", async (importOriginal) => {
    const actual = await importOriginal();
    return {
        ...actual,
        collectTestCases: (...args) => {
            actual.collectTestCases(...args);
            if (hook.throwAfterCases) throw new Error("planted failure");
        },
    };
});

const FILES = {
    "src/user.ts":
        "export class UserService {\n    getProfile(id: string): string { return id; }\n}\n",
    "tests/user.test.ts":
        "import { expect, it, vi } from 'vitest';\nimport { UserService } from '../src/user';\n" +
        "it('reads', () => {\n    const service = new UserService();\n    vi.spyOn(service, 'getProfile');\n" +
        "    expect(service.getProfile('1')).toBe('1');\n});\n",
};

const created = [];

afterEach(() => {
    hook.throwAfterCases = false;
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

/** The test file's contribution. */
function scanTestFile() {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-test-facts-")),
    );
    created.push(root);
    for (const [path, contents] of Object.entries(FILES)) {
        fs.mkdirSync(dirname(join(root, path)), { recursive: true });
        fs.writeFileSync(join(root, path), contents);
    }
    const contributions = [];
    new TypeScriptScanner().scan(
        { limits: SCAN_LIMITS, root, files: Object.keys(FILES) },
        (c) => contributions.push(c),
    );
    return contributions.find(
        (c) => c.owner_key === "knossos.typescript:file:tests/user.test.ts",
    );
}

/** A contribution's facts with every test fact taken out. */
function ordinary(contribution) {
    const test = new Set(
        contribution.nodes
            .filter((n) => n.kind === "test_double" || n.kind === "test_case")
            .map((n) => n.local_id),
    );
    return {
        nodes: contribution.nodes.filter((n) => !test.has(n.local_id)),
        edges: contribution.edges.filter(
            (e) => !test.has(e.source) && !test.has(e.target),
        ),
    };
}

describe("a failure reading a test file's test facts", () => {
    it("keeps the file's other facts, reports no test fact and warns once", () => {
        const healthy = scanTestFile();
        expect(
            healthy.nodes.filter((n) => n.kind === "test_double"),
        ).toHaveLength(1);
        expect(
            healthy.nodes.find((n) => n.kind === "test_double").attributes
                .test_case,
        ).toBe("tests/user.test.ts#test:3");

        hook.throwAfterCases = true;
        const failed = scanTestFile();

        expect(ordinary(failed)).toEqual(ordinary(healthy));
        expect(failed.nodes.some((n) => n.kind === "module")).toBe(true);
        expect(
            failed.nodes.filter(
                (n) => n.kind === "test_double" || n.kind === "test_case",
            ),
        ).toEqual([]);
        expect(failed.edges.filter((e) => e.kind === "doubles")).toEqual([]);
        const warnings = failed.diagnostics.filter(
            (d) => d.code === "TS_TEST_FACTS_FAILED",
        );
        expect(warnings).toHaveLength(1);
        expect(warnings[0]).toMatchObject({
            severity: "warning",
            evidence: { path: "tests/user.test.ts", start_line: 1 },
        });
        expect(warnings[0].message).toContain("planted failure");
        expect(
            failed.diagnostics.filter((d) => d.code === "TS_INTERNAL_ERROR"),
        ).toEqual([]);
    });
});
