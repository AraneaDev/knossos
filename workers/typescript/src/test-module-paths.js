/**
 * Whether a file is test code, by the conventions the core's
 * `TestModuleRule` reads from its path: a directory a test runner owns, or a
 * `*.test.*` or `*.spec.*` file name. Only such a file's mocks are test
 * doubles; a `vi.fn()` in product code is something else.
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
        if (SOURCE_ROOTS.includes(lower)) underSourceRoot = true;
        previous = lower;
    }
    return /\.(?:test|spec)\./i.test(file);
}
