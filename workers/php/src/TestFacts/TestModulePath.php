<?php

declare(strict_types=1);

namespace KnossosPhpScanner\TestFacts;

/**
 * Whether a file is test code, by the path conventions the core's
 * `TestModuleRule` reads: a directory a test runner owns, or a test file
 * name such as `FooTest.php`. Only a test file's mocks are test doubles.
 *
 * Kept in step with `src/Classification/TestModuleRule.php` by hand: the
 * worker runs in its own process and cannot load the core's classes.
 */
final class TestModulePath
{
    /** Directories that hold tests wherever they are nested. */
    private const UNAMBIGUOUS_SEGMENTS = ['__tests__', '__test__', '__fixtures__', '__mocks__'];

    /** Directories that hold tests only outside a package's source root. */
    private const AMBIGUOUS_SEGMENTS = ['tests', 'test', 'spec', 'e2e', 'fixtures'];

    /** Directories a package keeps its product code in. */
    private const SOURCE_ROOTS = ['src', 'lib', 'app', 'source'];

    private function __construct() {}

    /** Whether a project-relative path, with forward slashes, is a test module. */
    public static function isTest(string $path): bool
    {
        $segments = explode('/', $path);
        $file = array_pop($segments);
        $underSourceRoot = false;
        $previous = null;
        foreach ($segments as $segment) {
            $lower = strtolower($segment);
            if (in_array($lower, self::UNAMBIGUOUS_SEGMENTS, true) || (in_array($lower, self::AMBIGUOUS_SEGMENTS, true) && !$underSourceRoot)) {
                return true;
            }
            // Maven's `src/test/`, and Laravel's model factories, which build fixtures for tests.
            if (($lower === 'test' && $previous === 'src') || ($lower === 'factories' && $previous === 'database')) {
                return true;
            }
            $underSourceRoot = $underSourceRoot || in_array($lower, self::SOURCE_ROOTS, true);
            $previous = $lower;
        }
        $stem = (string) preg_replace('/\.[^.]+$/', '', $file);
        $lower = strtolower($stem);
        if (str_ends_with($lower, '.test') || str_ends_with($lower, '.spec') || str_starts_with($lower, 'test_') || str_ends_with($lower, '_test')) {
            return true;
        }

        // PascalCase `ThingTest`: the suffix counts only at a word boundary, so `contest` or `latest` do not.
        return preg_match('/(?:^|[^A-Za-z])Test$|[a-z0-9]Test$/', $stem) === 1;
    }
}
