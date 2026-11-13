<?php

declare(strict_types=1);

namespace Knossos\Scan\Conventions;

/**
 * Learns where the tests of a folder's files live, as a path with two holes.
 *
 * `<name>` stands for the source file's name without its extensions and `<dir>`
 * for its directory, so `tests/phpunit/Query/<name>Test.php` and
 * `<dir>/<name>.spec.ts` can be applied to a file that does not exist yet.
 * The pattern is learnt from source and test pairs the graph already shows.
 */
final class TestPathPattern
{
    private const NAME = '<name>';

    private const DIR = '<dir>';

    /**
     * The shape most pairs share, with how many pairs have it, or null without a usable pair.
     *
     * A pair is usable when the test's file name contains the source's name.
     * A tie goes to the pattern that sorts first, so the result is stable.
     *
     * @param list<array{source: string, test: string}> $pairs
     * @return array{pattern: string, files: int}|null
     */
    public static function learn(array $pairs): ?array
    {
        $counts = [];
        foreach ($pairs as $pair) {
            $pattern = self::shapeOf($pair['source'], $pair['test']);
            if ($pattern !== null) {
                $counts[$pattern] = ($counts[$pattern] ?? 0) + 1;
            }
        }
        if ($counts === []) {
            return null;
        }
        $patterns = array_keys($counts);
        usort($patterns, static fn(string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

        return ['pattern' => $patterns[0], 'files' => $counts[$patterns[0]]];
    }

    /** The test path a pattern gives for a source file. */
    public static function apply(string $pattern, string $sourcePath): string
    {
        $directory = self::directoryOf($sourcePath);
        $path = $directory === '' ? str_replace(self::DIR . '/', '', $pattern) : $pattern;

        return str_replace([self::DIR, self::NAME], [$directory, self::stemOf($sourcePath)], $path);
    }

    /** The pattern one pair stands for, or null when the test's name does not contain the source's. */
    private static function shapeOf(string $source, string $test): ?string
    {
        $stem = self::stemOf($source);
        $base = basename($test);
        $at = $stem === '' ? false : strpos($base, $stem);
        if ($at === false) {
            return null;
        }
        $name = substr($base, 0, $at) . self::NAME . substr($base, $at + strlen($stem));
        $testDirectory = self::directoryOf($test);
        if ($testDirectory === self::directoryOf($source)) {
            return $testDirectory === '' ? $name : self::DIR . '/' . $name;
        }

        return $testDirectory === '' ? $name : $testDirectory . '/' . $name;
    }

    /** A file's name up to its first dot, so `a.spec.ts` and `a.ts` both name `a`. */
    public static function stemOf(string $path): string
    {
        $base = basename($path);
        $dot = strpos($base, '.');

        return $dot === false || $dot === 0 ? $base : substr($base, 0, $dot);
    }

    /** The directory of a path, empty for a file at the root. */
    private static function directoryOf(string $path): string
    {
        $directory = dirname($path);

        return $directory === '.' ? '' : $directory;
    }
}
