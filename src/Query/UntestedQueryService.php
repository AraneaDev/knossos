<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use Knossos\Classification\ToolConfigModuleRule;
use Knossos\Result\ResultEnvelope;
use Knossos\Store\ChunkedInQuery;
use PDO;

/**
 * The files and components no test reaches through the graph: a quick list
 * of where a change would go out with nothing checking it.
 *
 * One forward walk from every test component along the impact edge kinds, as
 * far as the edges go, marks what the tests reach; what is left unmarked is
 * untested. The walk mirrors the two stand-ins {@see TestReachSearch} reads
 * in the other direction: a method whose supertype method a test reaches may
 * be the one that runs, and constructing a class runs its constructor. A
 * {@see CompositionRoots composition root} is reached but never walked out of:
 * a test that builds the application would otherwise reach every service
 * without using one.
 *
 * Static, like every other answer here: a test that reaches code through a
 * framework, a callback or an untyped receiver is not seen, so "untested"
 * means "no test reaches it in the graph". A walk cut short by its bounds
 * says so, since everything past the cut would read as untested.
 */
final readonly class UntestedQueryService extends AbstractArchitectureQueryService
{
    /** The component kinds listed: the ones a test calls or builds. */
    private const LISTED_KINDS = ['class', 'interface', 'trait', 'enum', 'function', 'method'];

    /** The most nodes the walk visits by default. */
    public const MAX_NODES = 200_000;

    /** Frontier ids per query, well under SQLite's variable limit with the edge kinds added. */
    private const CHUNK = 500;

    /** Rounds of supertype and constructor stand-ins the walk resumes after. */
    private const MAX_ROUNDS = 8;

    /**
     * Files (or components) no test reaches, largest first (or by name).
     *
     * @param 'files'|'components' $granularity
     */
    public function untested(
        string $projectId,
        string $granularity = 'files',
        ?string $pathPrefix = null,
        int $limit = 50,
        int $offset = 0,
        int $maxNodes = self::MAX_NODES,
        int $timeoutMs = 5000,
    ): ResultEnvelope {
        self::assertLimit($limit);
        if (!in_array($granularity, ['files', 'components'], true)) {
            throw new InvalidArgumentException('granularity must be files or components.');
        }
        if ($offset < 0 || $offset > 100_000) {
            throw new InvalidArgumentException('Offset must be between 0 and 100000.');
        }
        if ($maxNodes < 1 || $maxNodes > self::MAX_NODES) {
            throw new InvalidArgumentException(sprintf('max_nodes must be between 1 and %d.', self::MAX_NODES));
        }
        if ($timeoutMs < 1 || $timeoutMs > 60_000) {
            throw new InvalidArgumentException('timeout_ms must be between 1 and 60000.');
        }
        $project = $this->project($projectId);
        $deadline = $this->now() + $timeoutMs * 1_000_000;
        $walk = $this->reached($projectId, $maxNodes, $deadline);
        // Written the way the scan stores paths: `./src` is `src`.
        $prefix = $pathPrefix === null ? '' : (string) preg_replace('#^(?:\./)+#', '', $pathPrefix);
        $prefix = $prefix === '' ? null : $prefix;
        [$entries, $scope] = $granularity === 'files'
            ? $this->untestedFiles($projectId, $walk['reached'], $walk['left_out'], $prefix)
            : $this->untestedComponents($projectId, $walk['reached'], $walk['left_out'], $prefix);
        $total = count($entries);
        $page = array_slice($entries, $offset, $limit);
        $reasons = $walk['truncation_reasons'];
        $noun = $granularity === 'files' ? 'source files' : 'components';
        $summary = sprintf('%d of %d %s%s have no test reaching them in the graph.', $total, $scope, $noun, $prefix === null ? '' : ' under ' . $prefix);
        if ($total > count($page)) {
            $summary .= sprintf(' This page lists %d of them.', count($page));
        }
        if ($reasons !== []) {
            $summary .= sprintf(' The walk from the tests was truncated (%s), so some of these may be reached past that bound.', implode(', ', $reasons));
        }

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            $summary,
            [
                $granularity => $page,
                'total' => $total,
                'scope' => $scope,
                'returned' => count($page),
                'limit' => $limit,
                'offset' => $offset,
                'bounds' => ['visited' => count($walk['reached']), 'max_nodes' => $maxNodes, 'truncation_reasons' => $reasons],
            ],
            [],
            [],
            $reasons !== [] || $offset + count($page) < $total,
        );
    }

    /**
     * Every node a test reaches, the test nodes themselves among them.
     *
     * @return array{reached: array<string, true>, tests: array<string, true>, left_out: array<string, true>, truncation_reasons: list<string>}
     *         left_out: the test code and tool configs, which are never listed
     */
    private function reached(string $projectId, int $maxNodes, int $deadline): array
    {
        $statement = $this->pdo->prepare('SELECT DISTINCT node_id, role FROM classifications WHERE project_id = :project AND role IN (:test, :config)');
        $statement->execute(['project' => $projectId, 'test' => ReportableComponent::TEST_ROLE, 'config' => ToolConfigModuleRule::ROLE]);
        $tests = [];
        $configs = [];
        foreach ($statement->fetchAll() as $row) {
            if ($row['role'] === ReportableComponent::TEST_ROLE) {
                $tests[(string) $row['node_id']] = true;
            } else {
                $configs[(string) $row['node_id']] = true;
            }
        }
        $reached = [];
        $frontier = [];
        $reason = null;
        foreach (array_keys($tests) as $id) {
            if (count($reached) >= $maxNodes) {
                $reason = 'visit_limit';
                break;
            }
            $reached[(string) $id] = true;
            $frontier[] = (string) $id;
        }
        for ($round = 0; $reason === null && $frontier !== [] && $round < self::MAX_ROUNDS; ++$round) {
            $reason = $this->walk($projectId, $frontier, $reached, $maxNodes, $deadline);
            $frontier = $reason === null ? $this->standIns($projectId, $reached) : [];
            foreach ($frontier as $id) {
                if (count($reached) >= $maxNodes) {
                    $reason = 'visit_limit';
                    break;
                }
                $reached[$id] = true;
            }
        }

        // A tool's config is left out with the tests: its tool runs it, no test would.
        return ['reached' => $reached, 'tests' => $tests, 'left_out' => $tests + $configs, 'truncation_reasons' => $reason === null ? [] : [$reason]];
    }

    /**
     * Walk forward from `$frontier` along the impact edge kinds until nothing
     * new is met, marking what is met in `$reached`; the bound that cut the
     * walk, or null.
     *
     * @param list<string> $frontier
     * @param array<string, true> $reached
     */
    private function walk(string $projectId, array $frontier, array &$reached, int $maxNodes, int $deadline): ?string
    {
        $kinds = self::IMPACT_EDGE_KINDS;
        $placeholders = implode(', ', array_fill(0, count($kinds), '?'));
        $sql = "SELECT DISTINCT target_id FROM edges WHERE project_id = ? AND kind IN ({$placeholders}) AND source_id IN (%s)";
        while ($frontier !== []) {
            if ($this->now() > $deadline) {
                return 'time_limit';
            }
            sort($frontier, SORT_STRING);
            // Wiring is reached, never walked out of.
            $roots = CompositionRoots::of($this->pdo, $projectId, $frontier);
            $walked = array_values(array_filter($frontier, static fn(string $id): bool => !isset($roots[$id])));
            $next = [];
            foreach (array_chunk($walked, self::CHUNK) as $chunk) {
                foreach (ChunkedInQuery::rows($this->pdo, $sql, $chunk, [$projectId, ...$kinds], [], PDO::FETCH_COLUMN) as $target) {
                    $target = (string) $target;
                    if (isset($reached[$target])) {
                        continue;
                    }
                    if (count($reached) >= $maxNodes) {
                        return 'visit_limit';
                    }
                    $reached[$target] = true;
                    $next[] = $target;
                }
            }
            $frontier = $next;
        }

        return null;
    }

    /**
     * The methods not reached yet that a reached node stands in for: an
     * override or implementation of a reached supertype method, and a
     * constructor of a reached class.
     *
     * @param array<string, true> $reached
     * @return list<string>
     */
    private function standIns(string $projectId, array $reached): array
    {
        $statement = $this->pdo->prepare("SELECT id FROM nodes WHERE project_id = :project AND kind = 'method'");
        $statement->execute(['project' => $projectId]);
        $methods = array_values(array_filter(
            array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)),
            static fn(string $id): bool => !isset($reached[$id]),
        ));
        $found = [];
        foreach ([SupertypeMethods::of($this->pdo, $projectId, $methods), ConstructedClasses::of($this->pdo, $projectId, $methods)] as $standIns) {
            foreach ($standIns as $method => $stand) {
                foreach ($stand as $id) {
                    if (isset($reached[$id])) {
                        $found[(string) $method] = true;
                        break;
                    }
                }
            }
        }
        $found = array_keys($found);
        sort($found, SORT_STRING);

        return array_map('strval', $found);
    }

    /**
     * The source files none of whose components a test reaches, largest
     * first, and how many source files were in scope. A file holding test
     * code or a tool's config is not a source file here, and neither is a
     * TypeScript declaration file, which holds no code to run.
     *
     * @param array<string, true> $reached
     * @param array<string, true> $leftOut
     * @return array{0: list<array{path: string, language: string, line_count: int, components: int}>, 1: int}
     */
    private function untestedFiles(string $projectId, array $reached, array $leftOut, ?string $prefix): array
    {
        $statement = $this->pdo->prepare(
            'SELECT f.relative_path, f.language, f.line_count, n.id FROM files f LEFT JOIN nodes n ON n.file_id = f.id AND n.project_id = f.project_id WHERE f.project_id = :project',
        );
        $statement->execute(['project' => $projectId]);
        $files = [];
        foreach ($statement->fetchAll() as $row) {
            $path = (string) $row['relative_path'];
            if (($prefix !== null && !str_starts_with($path, $prefix)) || str_ends_with($path, '.d.ts')) {
                continue;
            }
            $file = $files[$path] ?? ['path' => $path, 'language' => (string) $row['language'], 'line_count' => (int) $row['line_count'], 'components' => 0, 'reached' => false, 'test' => false];
            if ($row['id'] !== null) {
                $id = (string) $row['id'];
                ++$file['components'];
                $file['reached'] = $file['reached'] || isset($reached[$id]);
                $file['test'] = $file['test'] || isset($leftOut[$id]);
            }
            $files[$path] = $file;
        }
        $source = array_filter($files, static fn(array $file): bool => !$file['test']);
        $untested = array_values(array_map(
            static fn(array $file): array => ['path' => $file['path'], 'language' => $file['language'], 'line_count' => $file['line_count'], 'components' => $file['components']],
            array_filter($source, static fn(array $file): bool => !$file['reached']),
        ));
        usort($untested, static fn(array $a, array $b): int => [$b['line_count'], $a['path']] <=> [$a['line_count'], $b['path']]);

        return [$untested, count($source)];
    }

    /**
     * The components no test reaches, by file then line, and how many were
     * in scope: declared in a scanned file, of a listed kind, neither test
     * code nor a tool's config. Each says whether it has no inbound edge at
     * all; whether that makes it dead is the dead-code report's call, which
     * knows the entry points and the framework conventions.
     *
     * @param array<string, true> $reached
     * @param array<string, true> $leftOut
     * @return array{0: list<array{canonical_name: string, kind: string, path: string, line: ?int, no_inbound_edge: bool}>, 1: int}
     */
    private function untestedComponents(string $projectId, array $reached, array $leftOut, ?string $prefix): array
    {
        $kinds = "'" . implode("', '", self::LISTED_KINDS) . "'";
        $statement = $this->pdo->prepare(
            "SELECT n.id, n.canonical_name, n.kind, n.start_line, f.relative_path, EXISTS (SELECT 1 FROM edges e WHERE e.project_id = n.project_id AND e.target_id = n.id AND e.kind != 'contains') AS referenced "
            . "FROM nodes n JOIN files f ON f.id = n.file_id WHERE n.project_id = :project AND n.kind IN ({$kinds}) AND n.origin = 'ast' ORDER BY f.relative_path, n.start_line, n.canonical_name",
        );
        $statement->execute(['project' => $projectId]);
        $rows = $statement->fetchAll();
        // A type is reached when a test reaches one of its members: a static
        // call reaches the method and constructs nothing.
        $members = $this->pdo->prepare('SELECT id, parent_id FROM nodes WHERE project_id = :project AND parent_id IS NOT NULL');
        $members->execute(['project' => $projectId]);
        foreach ($members->fetchAll(PDO::FETCH_KEY_PAIR) as $member => $parent) {
            if (isset($reached[(string) $member])) {
                $reached[(string) $parent] = true;
            }
        }
        $scope = 0;
        $untested = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $path = (string) $row['relative_path'];
            if (isset($leftOut[$id]) || ($prefix !== null && !str_starts_with($path, $prefix))) {
                continue;
            }
            ++$scope;
            if (isset($reached[$id])) {
                continue;
            }
            $untested[] = [
                'canonical_name' => (string) $row['canonical_name'],
                'kind' => (string) $row['kind'],
                'path' => $path,
                'line' => $row['start_line'] === null ? null : (int) $row['start_line'],
                'no_inbound_edge' => (int) $row['referenced'] === 0,
            ];
        }

        return [$untested, $scope];
    }
}
