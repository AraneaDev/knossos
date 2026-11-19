<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

/**
 * The project files a double's raw module specifier can name, for a double
 * whose module resolved to nothing (`vi.mock('../src/old')` once `old.ts` is
 * gone): the stored double then keeps only the specifier, not a target.
 *
 * Read against the test file's folder with the suffixes the TypeScript worker
 * tries, and no others. A specifier with a JavaScript extension names the
 * TypeScript sources it compiles from first, then itself (the worker's
 * `TYPESCRIPT_SOURCES`, workers/typescript/src/dynamic-import-collector.js);
 * any other specifier names itself with each of the worker's
 * `REQUIRE_SUFFIXES` (workers/typescript/src/syntax-predicates.js).
 *
 * Only a relative specifier (`./`, `../`) names a path: a bare package, a
 * path alias, an absolute path, a loader query or one that climbs out of the
 * project names no file Knossos can tell, so it names none.
 */
final class MockedModulePaths
{
    /** A JavaScript extension => the TypeScript sources a specifier naming it resolves to first. */
    private const TYPESCRIPT_SOURCES = ['.js' => ['.ts', '.tsx'], '.jsx' => ['.tsx'], '.mjs' => ['.mts'], '.cjs' => ['.cts']];

    /** What a specifier may leave off, in the order the worker tries it. */
    private const SUFFIXES = [
        '', '.js', '/index.js',
        '.ts', '.tsx', '.cts', '.mts', '.jsx', '.cjs', '.mjs', '.d.ts',
        '/index.ts', '/index.tsx', '/index.jsx', '/index.d.ts',
    ];

    /**
     * The project-relative paths `$specifier`, written in `$testFile`, can name; none when it names no project path.
     *
     * @return list<string>
     */
    public static function of(string $testFile, string $specifier): array
    {
        if ((!str_starts_with($specifier, './') && !str_starts_with($specifier, '../')) || strpbrk($specifier, '?#') !== false) {
            return [];
        }
        $folder = dirname($testFile);
        $path = self::folded(($folder === '.' ? '' : $folder . '/') . $specifier);
        if ($path === null) {
            return [];
        }
        $extension = strrchr(basename($path), '.');
        $sources = $extension === false ? null : self::TYPESCRIPT_SOURCES[$extension] ?? null;
        if ($sources !== null) {
            $stem = substr($path, 0, -strlen((string) $extension));

            return [...array_map(static fn(string $source): string => $stem . $source, $sources), $path];
        }

        return array_map(static fn(string $suffix): string => $path . $suffix, self::SUFFIXES);
    }

    /** `$path` with its `.` and `..` segments folded away; null when it climbs out of the project or names nothing. */
    private static function folded(string $path): ?string
    {
        $kept = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment !== '..') {
                $kept[] = $segment;
            } elseif (array_pop($kept) === null) {
                return null;
            }
        }

        return $kept === [] ? null : implode('/', $kept);
    }
}
