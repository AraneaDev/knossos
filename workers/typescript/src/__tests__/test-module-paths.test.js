import { describe, expect, it } from "vitest";
import fs from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

import { isTestModule } from "../test-module-paths.js";

// The worker reads only a test file's mocks as doubles, and the core tags
// test modules by their path; both must call the same files test code. One
// table holds the answers, and the core's TestModuleRuleTest reads it too.

const TABLE = join(
    dirname(fileURLToPath(import.meta.url)),
    "../../../../tests/Fixtures/test-module-paths.json",
);

describe("which paths are test code", () => {
    const { paths } = JSON.parse(fs.readFileSync(TABLE, "utf8"));

    it("answers every path of the shared table as the core does", () => {
        expect(paths.length).toBeGreaterThan(30);
        expect(
            paths.map(([path]) => [path, isTestModule(path)]),
        ).toEqual(paths);
    });
});
