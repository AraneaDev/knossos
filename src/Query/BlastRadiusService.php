<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Store\ChunkedInQuery;
use Knossos\Store\ProjectRepository;
use PDO;

/**
 * A component's blast radius as rings: what depends on it directly (one
 * hop), through one other component (two hops), and further (three hops and
 * more), each ring with how many of its components a test reaches and the
 * test files that do.
 *
 * The rings are {@see ImpactAnalysisQuery::impactAnalysis()}'s dependants,
 * the answer `impact_analysis` gives the agent, read with far more of them
 * ({@see self::DEPENDENTS}) and grouped by distance; a test component is no
 * member of a ring but the tests' own. A component is reached by a test when
 * a test depends on it, directly or through others the walk found: worked out
 * over every edge the walk read. A member no such test reaches gets a test
 * search of its own, {@see self::TEST_REACH} hops past the last ring, so a
 * component near the edge is as tested as one near the middle. Read-only,
 * never scans.
 */
final readonly class BlastRadiusService
{
    /** How many hops the rings go out. */
    public const DEPTH = 6;

    /**
     * How many hops past the last ring the search goes on, for tests alone: a
     * component near the edge is as tested as one near the middle when its
     * test stands a few hops further out.
     */
    public const TEST_REACH = 4;

    /** The most dependents read. */
    private const DEPENDENTS = ImpactAnalysisQuery::MAX_LIMIT;

    /** How long the search may take. */
    private const TIMEOUT_MS = 2000;

    /** Components and tests named per ring. */
    public const NAMED = 12;

    /**
     * @param PDO $pdo an existing, migrated graph database
     * @param int $dependents the most dependents read; {@see self::DEPENDENTS} unless a test gives its own
     * @param int $testSearchMs how long each of the tests' own searches may take; {@see self::TIMEOUT_MS} unless a
     *                          test gives its own
     */
    public function __construct(private PDO $pdo, private int $dependents = self::DEPENDENTS, private int $testSearchMs = self::TIMEOUT_MS) {}

    /**
     * The rings around `$component` in the project owning `$path`; `unscanned`,
     * or `not-found` when no one component goes by that name.
     *
     * `truncated` is the core's: a bound (the dependents read, the time, a
     * hub's inbound edges) cut the walk, each named in `truncation_reasons`.
     * `tests_partial` says the tests' own searches ran out of time, so a
     * member marked untested may have a test no search reached.
     *
     * @return array<string, mixed>
     */
    public function rings(string $path, string $component): array
    {
        $absolute = realpath($path) ?: $path;
        $envelope = ['path' => $absolute, 'project_id' => null, 'snapshot_id' => null, 'component' => null, 'rings' => [], 'truncated' => false, 'truncation_reasons' => [], 'tests_partial' => false];
        $project = (new ProjectRepository($this->pdo))->resolvePath($absolute);
        if ($project === null || !is_string($project['active_scan_id']) || $project['active_scan_id'] === '') {
            return ['status' => 'unscanned'] + $envelope;
        }
        $id = (string) $project['id'];
        $envelope = ['project_id' => $id, 'snapshot_id' => $project['active_scan_id']] + $envelope;
        $target = $this->component($id, $component);
        if ($target === null) {
            return ['status' => 'not-found'] + $envelope;
        }
        $impact = (new ImpactAnalysisQuery($this->pdo))->impactAnalysis($id, (string) $target['id'], self::DEPTH, $this->dependents, timeoutMs: self::TIMEOUT_MS, rings: true, links: true);
        $hop = [];
        $confidence = [];
        $viaMember = [];
        foreach ($impact->data['dependants'] as $row) {
            $node = (string) $row['node']['id'];
            $hop[$node] = (int) $row['distance'];
            $confidence[$node] = (string) $row['path_confidence'];
            if (isset($row['via_member'])) {
                $viaMember[$node] = (string) $row['via_member'];
            }
        }
        $out = [];
        foreach ($impact->data['links'] as [$source, $into]) {
            $out[$source][] = $into;
        }
        $ids = array_map('strval', array_keys($hop));
        $roles = $this->roles($ids);
        $tests = array_flip(array_filter($ids, static fn(string $node): bool => ReportableComponent::isTest($roles[$node] ?? [])));
        $reached = self::reachedByTests(array_keys($tests), $out);
        // Everything some test reaches.
        $covered = $reached === [] ? [] : array_replace(...array_values($reached));
        // The walk stops at the last ring: each member no test it read reaches gets a search of its own, as far
        // out as the tests' reach.
        [$beyond, $beyondCut] = $this->testsBeyond($id, $hop, $tests, $covered);
        $covered += array_fill_keys(array_keys($beyond), true);
        [$far, $farCut] = $this->testsPastTheWalk($id, $hop, $tests, $out);
        $labels = BoundaryLabels::load($this->pdo, $id)->forNodes([(string) $target['id'], ...$ids]);
        $places = $this->places([...$ids, ...array_keys(array_replace([], ...array_values($beyond))), ...array_keys($far)]);
        $named = fn(string $node): array => ['name' => (string) $places[$node]['display_name'], 'canonical_name' => (string) $places[$node]['canonical_name'], 'kind' => (string) $places[$node]['kind'],
            'path' => $places[$node]['path'], 'line' => $places[$node]['line'], 'boundary' => $labels[$node] ?? null,
            'confidence' => $confidence[$node], 'via_member' => $viaMember[$node] ?? null];
        $rings = [];
        foreach ($impact->data['rings'] as $core) {
            $ring = (int) $core['hop'];
            $in = static fn(string $node): bool => $ring < 3 ? $hop[$node] === $ring : $hop[$node] >= 3;
            $members = array_values(array_filter($ids, static fn(string $node): bool => $in($node) && !isset($tests[$node])));
            // Built once per ring, not once per test it is checked against.
            $memberSet = array_flip($members);
            $covering = array_values(array_filter(array_keys($tests), static fn(string $test): bool => array_intersect_key($reached[$test], $memberSet) !== []));
            $testedSet = array_intersect_key($covered, $memberSet);
            // The untested first: they are what a change there would break unseen.
            usort($members, static fn(string $a, string $b): int => [isset($testedSet[$a]) ? 1 : 0, $places[$a]['canonical_name']] <=> [isset($testedSet[$b]) ? 1 : 0, $places[$b]['canonical_name']]);
            // A test is its file: the nearest of its components says how far out it stands.
            $files = [];
            foreach ($covering as $test) {
                $file = $places[$test]['path'] ?? $places[$test]['canonical_name'];
                $files[$file] = min($files[$file] ?? PHP_INT_MAX, $hop[$test]);
            }
            foreach (array_intersect_key($beyond, $memberSet) as $member => $found) {
                foreach ($found as $test => $distance) {
                    $file = $places[$test]['path'] ?? $places[$test]['canonical_name'];
                    $files[$file] = min($files[$file] ?? PHP_INT_MAX, $hop[$member] + $distance);
                }
            }
            foreach ($far as $test => $past) {
                if (isset($past['rings'][$ring])) {
                    $file = $places[$test]['path'] ?? $places[$test]['canonical_name'];
                    $files[$file] = min($files[$file] ?? PHP_INT_MAX, $past['hop']);
                }
            }
            uksort($files, static fn(string $a, string $b): int => [$files[$a], $a] <=> [$files[$b], $b]);
            $rings[] = [
                'hop' => $ring,
                'count' => (int) $core['count'],
                'tested' => count($testedSet),
                'by_confidence' => $core['by_confidence'],
                'items' => array_map(static fn(string $node): array => $named($node) + ['tested' => isset($testedSet[$node])], array_slice($members, 0, self::NAMED)),
                'tests' => ['count' => count($files), 'items' => array_map(static fn(string $file, int $at): array => ['path' => $file, 'hop' => $at], array_keys(array_slice($files, 0, self::NAMED, true)), array_slice($files, 0, self::NAMED, true))],
            ];
        }
        $reason = $impact->data['bounds']['truncation_reason'] ?? null;

        return [
            'status' => 'ok',
            'component' => ['name' => (string) $target['display_name'], 'canonical_name' => (string) $target['canonical_name'], 'kind' => (string) $target['kind'], 'boundary' => $labels[(string) $target['id']] ?? null],
            'rings' => $rings,
            'truncated' => $impact->truncated,
            'truncation_reasons' => $reason === null ? [] : [(string) $reason],
            'tests_partial' => $beyondCut || $farCut,
        ] + $envelope;
    }

    /**
     * The tests standing past the walk's outermost level, within
     * {@see self::TEST_REACH} hops of the last ring, each with its hop from
     * the target and the rings it reaches into: those whose members the
     * component it reaches depends on, over the edges the walk read. One
     * search per set of rings, from every component on that level reaching
     * just those.
     *
     * @param array<string, int> $hop each dependent's hop
     * @param array<string, int> $tests the tests the walk read
     * @param array<string, list<string>> $out the edges the walk read, source to its targets
     * @return array{0: array<string, array{hop: int, rings: array<int, true>}>, 1: bool} test id => its hop and
     *         rings, and whether a search ran out of time
     */
    private function testsPastTheWalk(string $projectId, array $hop, array $tests, array $out): array
    {
        $members = array_diff_key($hop, $tests);
        if ($members === []) {
            return [[], false];
        }
        $outermost = max($members);
        // What reaches each ring's members, over the edges read backwards.
        $into = [];
        foreach ($out as $source => $targets) {
            foreach ($targets as $target) {
                $into[$target][] = (string) $source;
            }
        }
        $reaching = [];
        foreach ([1, 2, 3] as $ring) {
            $queue = array_map('strval', array_keys(array_filter($members, static fn(int $at): bool => min(3, $at) === $ring)));
            $seen = array_fill_keys($queue, true);
            while ($queue !== []) {
                foreach ($into[array_pop($queue)] ?? [] as $source) {
                    if (!isset($seen[$source])) {
                        $seen[$source] = true;
                        $queue[] = $source;
                    }
                }
            }
            $reaching[$ring] = $seen;
        }
        $groups = [];
        foreach (array_keys($members, $outermost, true) as $node) {
            $rings = array_keys(array_filter($reaching, static fn(array $seen): bool => isset($seen[(string) $node])));
            $groups[implode(',', $rings)][] = (string) $node;
        }
        $deadline = hrtime(true) + $this->testSearchMs * 1_000_000;
        $search = new TestReachSearch($this->pdo);
        $far = [];
        $cut = false;
        foreach ($groups as $rings => $level) {
            $searched = $search->search($projectId, $level, self::DEPTH + self::TEST_REACH - $outermost, AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, 1, $deadline);
            $cut = $cut || $searched['truncation_reasons'] !== [];
            foreach ($searched['tests'] as $test => $distance) {
                $test = (string) $test;
                if (isset($hop[$test])) {
                    continue;
                }
                $far[$test] = [
                    'hop' => min($far[$test]['hop'] ?? PHP_INT_MAX, $outermost + $distance),
                    'rings' => ($far[$test]['rings'] ?? []) + array_fill_keys(array_map('intval', explode(',', (string) $rings)), true),
                ];
            }
        }

        return [$far, $cut];
    }

    /**
     * The tests the walk left unread, for each ring member no test it read
     * reaches: past the last ring, or past a bound that cut it. One search
     * from the member alone, stopped at the first level a test stands at, out
     * to `DEPTH + TEST_REACH` hops counted from the target, the nearest
     * members first. Within a {@see self::TIMEOUT_MS} of its own; a member the
     * time does not reach stays untested, as the walk alone left it.
     *
     * @param array<string, int> $hop each dependent's hop
     * @param array<string, int> $tests the tests the walk read
     * @param array<string, true> $covered what those tests reach
     * @return array{0: array<string, array<string, int>>, 1: bool} member id => test id => hops from the member, and
     *         whether the time ran out before every member was searched
     */
    private function testsBeyond(string $projectId, array $hop, array $tests, array $covered): array
    {
        $deadline = hrtime(true) + $this->testSearchMs * 1_000_000;
        $search = new TestReachSearch($this->pdo);
        $members = array_filter($hop, static fn(int $at, string $node): bool => $at <= self::DEPTH && !isset($tests[$node]) && !isset($covered[$node]), ARRAY_FILTER_USE_BOTH);
        asort($members);
        $beyond = [];
        foreach ($members as $member => $at) {
            if (hrtime(true) > $deadline) {
                return [$beyond, true];
            }
            $searched = $search->search($projectId, [(string) $member], self::DEPTH + self::TEST_REACH - $at, AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, 1, $deadline, first: true);
            if ($searched['tests'] !== []) {
                $beyond[(string) $member] = $searched['tests'];
            } elseif ($searched['truncation_reasons'] !== []) {
                return [$beyond, true];
            }
        }

        return [$beyond, false];
    }

    /**
     * Each node's roles, by id.
     *
     * @param list<string> $ids
     * @return array<string, list<string>>
     */
    private function roles(array $ids): array
    {
        $roles = [];
        foreach (ChunkedInQuery::rows($this->pdo, 'SELECT node_id, role FROM classifications WHERE node_id IN (%s)', $ids, mode: PDO::FETCH_NUM) as [$node, $role]) {
            $roles[(string) $node][] = (string) $role;
        }

        return $roles;
    }

    /**
     * The one component `$query` names (its full name, else its short name
     * when only one goes by it), or null.
     *
     * @return array<string, mixed>|null
     */
    private function component(string $projectId, string $query): ?array
    {
        foreach (['canonical_name', 'display_name'] as $column) {
            $statement = $this->pdo->prepare(sprintf('SELECT id, kind, canonical_name, display_name FROM nodes WHERE project_id = :project AND %s = :query LIMIT 2', $column));
            $statement->execute(['project' => $projectId, 'query' => $query]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) === 1) {
                return $rows[0];
            }
            if ($rows !== []) {
                return null;
            }
        }

        return null;
    }

    /**
     * What each test reaches among the components found, by the edges read
     * between them, from the test outwards, by id.
     *
     * @param list<string> $tests
     * @param array<string, list<string>> $out each found component's targets among them
     * @return array<string, array<string, true>>
     */
    private static function reachedByTests(array $tests, array $out): array
    {
        $reached = [];
        foreach ($tests as $test) {
            $seen = [];
            $queue = $out[$test] ?? [];
            while ($queue !== []) {
                $node = array_pop($queue);
                if (isset($seen[$node])) {
                    continue;
                }
                $seen[$node] = true;
                array_push($queue, ...($out[$node] ?? []));
            }
            $reached[$test] = $seen;
        }

        return $reached;
    }

    /**
     * Each node's names, kind and place (file and first line), by id.
     *
     * @param list<string> $ids
     * @return array<string, array{display_name: string, canonical_name: string, kind: string, path: string|null, line: int|null}>
     */
    private function places(array $ids): array
    {
        $places = [];
        $sql = 'SELECT n.id, n.display_name, n.canonical_name, n.kind, f.relative_path, n.start_line FROM nodes n LEFT JOIN files f ON f.id = n.file_id WHERE n.id IN (%s)';
        foreach (ChunkedInQuery::rows($this->pdo, $sql, $ids, mode: PDO::FETCH_NUM) as [$node, $display, $canonical, $kind, $file, $line]) {
            $places[(string) $node] = ['display_name' => (string) $display, 'canonical_name' => (string) $canonical, 'kind' => (string) $kind, 'path' => $file === null ? null : (string) $file, 'line' => $line === null ? null : (int) $line];
        }

        return $places;
    }
}
