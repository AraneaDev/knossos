<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\OnRequest;

use Knossos\TestFidelity\Facts\TestDouble;

/**
 * The test frameworks' own modules, per language: what a test file imports to
 * run and to make its doubles, which `OVER_MOCKING` does not count among the
 * file's dependencies. Frozen, as Momus freezes its own
 * (`hygiene.ts:33`, `FRAMEWORK_SPECIFIERS`, TypeScript only).
 *
 * A name matches a listed one whole, or as a part of it: `unittest.mock`
 * under `unittest`, `@vitest/expect` under `@vitest/`, `std::sync` under
 * `std`. `vitest-extra` or `stdx` is a module like any other.
 */
final class FrameworkModules
{
    /** Per language, the frameworks by name, and the separator a part of one follows. */
    private const LISTS = [
        'typescript' => ['separator' => '/', 'names' => ['vitest', 'jest'], 'scopes' => ['@vitest', '@jest']],
        'php' => ['separator' => '\\', 'names' => ['PHPUnit', 'Mockery', 'Prophecy', 'Pest'], 'scopes' => []],
        'python' => ['separator' => '.', 'names' => ['unittest', 'unittest.mock', 'mock', 'pytest', 'pytest_mock'], 'scopes' => []],
        'rust' => ['separator' => '::', 'names' => ['std', 'pretty_assertions'], 'scopes' => []],
    ];

    private function __construct() {}

    /** Whether `$module`, imported by a test file of `$language`, is one of that language's test frameworks. */
    public static function contains(string $language, string $module): bool
    {
        $list = self::LISTS[$language] ?? null;
        if ($list === null) {
            return false;
        }
        $separator = $list['separator'];
        $names = $list['names'];
        if ($language === 'rust') {
            // A crate is named with `-` in Cargo.toml and `_` in a path.
            $names = [...$names, ...TestDouble::RUST_CRATES];
            $module = str_replace('-', '_', $module);
        }
        if ($language === 'php') {
            // PHP names are matched in any case, and may be written fully qualified.
            $module = strtolower(ltrim($module, '\\'));
            $names = array_map(strtolower(...), $names);
        }
        foreach ($names as $name) {
            if ($module === $name || str_starts_with($module, $name . $separator)) {
                return true;
            }
        }
        foreach ($list['scopes'] as $scope) {
            if (str_starts_with($module, $scope . $separator)) {
                return true;
            }
        }

        return false;
    }
}
