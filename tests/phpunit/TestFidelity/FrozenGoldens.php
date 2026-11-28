<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

/**
 * The Momus and Nemesis output the acceptance suites compare against, frozen
 * in `tests/Fixtures/test-fidelity/goldens/{momus,nemesis}/<suite>.json`.
 * Phase 7 archives both tools, so these files are then the only record of
 * what they reported.
 *
 * Each file holds the tool, its commit, and one run per fixture: the trees
 * copied into the scratch project (fixture path => path in the copy), a
 * sha256 of those trees, the command and the tool's JSON output, with the
 * copy's path and Momus's `durationMs` removed.
 */
trait FrozenGoldens
{
    /** The Momus-MCP commit every Momus golden was run at. */
    private const MOMUS_COMMIT = '9fe7283ae4f0258ea1ba56fbed3a1c40a87e850a';

    /** The Nemesis-MCP commit every Nemesis golden was run at (`dist/` built from it). */
    private const NEMESIS_COMMIT = 'bf9be2704abf37502f07f12a9e629cc1ea55c3fe';

    /**
     * One golden file, decoded.
     *
     * @return array{tool: string, commit: string, suite: string, runs: list<array<string, mixed>>}
     */
    private static function golden(string $tool, string $suite): array
    {
        $path = dirname(__DIR__, 3) . '/tests/Fixtures/test-fidelity/goldens/' . $tool . '/' . $suite . '.json';
        $golden = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        \assert(is_array($golden));

        /** @var array{tool: string, commit: string, suite: string, runs: list<array<string, mixed>>} $golden */
        return $golden;
    }

    /**
     * What each run of a golden reports, per fixture, as
     * `RULE/severity@file:line` (Momus) or `TYPE/confidence@file:line`
     * (Nemesis), sorted bytewise; a finding given twice is listed twice.
     *
     * @return array<string, list<string>>
     */
    private static function goldenFindings(string $tool, string $suite): array
    {
        $findings = [];
        foreach (self::golden($tool, $suite)['runs'] as $run) {
            /** @var array<string, mixed> $output */
            $output = $run['output'];
            /** @var list<array<string, mixed>> $items */
            $items = $tool === 'momus' ? $output['result']['issues'] : $output['violations'];
            $list = array_map(static fn(array $item): string => $tool === 'momus'
                ? sprintf('%s/%s@%s:%d', $item['rule'], $item['severity'], $item['file'], $item['line'])
                : sprintf('%s/%s@%s:%d', $item['type'], $item['confidence'], $item['file'], $item['line']), $items);
            sort($list, SORT_STRING);
            $findings[(string) $run['fixture']] = $list;
        }

        return $findings;
    }

    /**
     * The sha256 of fixture trees as a golden records it: one line per file,
     * `path NUL sha256(contents) LF` with the path as in the copy, the lines
     * in byte order.
     *
     * @param array<string, string> $trees fixture path under `tests/Fixtures/test-fidelity` => path in the copy ('' for its root)
     */
    private static function fixtureHash(array $trees): string
    {
        $lines = [];
        foreach ($trees as $from => $to) {
            $base = dirname(__DIR__, 3) . '/tests/Fixtures/test-fidelity/' . $from;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
                \assert($file instanceof \SplFileInfo);
                $relative = substr($file->getPathname(), strlen($base) + 1);
                $lines[] = ($to === '' ? $relative : $to . '/' . $relative) . "\0" . hash_file('sha256', $file->getPathname()) . "\n";
            }
        }
        sort($lines, SORT_STRING);

        return hash('sha256', implode('', $lines));
    }
}
