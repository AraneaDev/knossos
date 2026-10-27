<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use Knossos\Scanner\Protocol\ConfidenceRank;
use Knossos\Store\ChunkedInQuery;
use PDO;
use PDOStatement;

/**
 * Which test components statically reach a set of changed components.
 *
 * A reverse breadth-first search that visits every dependant, production and
 * test alike, because a test usually reaches changed code through production
 * code, and keeps only the components classified as test code. Production
 * dependants cost a visit but never take a result slot. test_impact used to
 * filter test roles out of an impact analysis capped at 100 dependants, so a
 * hub's production callers filled that window before any test was met and the
 * answer read "0 test files".
 *
 * The search is level-synchronous: each level's frontier is sorted by id and
 * read in chunks with one ordered query per chunk, so a node's distance is its
 * shortest hop count, and which nodes a bound cuts is the same on every run.
 * A `launches` hop costs no depth: a test that drives a script through another
 * process is as near the change as the script's own code, so the launching side
 * is read on the level of the script it launches.
 * Its bounds are its own: visited nodes, edge rows examined and one deadline,
 * each named in `truncation_reasons` when it cut the walk. Stopping at the
 * depth horizon with dependants still unread is reported apart, as
 * `depth_horizon`, so a caller can say that tests further out were not looked for.
 *
 * A component reached only through a {@see CompositionRoots composition root}
 * is marked as reached through wiring, and so is everything reached only from
 * it: a test that builds the application reaches every service that way
 * without using one. Reached by any other edge on the same level, it is not.
 * A start component is never taken as a composition root: a change to the
 * wiring itself is what its tests exercise.
 */
