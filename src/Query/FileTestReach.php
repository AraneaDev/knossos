<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use PDO;

/**
 * How many test files reach each of a few changed files, one file at a time:
 * what lets the architecture pane mark a changed file no test reaches.
 *
 * A test search over a set of files says which tests reach the set, not
 * which file each reaches; so each file is searched on its own
 * ({@see ChangeImpactQueryService::testImpact()}), the most depended on
 * first, within a cap on files and a deadline across them all. A file is
 * answered with a count only when that is known: a file the graph places no
 * component in (a document, a configuration file) has no tests to count, and
 * a search cut short that found none says nothing either way, so neither is
 * listed. Neither is a file past the cap or the deadline.
 *
 * Read-only, never scans.
 */
final readonly class FileTestReach
{
    /** The most files searched: past them, a file is not marked either way. */
    public const MAX_FILES = 60;

    /** The time all the searches together may take. */
    public const DEADLINE_MS = 3000;

    /** The time one file's search may take. */
    private const PER_FILE_MS = 500;

    /** The time all of {@see self::testsOf()}'s searches together may take. */
    public const TESTS_DEADLINE_MS = 5000;

    /** The time one of {@see self::testsOf()}'s searches may take. */
    private const CHUNK_MS = 1000;

    /**
     * How many hops a test may stand from a changed file and still count:
     * past the four an impact query looks by default, since code a few
     * layers under a service is routinely tested only through it, and a
     * file marked untested that a test does reach is the worse mistake.
     */
    public const DEPTH = 6;

    /** Tests counted per file: a count at it is a floor, which is all a mark needs. */
    public const COUNTED = 20;

    /**
     * @param PDO $pdo an existing, migrated graph database
     * @param Closure|null $clock milliseconds since some start, so the deadline is testable
     * @param QueryServices|null $services the query services to read through; null wires them against `$pdo` per call
     */
    public function __construct(private PDO $pdo, private ?Closure $clock = null, private ?QueryServices $services = null) {}

    /**
     * The tests reaching each of `$files` (project-relative), at most
     * {@see self::COUNTED}, for the files a count is known of.
     *
     * @param list<string> $files the files to search, the most telling first
     * @return array<string, int>
     */
    public function reach(string $projectId, array $files): array
    {
        $changes = ($this->services ?? QueryServices::wire($this->pdo))->changeQueries;
        $now = $this->clock ?? static fn(): float => microtime(true) * 1000;
        $until = $now() + self::DEADLINE_MS;
        $counts = [];
        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            $left = (int) ($until - $now());
            if ($left <= 0) {
                break;
            }
            $found = $changes->testImpact($projectId, [$file], maxDepth: self::DEPTH, limit: self::COUNTED, timeoutMs: max(1, min(self::PER_FILE_MS, $left)));
            // A test that only builds the application still runs the file's code.
            $tests = count($found->data['test_files'] ?? []) + count($found->data['test_files_via_wiring'] ?? []);
            $unplaced = in_array($file, $found->data['unresolved_files'] ?? [], true);
            if ($unplaced || ($tests === 0 && $found->truncated)) {
                continue;
            }
            $counts[$file] = $tests;
        }

        return $counts;
    }

    /**
     * The tests that reach any of `$files`, nearest first, at most `$limit`:
     * asked {@see ChangeImpactQueryService::MAX_FILES} files at a time (the
     * most one search takes), each test kept at its nearest distance. A set
     * of changed files larger than one search takes was refused outright
     * before, so a big turn reported no tests at all.
     *
     * All the searches together keep to {@see self::TESTS_DEADLINE_MS}: a
     * checkout or a pull can change thousands of files, and a second per
     * fifty of them outran the turn brief's own time limit, which lost the
     * whole brief. Past the deadline the rest is not searched and the list
     * is `truncated`, as it is when a search was cut short or more tests
     * than `$limit` reach the files.
     *
     * @param list<string> $files
     * @param Closure|null $clock milliseconds since some start, so the deadline is testable
     * @return array{tests: list<array{path: string, distance: int}>, truncated: bool}
     */
    public static function testsOf(ChangeImpactQueryService $changes, string $projectId, array $files, int $limit, ?Closure $clock = null): array
    {
        $now = $clock ?? static fn(): float => microtime(true) * 1000;
        $until = $now() + self::TESTS_DEADLINE_MS;
        // Tests that reach a file by a call, then those that only build the application.
        $nearest = ['direct' => [], 'wired' => []];
        $truncated = false;
        foreach (array_chunk($files, ChangeImpactQueryService::MAX_FILES) as $chunk) {
            $left = (int) ($until - $now());
            if ($left <= 0) {
                $truncated = true;
                break;
            }
            // test_impact accepts a limit of 1 to 100; this list is cut to
            // $limit below either way, so a smaller or larger one is clamped.
            $found = $changes->testImpact($projectId, $chunk, maxDepth: self::DEPTH, limit: max(1, min(100, $limit)), timeoutMs: max(1, min(self::CHUNK_MS, $left)));
            $truncated = $truncated || $found->truncated;
            foreach (['direct' => 'test_files', 'wired' => 'test_files_via_wiring'] as $group => $key) {
                foreach ($found->data[$key] ?? [] as $test) {
                    $path = (string) $test['path'];
                    $nearest[$group][$path] = min($nearest[$group][$path] ?? PHP_INT_MAX, (int) $test['distance']);
                }
            }
        }
        $tests = [];
        foreach ([$nearest['direct'], array_diff_key($nearest['wired'], $nearest['direct'])] as $group) {
            $listed = [];
            foreach ($group as $path => $distance) {
                $listed[] = ['path' => (string) $path, 'distance' => $distance];
            }
            usort($listed, static fn(array $a, array $b): int => [$a['distance'], $a['path']] <=> [$b['distance'], $b['path']]);
            array_push($tests, ...$listed);
        }

        return ['tests' => array_slice($tests, 0, $limit), 'truncated' => $truncated || count($tests) > $limit];
    }
}
