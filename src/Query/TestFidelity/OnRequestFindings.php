<?php

declare(strict_types=1);

namespace Knossos\Query\TestFidelity;

use Closure;
use Knossos\Query\QueryBounds;
use Knossos\Scanner\PackagedLanguages;
use Knossos\TestFidelity\Facts\Assertion;
use Knossos\TestFidelity\OnRequest\OnRequestInput;
use PDO;

/**
 * Loads what the on-request rules read from a project's active graph: its
 * test files (a file holding a test double or a test case), what their
 * modules import, the target of each double, and the assertions of each test
 * case.
 *
 * Four queries, each capped by {@see QueryBounds} and each started only
 * before the deadline; a cap or the deadline cuts the whole load, so a rule
 * never judges a file with half its facts ({@see OnRequestInput::cutShort()}).
 *
 * An import counts as local when it sits within a function, method or class
 * of its file: the Python worker reports a function's own `from x import y`
 * as its module's import, and Momus reads module-level imports only
 * (`parser-python/src/index.ts:98-108`).
 */
final readonly class OnRequestFindings
{
    /** The files that hold a test double or a test case. */
    private const TEST_FILES = "SELECT DISTINCT file_id FROM nodes WHERE project_id = :project AND kind IN ('test_double', 'test_case') AND file_id IS NOT NULL";

    /** The kinds whose span makes an import inside it local. */
    private const SCOPES = "('function', 'method', 'class')";

    /**
     * @param ?Closure(): int $now the clock the deadline is read against, in nanoseconds; hrtime by default
     */
    public function __construct(private PDO $pdo, private ?Closure $now = null) {}

    /**
     * The project's test facts; empty and `partial` when a bound or `$deadline` (in the clock's nanoseconds) cut the load.
     */
    public function load(string $projectId, ?int $deadline = null): OnRequestInput
    {
        $steps = [
            fn(): ?array => $this->testFiles($projectId),
            fn(): ?array => $this->imports($projectId),
            fn(): ?array => $this->doubles($projectId),
            fn(): ?array => $this->assertions($projectId),
        ];
        $loaded = [];
        foreach ($steps as $step) {
            if ($deadline !== null && $this->now() > $deadline) {
                return OnRequestInput::cutShort();
            }
            $result = $step();
            if ($result === null) {
                return OnRequestInput::cutShort();
            }
            $loaded[] = $result;
        }

        return new OnRequestInput(...$loaded);
    }

    /**
     * Every test file and its language, in file order; null past the cap.
     *
     * @return array<string, string>|null
     */
    private function testFiles(string $projectId): ?array
    {
        $rows = $this->rows(
            'SELECT f.relative_path AS file, MIN(n.language) AS language FROM nodes n JOIN files f ON f.id = n.file_id '
            . 'WHERE n.project_id = :project AND n.file_id IN (' . self::TEST_FILES . ") AND n.kind IN ('test_double', 'test_case') "
            . 'GROUP BY f.relative_path ORDER BY f.relative_path',
            $projectId,
            QueryBounds::MAX_NODES,
        );
        if ($rows === null) {
            return null;
        }
        $files = [];
        foreach ($rows as $row) {
            $files[(string) $row['file']] = PackagedLanguages::languageOf((string) $row['language']);
        }

        return $files;
    }

    /**
     * What each test file's modules import; null past the cap.
     *
     * @return array<string, list<array{module: string, name: string, local: bool}>>|null
     */
    private function imports(string $projectId): ?array
    {
        $rows = $this->rows(
            'SELECT sf.relative_path AS file, t.kind, t.canonical_name, tf.relative_path AS target_file, '
            . 'EXISTS (SELECT 1 FROM nodes l WHERE l.file_id = s.file_id AND l.project_id = e.project_id AND l.kind IN ' . self::SCOPES
            . ' AND e.start_line IS NOT NULL AND l.start_line <= e.start_line AND l.end_line >= e.start_line) AS local '
            . 'FROM edges e JOIN nodes s ON s.id = e.source_id JOIN files sf ON sf.id = s.file_id JOIN nodes t ON t.id = e.target_id LEFT JOIN files tf ON tf.id = t.file_id '
            . "WHERE e.project_id = :project AND e.kind = 'imports' AND s.kind = 'module' AND s.file_id IN (" . self::TEST_FILES . ') '
            . 'ORDER BY sf.relative_path, t.canonical_name, e.start_line',
            $projectId,
            QueryBounds::MAX_EDGES,
        );
        if ($rows === null) {
            return null;
        }
        $imports = [];
        foreach ($rows as $row) {
            $imports[(string) $row['file']][] = [
                'module' => self::module((string) $row['kind'], (string) $row['canonical_name'], $row['target_file'] === null ? null : (string) $row['target_file']),
                'name' => (string) $row['canonical_name'],
                'local' => (bool) $row['local'],
            ];
        }

        return $imports;
    }

    /**
     * Each test file's doubles' targets; null past the cap.
     *
     * @return array<string, list<array{module: ?string, name: ?string}>>|null
     */
    private function doubles(string $projectId): ?array
    {
        $rows = $this->rows(
            'SELECT df.relative_path AS file, t.kind, t.canonical_name, t.display_name, tf.relative_path AS target_file '
            . 'FROM nodes d JOIN files df ON df.id = d.file_id '
            . "LEFT JOIN edges e ON e.project_id = d.project_id AND e.source_id = d.id AND e.kind = 'doubles' "
            . 'LEFT JOIN nodes t ON t.id = e.target_id LEFT JOIN files tf ON tf.id = t.file_id '
            . "WHERE d.project_id = :project AND d.kind = 'test_double' "
            . 'ORDER BY df.relative_path, d.canonical_name, t.canonical_name',
            $projectId,
            QueryBounds::MAX_NODES,
        );
        if ($rows === null) {
            return null;
        }
        $doubles = [];
        foreach ($rows as $row) {
            $target = $row['kind'] === null ? null : self::module((string) $row['kind'], (string) $row['canonical_name'], $row['target_file'] === null ? null : (string) $row['target_file']);
            $doubles[(string) $row['file']][] = ['module' => $target, 'name' => $target === null ? null : (string) $row['display_name']];
        }

        return $doubles;
    }

    /**
     * Every assertion of each test file's cases; a malformed one is left out. Null past the cap.
     *
     * @return array<string, list<Assertion>>|null
     */
    private function assertions(string $projectId): ?array
    {
        $rows = $this->rows(
            'SELECT f.relative_path AS file, n.attributes_json FROM nodes n JOIN files f ON f.id = n.file_id '
            . "WHERE n.project_id = :project AND n.kind = 'test_case' ORDER BY f.relative_path, n.canonical_name",
            $projectId,
            QueryBounds::MAX_NODES,
        );
        if ($rows === null) {
            return null;
        }
        $assertions = [];
        foreach ($rows as $row) {
            $attributes = json_decode((string) $row['attributes_json'], true);
            $raw = is_array($attributes) && is_array($attributes['assertions'] ?? null) ? $attributes['assertions'] : [];
            foreach ($raw as $candidate) {
                $assertion = Assertion::fromArray($candidate);
                if ($assertion !== null) {
                    $assertions[(string) $row['file']][] = $assertion;
                }
            }
        }

        return $assertions;
    }

    /**
     * A node as the module it names or is declared in: the project file that
     * declares it, or `external:` and its name for a package, a node outside
     * the project, or one no file holds.
     */
    private static function module(string $kind, string $canonicalName, ?string $file): string
    {
        return $kind === 'package' || str_starts_with($kind, 'external') || $file === null ? 'external:' . $canonicalName : $file;
    }

    /**
     * A query's rows, at most `$cap` of them; null when there are more.
     *
     * @return list<array<string, mixed>>|null
     */
    private function rows(string $sql, string $projectId, int $cap): ?array
    {
        $statement = $this->pdo->prepare($sql . ' LIMIT :cap');
        $statement->bindValue(':project', $projectId);
        $statement->bindValue(':cap', $cap + 1, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return count($rows) > $cap ? null : $rows;
    }

    /** The clock's reading, in nanoseconds. */
    private function now(): int
    {
        return $this->now === null ? hrtime(true) : ($this->now)();
    }
}
