<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
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
 * Its bounds are its own: visited nodes, edge rows examined and one deadline,
 * each named in `truncation_reasons` when it cut the walk. The depth horizon is
 * the caller's question, not a cut, so reaching it is not a truncation.
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
     * @return array{tests: array<string, int>, wired: array<string, true>, visited: int, edges_examined: int, truncation_reasons: list<string>}
     *         tests: test-role node id => shortest distance; wired: the tests among them reached only through wiring
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
        $examined = 0;
        // Supertype methods and constructed classes already read through: apart from $seen, so one found later as a dependant in its own right still counts.
        $passed = [];
        for ($level = 0; $reason === null && $level < $maxDepth && $frontier !== [] && !($first && $tests !== []); ++$level) {
            // A call through an interface or a parent goes to the supertype's method: what calls that
            // may run the frontier's own, so its dependants are read too, the supertype method passed through.
            // A node read through stands in for the frontier node it was read for, wired as that one is.
            $through = [];
            $standIn = static function (string $id, string $for) use (&$wired): void {
                $wired[$id] = ($wired[$id] ?? true) && $wired[$for];
            };
            foreach (SupertypeMethods::of($this->pdo, $projectId, $frontier) as $method => $supertypes) {
                foreach ($supertypes as $supertype) {
                    if (!isset($seen[$supertype]) && !isset($passed[$supertype])) {
                        $passed[$supertype] = true;
                        $through[] = $supertype;
                    }
                    if (!isset($seen[$supertype])) {
                        $standIn($supertype, (string) $method);
                    }
                }
            }
            // Nothing points at a property: its class's methods read it, so what uses the class may use it.
            foreach (PropertyOwners::of($this->pdo, $projectId, $frontier) as $property => $class) {
                if (!isset($seen[$class]) && !isset($passed[$class])) {
                    $passed[$class] = true;
                    $through[] = $class;
                }
                if (!isset($seen[$class])) {
                    $standIn($class, (string) $property);
                }
            }
            $targets = [...$frontier, ...$through];
            sort($targets, SORT_STRING);
            // A constructor runs whenever its class is constructed, which the graph records against the class:
            // what constructs the class reaches the constructor, and nothing else that names the class does.
            $constructed = [];
            if (in_array(ConstructedClasses::EDGE_KIND, $edgeKinds, true)) {
                foreach (ConstructedClasses::of($this->pdo, $projectId, $frontier) as $constructor => $classes) {
                    foreach ($classes as $class) {
                        if (!isset($seen[$class]) && !isset($passed[$class])) {
                            $passed[$class] = true;
                            $constructed[] = $class;
                        }
                        if (!isset($seen[$class])) {
                            $standIn($class, (string) $constructor);
                        }
                    }
                }
                sort($constructed, SORT_STRING);
            }
            $roots = array_diff_key(CompositionRoots::of($this->pdo, $projectId, [...$targets, ...$constructed]), $starts);
            $next = [];
            $found = [];
            $reads = [...array_map(static fn(array $chunk): array => [$chunk, $edgeKinds], array_chunk($targets, self::CHUNK)), ...array_map(static fn(array $chunk): array => [$chunk, [ConstructedClasses::EDGE_KIND]], array_chunk($constructed, self::CHUNK))];
            foreach ($reads as [$chunk, $kinds]) {
                $rows = $this->dependantRows($projectId, $chunk, $kinds, $minimumRank);
                $row = 0;
                while (($edge = $rows->fetch(PDO::FETCH_NUM)) !== false) {
                    [$targetId, $sourceId] = $edge;
                    if ($row++ % self::CLOCK_EVERY === 0 && $this->now() > $deadline) {
                        $reason = 'time_limit';
                        break;
                    }
                    if ($examined >= $this->maxEdges) {
                        $reason = 'edge_limit';
                        break;
                    }
                    ++$examined;
                    $sourceId = (string) $sourceId;
                    $viaWiring = isset($roots[(string) $targetId]) || ($wired[(string) $targetId] ?? false);
                    if (isset($seen[$sourceId])) {
                        // Met again on the level it was found at: any edge that is not wiring makes it direct.
                        if (isset($found[$sourceId]) && !$viaWiring) {
                            $wired[$sourceId] = false;
                        }
                        continue;
                    }
                    if (count($seen) >= $this->maxVisited) {
                        $reason = 'visit_limit';
                        break;
                    }
                    $seen[$sourceId] = true;
                    $found[$sourceId] = true;
                    $wired[$sourceId] = $viaWiring;
                    $next[] = $sourceId;
                }
                // Abandoned mid-result when a bound stops the walk; SQLite holds
                // its read lock until the cursor is closed.
                $rows->closeCursor();
                if ($reason !== null) {
                    break;
                }
            }
            // Also when a bound cut the level: what it did visit is reported.
            $this->markTests($projectId, $next, $level + 1, $tests);
            $frontier = $next;
        }

        $wiredTests = [];
        foreach (array_keys($tests) as $id) {
            if ($wired[(string) $id] ?? false) {
                $wiredTests[(string) $id] = true;
            }
        }

        return ['tests' => $tests, 'wired' => $wiredTests, 'visited' => count($seen), 'edges_examined' => $examined, 'truncation_reasons' => $reason === null ? [] : [$reason]];
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
     * Only the target and source ids are selected: the walk needs nothing
     * else, and the order alone fixes which nodes a bound cuts.
     *
     * @param list<string> $targetIds @param list<string> $edgeKinds
     */
    private function dependantRows(string $projectId, array $targetIds, array $edgeKinds, int $minimumRank): PDOStatement
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT e.target_id, e.source_id FROM edges e WHERE e.project_id = ? AND e.target_id IN (%s) AND e.kind IN (%s) ' .
            "AND CASE e.confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
            'ORDER BY e.target_id, e.source_id, e.id',
            implode(',', array_fill(0, count($targetIds), '?')),
            implode(',', array_fill(0, count($edgeKinds), '?')),
        ));
        $statement->execute([$projectId, ...$targetIds, ...$edgeKinds, $minimumRank]);
        return $statement;
    }
}
