/**
 * Whether a file is test code, by the conventions the core's
 * `TestModuleRule::isTestPath` reads from its path, and with the same
 * answers: a directory a test runner owns, or a test file name (`*.test.*`,
 * `*.spec.*`, `test_*`, `*_test`, `*Test`). Only such a file's mocks are test
 * doubles; a `vi.fn()` in product code is something else. One table of paths
 * (`tests/Fixtures/test-module-paths.json`) holds both to the same answers.
 */

/** Directories that hold tests wherever they are nested. */
const TEST_DIRECTORIES = ["__tests__", "__test__", "__fixtures__", "__mocks__"];

/** Directories that hold tests only outside a package's source root. */
const ROOT_TEST_DIRECTORIES = ["tests", "test", "spec", "e2e", "fixtures"];

/** Directories a package keeps its product code in. */
const SOURCE_ROOTS = ["src", "lib", "app", "source"];

/** Whether a project-relative path, with forward slashes, is a test module. */
export function isTestModule(relative) {
    const segments = relative.split("/");
    const file = segments.pop() ?? "";
    let underSourceRoot = false;
    let previous = null;
    for (const segment of segments) {
        const lower = segment.toLowerCase();
        if (TEST_DIRECTORIES.includes(lower)) return true;
        if (ROOT_TEST_DIRECTORIES.includes(lower) && !underSourceRoot)
            return true;
        // `src/test/` is the one test directory a source root holds.
        if (lower === "test" && previous === "src") return true;
        // Laravel's model factories are test support.
        if (lower === "factories" && previous === "database") return true;
        if (SOURCE_ROOTS.includes(lower)) underSourceRoot = true;
        previous = lower;
    }
    return isTestFileName(file);
}

/**
 * Whether a file name is a test's: its stem, the name less its last
 * extension, ends in `.test` or `.spec` (`a.test.ts`, never `a.test.helpers.ts`),
 * starts `test_` or ends `_test`, or ends in `Test` at a word boundary
 * (`ThingTest`, never `contest`).
 */
function isTestFileName(file) {
    const stem = file.replace(/\.[^.]+$/, "");
    const lower = stem.toLowerCase();
    return (
        lower.endsWith(".test") ||
        lower.endsWith(".spec") ||
        lower.startsWith("test_") ||
        lower.endsWith("_test") ||
        /(?:^|[^A-Za-z])Test$|[a-z0-9]Test$/.test(stem)
    );
}