final readonly class TestReachSearch extends AbstractArchitectureQueryService
{
    /** The largest node bound any query accepts (`max_nodes` in architecture_health). */
    public const MAX_VISITED = 50_000;

    /** The largest `max_edges` any query accepts. */
    public const MAX_EDGES = QueryBounds::MAX_EDGES;

    /** Frontier ids per query, well under SQLite's 999-variable floor with the edge kinds added. */
    private const CHUNK = 500;

    /** The edge kind whose source is read on the level of its target. */
    private const LAUNCH = 'launches';

    /** Most dependant rows the depth-horizon check reads before taking the horizon as reached. */
    private const HORIZON_ROWS = 10_000;

    /** Rows read between two clock reads, after the first row of every chunk. */
    private const CLOCK_EVERY = 64;

    public function __construct(PDO $pdo, ?Closure $clock = null, private int $maxVisited = self::MAX_VISITED, private int $maxEdges = self::MAX_EDGES)
    {
        parent::__construct($pdo, $clock);
    }

    /**
     * Walk dependants outward from the start components and collect the test-classified ones.
     *
     * @param list<string> $startIds direct components, distance 0
     * @param list<string> $edgeKinds validated impact edge kinds
     * @param bool $first stop after the level the first test is met at: enough to say whether any test reaches
     * @return array{tests: array<string, int>, wired: array<string, true>, visited: int, edges_examined: int, truncation_reasons: list<string>, depth_horizon: bool}
     *         tests: test-role node id => shortest distance; wired: the tests among them reached only through wiring;
     *         depth_horizon: the walk stopped at max_depth with a dependant of its last level still unread
     */
    public function search(string $projectId, array $startIds, int $maxDepth, array $edgeKinds, int $minimumRank, int $deadline, bool $first = false): array
    {
        $seen = [];
        $tests = [];
        $frontier = [];
        $reason = null;
        // Visited node id => whether it was reached only through wiring.
        $wired = [];
        foreach ($startIds as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            if (count($seen) >= $this->maxVisited) {
                $reason = 'visit_limit';
                break;
            }
            $seen[$id] = true;
            $wired[$id] = false;
            $frontier[] = $id;
        }
        $starts = array_fill_keys($frontier, true);
        $this->markTests($projectId, $frontier, 0, $tests);
        // `passed`: supertype methods and constructed classes already read through, apart from `seen`,
        // so one found later as a dependant in its own right still counts.
        $walk = ['seen' => $seen, 'passed' => [], 'wired' => $wired, 'tests' => $tests, 'examined' => 0];
        for ($level = 0; $reason === null && $level < $maxDepth && $frontier !== [] && !($first && $walk['tests'] !== []); ++$level) {
            [$frontier, $reason] = $this->readLevel($projectId, $frontier, $level, $starts, $edgeKinds, $minimumRank, $deadline, $walk);
        }
        // The last level's launches spend no depth either: what launches one of its nodes is at the same distance.
        if ($reason === null && $frontier !== [] && $level >= $maxDepth && in_array(self::LAUNCH, $edgeKinds, true)) {
            $before = $walk['seen'];
            [, $reason] = $this->readLevel($projectId, $frontier, $maxDepth, $starts, [self::LAUNCH], $minimumRank, $deadline, $walk, launchesOnly: true);
            $frontier = [...$frontier, ...array_map('strval', array_keys(array_diff_key($walk['seen'], $before)))];
        }
        // The walk stopped at max_depth with dependants of its last level still unread: tests may lie beyond it.
        $horizon = false;
        if ($reason === null && $frontier !== [] && $level >= $maxDepth) {
            [$horizon, $reason] = $this->hasUnreadDependant($projectId, $frontier, $edgeKinds, $minimumRank, $walk['seen'], $deadline, $walk['examined']);
        }

        $wiredTests = [];
        foreach (array_keys($walk['tests']) as $id) {
            if ($walk['wired'][(string) $id] ?? false) {
                $wiredTests[(string) $id] = true;
            }
        }

        return ['tests' => $walk['tests'], 'wired' => $wiredTests, 'visited' => count($walk['seen']), 'edges_examined' => $walk['examined'], 'truncation_reasons' => $reason === null ? [] : [$reason], 'depth_horizon' => $horizon];
    }

    /**
     * Read the dependants of one level's frontier, and the next level it makes, with the bound that cut it if one did.
     *
     * A process launch hands the work to what it launches: the launching side is as far from the
     * change as the launched one, so it is read on this level, as often as launches lead on.
     *
     * @param list<string> $frontier the nodes at distance `$level`
     * @param array<string, true> $starts
     * @param list<string> $edgeKinds
     * @param array{seen: array<string, true>, passed: array<string, true>, wired: array<string, bool>, tests: array<string, int>, examined: int} $walk
     * @param bool $launchesOnly read only the launches into the frontier, no stand-ins: the last level's launch hop
     * @return array{0: list<string>, 1: ?string} the next level's frontier, and the bound that stopped the walk
     */
    private function readLevel(string $projectId, array $frontier, int $level, array $starts, array $edgeKinds, int $minimumRank, int $deadline, array &$walk, bool $launchesOnly = false): array
    {
        $reason = null;
        // Next level's node id => true, so a launch met later on this level can take it back.
        $next = [];
        $found = [];
        for ($reading = $frontier; $reading !== [] && $reason === null;) {
            $launched = [];
            // A call through an interface or a parent goes to the supertype's method, and a property use the scan
            // could not attribute to the property is recorded against its class: what calls the supertype method
            // or uses the property's class may reach the frontier's own,
            // so their dependants are read too, the stand-in passed through ({@see StandInExpansion}).
            // A node read through stands in for the frontier node it was read for, wired as that one is.
            $standIns = $launchesOnly ? ['through' => [], 'constructed' => []] : StandInExpansion::of($this->pdo, $projectId, $reading, $edgeKinds);
            $through = self::passThrough($standIns['through'], $walk['seen'], $walk['passed'], $walk['wired']);
            $targets = [...$reading, ...$through];
            sort($targets, SORT_STRING);
            // A constructor runs whenever its class is constructed, which the graph records against the class:
            // what constructs the class reaches the constructor, and nothing else that names the class does.
            $constructed = self::passThrough($standIns['constructed'], $walk['seen'], $walk['passed'], $walk['wired']);
            sort($constructed, SORT_STRING);
            $roots = array_diff_key(CompositionRoots::of($this->pdo, $projectId, [...$targets, ...$constructed]), $starts);
            $reads = [...array_map(static fn(array $chunk): array => [$chunk, $edgeKinds], array_chunk($targets, self::CHUNK)), ...array_map(static fn(array $chunk): array => [$chunk, [ConstructedClasses::EDGE_KIND]], array_chunk($constructed, self::CHUNK))];
            foreach ($reads as [$chunk, $kinds]) {
                $rows = $this->dependantRows($projectId, $chunk, $kinds, $minimumRank);
                $row = 0;
                while (($edge = $rows->fetch(PDO::FETCH_NUM)) !== false) {
                    [$targetId, $sourceId, $kind] = $edge;
                    if ($row++ % self::CLOCK_EVERY === 0 && $this->now() > $deadline) {
                        $reason = 'time_limit';
                        break;
                    }
                    if ($walk['examined'] >= $this->maxEdges) {
                        $reason = 'edge_limit';
                        break;
                    }
                    ++$walk['examined'];
                    $sourceId = (string) $sourceId;
                    $launch = $kind === self::LAUNCH;
                    $viaWiring = isset($roots[(string) $targetId]) || ($walk['wired'][(string) $targetId] ?? false);
                    if (isset($walk['seen'][$sourceId])) {
                        // Met again on the level it was found at: any edge that is not wiring makes it direct.
                        if (isset($found[$sourceId]) && !$viaWiring) {
                            $walk['wired'][$sourceId] = false;
                        }
                        // Found for the next level, then met over a launch: it belongs to this one.
                        if ($launch && isset($next[$sourceId])) {
                            unset($next[$sourceId]);
                            $launched[] = $sourceId;
                        }
                        continue;
                    }
                    if (count($walk['seen']) >= $this->maxVisited) {
                        $reason = 'visit_limit';
                        break;
                    }
                    $walk['seen'][$sourceId] = true;
                    $found[$sourceId] = true;
                    $walk['wired'][$sourceId] = $viaWiring;
                    if ($launch) {
                        $launched[] = $sourceId;
                    } else {
                        $next[$sourceId] = true;
                    }
                }
                // Abandoned mid-result when a bound stops the walk; SQLite holds
                // its read lock until the cursor is closed.
                $rows->closeCursor();
                if ($reason !== null) {
                    break;
                }
            }
            $this->markTests($projectId, $launched, $level, $walk['tests']);
            $reading = $launched;
        }
        // Also when a bound cut the level: what it did visit is reported.
        $next = array_map('strval', array_keys($next));
        $this->markTests($projectId, $next, $level + 1, $walk['tests']);

        return [$next, $reason];
    }

    /**
     * Whether a node of the last level read, or a stand-in for one, has a dependant the walk has not visited.
     *
     * A level of leaves ends the walk as surely as an empty one, so reaching max_depth on it cuts
     * nothing. Reads at most {@see self::HORIZON_ROWS} rows, and none once the deadline has passed; past
     * either it answers yes, the side that only costs a deeper search. Each row it reads is an edge
     * examined, counted against the walk's edge bound like the rest; when that runs out first, the
     * check stops with `edge_limit` and no answer.
     *
     * @param list<string> $frontier @param list<string> $edgeKinds @param array<string, true> $seen
     * @param int $examined the walk's edges examined so far, advanced by the rows read here
     * @return array{0: bool, 1: ?string} whether an unread dependant was met, and the bound that cut the check
     */
    private function hasUnreadDependant(string $projectId, array $frontier, array $edgeKinds, int $minimumRank, array $seen, int $deadline, int &$examined): array
    {
        if ($this->now() > $deadline) {
            return [true, null];
        }
        $standIns = StandInExpansion::of($this->pdo, $projectId, $frontier, $edgeKinds);
        $budget = self::HORIZON_ROWS;
        $reads = [
            [[...$frontier, ...array_column($standIns['through'], 0)], $edgeKinds],
            [array_column($standIns['constructed'], 0), [ConstructedClasses::EDGE_KIND]],
        ];
        foreach ($reads as [$ids, $kinds]) {
            $ids = array_values(array_unique(array_map('strval', $ids)));
            sort($ids, SORT_STRING);
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $rows = $this->dependantRows($projectId, $chunk, $kinds, $minimumRank);
                while (($edge = $rows->fetch(PDO::FETCH_NUM)) !== false) {
                    if ($examined >= $this->maxEdges) {
                        $rows->closeCursor();

                        return [false, 'edge_limit'];
                    }
                    ++$examined;
                    if (!isset($seen[(string) $edge[1]]) || --$budget <= 0) {
                        $rows->closeCursor();

                        return [true, null];
                    }
                }
                $rows->closeCursor();
            }
        }

        return [false, null];
    }

    /**
     * The stand-ins not yet visited nor read through, each marked passed and
     * wired as the node it stands in for.
     *
     * @param list<array{0: string, 1: string}> $standIns [stand-in id, id it stands in for]
     * @param array<string, true> $seen
     * @param array<string, true> $passed
     * @param array<string, bool> $wired
     * @return list<string>
     */
    private static function passThrough(array $standIns, array $seen, array &$passed, array &$wired): array
    {
        $read = [];
        foreach ($standIns as [$id, $for]) {
            if (isset($seen[$id])) {
                continue;
            }
            if (!isset($passed[$id])) {
                $passed[$id] = true;
                $read[] = $id;
            }
            $wired[$id] = ($wired[$id] ?? true) && $wired[$for];
        }

        return $read;
    }

    /**
     * Record which of one level's newly visited components are test code.
     *
     * Looked up per chunk of the level rather than loaded for the whole
     * project up front, so the search holds nothing beyond what it visited:
     * a project's full test set sat outside the visit bound.
     *
     * @param list<string> $nodeIds visited at $distance
     * @param array<string, int> $tests
     */
    private function markTests(string $projectId, array $nodeIds, int $distance, array &$tests): void
    {
        sort($nodeIds, SORT_STRING);
        foreach (ChunkedInQuery::rows(
            $this->pdo,
            'SELECT node_id FROM classifications WHERE project_id = ? AND role = ? AND node_id IN (%s) GROUP BY node_id ORDER BY node_id',
            $nodeIds,
            [$projectId, ReportableComponent::TEST_ROLE],
            mode: PDO::FETCH_COLUMN,
            size: self::CHUNK,
        ) as $id) {
            $tests[(string) $id] = $distance;
        }
    }

    /**
     * The dependants of one chunk of the frontier, streamed in target, source and edge order.
     *
     * Only the target and source ids and the edge kind are selected: the walk
     * needs nothing else, and the order alone fixes which nodes a bound cuts.
     * Read by target: with one target the rest of the order is the source
     * index's own, and SQLite walked every edge of the project in it to spare
     * a sort of the target's few, 100 ms a chunk on 100,000 edges.
     *
     * @param list<string> $targetIds @param list<string> $edgeKinds
     */
    private function dependantRows(string $projectId, array $targetIds, array $edgeKinds, int $minimumRank): PDOStatement
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT e.target_id, e.source_id, e.kind FROM edges e INDEXED BY edges_project_target_idx WHERE e.project_id = ? AND e.target_id IN (%s) AND e.kind IN (%s) ' .
            'AND ' . ConfidenceRank::atLeast('e.confidence') . ' ' .
            'ORDER BY e.target_id, e.source_id, e.id',
            implode(',', array_fill(0, count($targetIds), '?')),
            implode(',', array_fill(0, count($edgeKinds), '?')),
        ));
        $statement->execute([$projectId, ...$targetIds, ...$edgeKinds, $minimumRank]);
        return $statement;
    }
}
