<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Scan\ProjectPathResolver;
use Knossos\Store\ChunkedInQuery;
use PDO;

/**
 * A component's blast radius as rings: what depends on it directly (one
 * hop), through one other component (two hops), and further (three hops and
 * more), each ring with how many of its components a test reaches and the
 * test files that do.
 *
 * The dependents are found hop by hop over the impact edges, as
 * {@see ArchitectureQueryService::impactAnalysis()} finds them but read a
 * hop at a time and with far more of them, bounded in depth
 * ({@see self::DEPTH}), count and time; a test component is no member of a
 * ring but the tests' own. A component is reached by a
 * test when a test depends on it, directly or through others the search
 * found: worked out over the impact edges among everything found, so a
 * route the depth cut off does not count. Read-only, never scans.
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
    private const DEPENDENTS = 1500;

    /** How long the search may take. */
    private const TIMEOUT_MS = 2000;

    /** Components and tests named per ring. */
    public const NAMED = 12;

    /**
     * @param PDO $pdo an existing, migrated graph database
     * @param int $dependents the most dependents read; {@see self::DEPENDENTS} unless a test gives its own
     */
    public function __construct(private PDO $pdo, private int $dependents = self::DEPENDENTS) {}

    /**
     * The rings around `$component` in the project owning `$path`; `unscanned`,
     * or `not-found` when no one component goes by that name.
     *
     * @return array<string, mixed>
     */
    public function rings(string $path, string $component): array
    {
        $absolute = realpath($path) ?: $path;
        $envelope = ['path' => $absolute, 'project_id' => null, 'snapshot_id' => null, 'component' => null, 'rings' => [], 'truncated' => false];
        $project = (new ProjectPathResolver($this->pdo))->resolve($absolute);
        if ($project === null || !is_string($project['active_scan_id']) || $project['active_scan_id'] === '') {
            return ['status' => 'unscanned'] + $envelope;
        }
        $id = (string) $project['id'];
        $envelope = ['project_id' => $id, 'snapshot_id' => $project['active_scan_id']] + $envelope;
        $target = $this->component($id, $component);
        if ($target === null) {
            return ['status' => 'not-found'] + $envelope;
        }
        [$hop, $out, $truncated] = $this->dependents($id, (string) $target['id']);
        $ids = array_map('strval', array_keys($hop));
        $out = $this->throughFrameworkHooks($id, (string) $target['id'], $ids, $out);
        $roles = $this->roles($ids);
        $tests = array_flip(array_filter($ids, static fn(string $node): bool => ReportableComponent::isTest($roles[$node] ?? [])));
        $reached = self::reachedByTests(array_keys($tests), $out);
        // Everything some test reaches.
        $covered = $reached === [] ? [] : array_replace(...array_values($reached));
        // A walk the bound cut never read what stands past the cut, tests included: each member no test it read
        // reaches gets a search of its own, as far out as an uncut walk would have looked.
        $beyond = $truncated ? $this->testsBeyondCut($id, $hop, $tests, $covered) : [];
        $covered += array_fill_keys(array_keys($beyond), true);
        $labels = BoundaryLabels::load($this->pdo, $id)->forNodes([(string) $target['id'], ...$ids]);
        $places = $this->places([...$ids, ...array_keys(array_replace([], ...array_values($beyond)))]);
        $named = fn(string $node): array => ['name' => (string) $places[$node]['display_name'], 'canonical_name' => (string) $places[$node]['canonical_name'], 'kind' => (string) $places[$node]['kind'],
            'path' => $places[$node]['path'], 'line' => $places[$node]['line'], 'boundary' => $labels[$node] ?? null];
        $rings = [];
        foreach ([1, 2, 3] as $ring) {
            $in = static fn(string $node): bool => $ring < 3 ? $hop[$node] === $ring : $hop[$node] >= 3 && $hop[$node] <= self::DEPTH;
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
            uksort($files, static fn(string $a, string $b): int => [$files[$a], $a] <=> [$files[$b], $b]);
            $rings[] = [
                'hop' => $ring,
                'count' => count($members),
                'tested' => count($testedSet),
                'items' => array_map(static fn(string $node): array => $named($node) + ['tested' => isset($testedSet[$node])], array_slice($members, 0, self::NAMED)),
                'tests' => ['count' => count($files), 'items' => array_map(static fn(string $file, int $at): array => ['path' => $file, 'hop' => $at], array_keys(array_slice($files, 0, self::NAMED, true)), array_slice($files, 0, self::NAMED, true))],
            ];
        }

        return [
            'status' => 'ok',
            'component' => ['name' => (string) $target['display_name'], 'canonical_name' => (string) $target['canonical_name'], 'kind' => (string) $target['kind'], 'boundary' => $labels[(string) $target['id']] ?? null],
            'rings' => $rings,
            'truncated' => $truncated,
        ] + $envelope;
    }

    /**
     * The tests a cut-off walk left unread, for each ring member no test it
     * read reaches: one search from the member alone, stopped at the first
     * level a test stands at, out to the hop an uncut walk would have read
     * (`DEPTH + TEST_REACH`, counted from the target), the nearest members
     * first. Within a {@see self::TIMEOUT_MS} of its own; a member the time
     * does not reach stays untested, as the walk alone left it.
     *
     * @param array<string, int> $hop each dependent's hop
     * @param array<string, int> $tests the tests the walk read
     * @param array<string, true> $covered what those tests reach
     * @return array<string, array<string, int>> member id => test id => hops from the member
     */
    private function testsBeyondCut(string $projectId, array $hop, array $tests, array $covered): array
    {
        $deadline = hrtime(true) + self::TIMEOUT_MS * 1_000_000;
        $search = new TestReachSearch($this->pdo);
        $members = array_filter($hop, static fn(int $at, string $node): bool => $at <= self::DEPTH && !isset($tests[$node]) && !isset($covered[$node]), ARRAY_FILTER_USE_BOTH);
        asort($members);
        $beyond = [];
        foreach ($members as $member => $at) {
            if (hrtime(true) > $deadline) {
                break;
            }
            $found = $search->search($projectId, [(string) $member], self::DEPTH + self::TEST_REACH - $at, AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, 1, $deadline, first: true)['tests'];
            if ($found !== []) {
                $beyond[(string) $member] = $found;
            }
        }

        return $beyond;
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
     * Everything that depends on `$target`, hop by hop over the impact
     * edges, up to {@see self::DEPTH} hops, {@see self::DEPENDENTS} components
     * and {@see self::TIMEOUT_MS}: each one's hop, the edges read between
     * them (source to its targets), and whether the rings are cut short.
     *
     * The walk goes on {@see self::TEST_REACH} hops past the rings, within
     * the same bounds, so the tests standing there count: what it finds there
     * carries its hop past {@see self::DEPTH} and is no ring's member. Only
     * the rings' own bounds make the answer truncated; that more depends on
     * them than they hold does too.
     *
     * @return array{0: array<string, int>, 1: array<string, list<string>>, 2: bool}
     */
    private function dependents(string $projectId, string $target): array
    {
        $deadline = hrtime(true) + self::TIMEOUT_MS * 1_000_000;
        $kinds = AbstractArchitectureQueryService::IMPACT_EDGE_KINDS;
        $hop = [];
        $out = [];
        $frontier = [$target];
        $seen = [$target => true];
        $truncated = false;
        /** @var array<string, list<string>> $callersOf what was read calling each supertype method passed through */
        $callersOf = [];
        /** @var array<string, list<string>> $constructorsOf what was read constructing each class passed through */
        $constructorsOf = [];
        // The last ring's components, and whether what depends on them was read.
        $edge = [];
        $readPast = false;
        for ($distance = 1; $distance <= self::DEPTH + self::TEST_REACH && $frontier !== []; ++$distance) {
            $past = $distance > self::DEPTH;
            if ($distance === self::DEPTH + 1) {
                $edge = $frontier;
            }
            // A call through an interface or a parent goes to the supertype's method: read what calls that
            // too, and count each such edge as one into the method that fulfils it, never listing the supertype's.
            // A supertype method read at an earlier hop is not read again: its callers are recorded for this method too.
            $standsFor = [];
            // Nothing points at a property: its class's methods read it, so what uses the class counts as using it.
            $owners = array_map(static fn(string $class): array => [$class], PropertyOwners::of($this->pdo, $projectId, $frontier));
            foreach (SupertypeMethods::of($this->pdo, $projectId, $frontier) + $owners as $method => $supertypes) {
                foreach ($supertypes as $supertype) {
                    if (isset($callersOf[$supertype])) {
                        foreach ($callersOf[$supertype] as $caller) {
                            $out[$caller][] = $method;
                        }
                    } elseif (!isset($seen[$supertype])) {
                        // Not marked seen: one found later as a dependant in its own right still enters the rings.
                        $standsFor[$supertype][] = $method;
                    }
                }
            }
            // Constructing a class runs its constructor, which the graph records against the class: what constructs
            // it counts as an edge into the constructor, and only that edge, a type in a signature constructing nothing.
            $constructed = [];
            foreach (ConstructedClasses::of($this->pdo, $projectId, $frontier) as $constructor => $classes) {
                foreach ($classes as $class) {
                    if (isset($constructorsOf[$class])) {
                        foreach ($constructorsOf[$class] as $caller) {
                            $out[$caller][] = $constructor;
                        }
                    } else {
                        $constructed[$class][] = $constructor;
                    }
                }
            }
            $next = [];
            $reads = [
                ...array_map(static fn(array $chunk): array => [$chunk, $kinds, false], array_chunk([...$frontier, ...array_keys($standsFor)], 400)),
                ...array_map(static fn(array $chunk): array => [$chunk, [ConstructedClasses::EDGE_KIND], true], array_chunk(array_map('strval', array_keys($constructed)), 400)),
            ];
            foreach ($reads as [$chunk, $readKinds, $throughClass]) {
                if (hrtime(true) > $deadline) {
                    $truncated = $truncated || !$past;
                    break 2;
                }
                $statement = $this->pdo->prepare(
                    'SELECT DISTINCT source_id, target_id FROM edges WHERE project_id = ? AND target_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') AND kind IN (' . implode(',', array_fill(0, count($readKinds), '?')) . ')',
                );
                $statement->execute([$projectId, ...$chunk, ...$readKinds]);
                foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$source, $to]) {
                    [$source, $to] = [(string) $source, (string) $to];
                    if ($source === $target) {
                        continue;
                    }
                    if ($throughClass) {
                        $constructorsOf[$to][] = $source;
                    } elseif (isset($standsFor[$to])) {
                        $callersOf[$to][] = $source;
                    }
                    // Into the methods a passed-through supertype or class stands for, and into itself when it is a dependant too.
                    $standing = $throughClass ? $constructed[$to] : ($standsFor[$to] ?? null);
                    foreach ($standing !== null ? [...$standing, ...(!$throughClass && isset($hop[$to]) ? [$to] : [])] : [$to] as $into) {
                        if ($into !== $target) {
                            $out[$source][] = $into;
                        }
                    }
                    if (isset($seen[$source])) {
                        continue;
                    }
                    // Read or not, something depending on the last ring is more than the rings hold.
                    $more = $distance <= self::DEPTH + 1;
                    if (count($hop) >= $this->dependents) {
                        $truncated = $truncated || $more;
                        continue;
                    }
                    $seen[$source] = true;
                    $hop[$source] = $distance;
                    $next[] = $source;
                    $truncated = $truncated || $distance === self::DEPTH + 1;
                }
            }
            $readPast = $readPast || $past;
            $frontier = $next;
        }
        // A supertype method found as a dependant in its own right was walked as itself, not passed through:
        // what calls it may run each method found that fulfils it, so those callers count for that method too.
        $calling = [];
        foreach ($out as $source => $targets) {
            foreach ($targets as $into) {
                $calling[$into][] = (string) $source;
            }
        }
        foreach (SupertypeMethods::of($this->pdo, $projectId, array_map('strval', array_keys($hop))) as $method => $supertypes) {
            foreach ($supertypes as $supertype) {
                if (isset($hop[$supertype])) {
                    foreach ($calling[$supertype] ?? [] as $caller) {
                        $out[$caller][] = $method;
                    }
                }
            }
        }
        // Past the last ring there may be more the walk did not read: an edge left unread is a cut.
        $truncated = $truncated || (!$readPast && $edge !== [] && $this->hasDependents($projectId, $edge, $kinds));

        return [$hop, $out, $truncated];
    }

    /**
     * `$out` with an edge into each framework hook among the dependents from
     * whatever uses its class: the class itself or one of its members.
     *
     * The framework calls a hook (`booted`, `newFactory`) whenever its class is
     * used, so nothing names it, and no test reached it however much its
     * class is tested. Only what the walk read counts as a user, so a hook is
     * as tested as its class within the rings and no further.
     *
     * @param list<string> $ids the dependents
     * @param array<string, list<string>> $out
     * @return array<string, list<string>>
     */
    private function throughFrameworkHooks(string $projectId, string $target, array $ids, array $out): array
    {
        $hooks = FrameworkHookMethods::among($this->pdo, $projectId, $ids);
        if ($hooks === []) {
            return $out;
        }
        $walked = array_flip($ids);
        $kinds = AbstractArchitectureQueryService::IMPACT_EDGE_KINDS;
        foreach (array_unique($hooks) as $class) {
            $statement = $this->pdo->prepare(
                'SELECT DISTINCT source_id FROM edges WHERE project_id = ? AND kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ') '
                . "AND (target_id = ? OR target_id IN (SELECT target_id FROM edges WHERE project_id = ? AND kind = 'contains' AND source_id = ?))",
            );
            $statement->execute([$projectId, ...$kinds, $class, $projectId, $class]);
            $users = array_values(array_filter(
                array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)),
                static fn(string $user): bool => isset($walked[$user]) && $user !== $target,
            ));
            foreach (array_keys($hooks, $class, true) as $hook) {
                foreach ($users as $user) {
                    if ($user !== $hook) {
                        $out[$user][] = (string) $hook;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Whether anything depends on one of `$ids`.
     *
     * @param list<string> $ids
     * @param list<string> $kinds
     */
    private function hasDependents(string $projectId, array $ids, array $kinds): bool
    {
        $sql = 'SELECT 1 FROM edges WHERE project_id = ? AND target_id IN (%s) AND kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ') LIMIT 1';
        foreach (ChunkedInQuery::rows($this->pdo, $sql, $ids, [$projectId], $kinds, size: 400) as $_) {
            return true;
        }

        return false;
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
