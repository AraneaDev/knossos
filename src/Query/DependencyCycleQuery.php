<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use Knossos\Result\ResultEnvelope;
use Knossos\Scanner\Protocol\ConfidenceRank;
use Knossos\Store\ChunkedInQuery;
use Knossos\Store\EnrichedFacts;
use Knossos\Store\NodeQueries;

/**
 * Dependency cycles: the strongly connected components of the selected dependency graph.
 *
 * Its own class because three answers lean on it: `dependency_cycles` itself,
 * the cycle signal `architecture_health` folds into its hotspots, and the
 * cycles `review_diff` reports. Each reaches the same search with the same
 * bounds, so none of them can come to count a cycle the others do not. A
 * cycle is a fact about native code, so enriched edges never close one
 * ({@see EnrichedFacts}).
 */
final readonly class DependencyCycleQuery extends AbstractArchitectureQueryService
{
    /**
     * Strongly connected components, bounded by node, edge, and time limits.
     *
     * The search runs in two phases. The whole selected graph is read as bare
     * endpoint pairs and searched first; names, file paths and lines are loaded
     * afterwards, and only for the members and edges of the components that are
     * reported. Reading every edge with both endpoints and its file attached
     * cost two orders of magnitude more than the search itself, so on a graph of
     * a few tens of thousands of edges the deadline ran out while rows were
     * still being read, and the search reported no cycles at all.
     *
     * max_nodes counts only the nodes that take part in a selected edge, so a
     * symbol nothing depends on and that depends on nothing never uses it up.
     *
     * touchingIds keeps only the cycles with a member among those node ids, and
     * does so before limit applies, so a caller asking about one change gets
     * the cycles it touches however many others sort ahead of them. Empty means
     * every cycle.
     *
     * withinType keeps the cycles inside one type ({@see OneTypeCycles}), a
     * symbol that only calls itself still left out, and marks every cycle:
     * `within_type`, and `type`, the canonical name of the type it stays
     * inside (null for one between types). Those come after every cycle
     * between types under a limit of their own: `result_limit` says only
     * that cycles between types were cut, and `bounds.within_type_truncated`
     * whether these were. A self-loop asked for too is one of these.
     *
     * @param list<string> $edgeKinds
     * @param list<string> $touchingIds
     */
    public function dependencyCycles(string $projectId, array $edgeKinds = [], string $minConfidence = 'possible', int $limit = 20, int $maxNodes = QueryBounds::MAX_NODES, int $maxEdges = QueryBounds::MAX_EDGES, int $timeoutMs = 1000, bool $includeSelfLoops = false, array $touchingIds = [], bool $withinType = false): ResultEnvelope
    {
        $project = $this->project($projectId);
        self::assertLimit($limit);
        if ($maxNodes < 1 || $maxNodes > QueryBounds::MAX_NODES) {
            throw new InvalidArgumentException(sprintf('max_nodes must be between 1 and %d.', QueryBounds::MAX_NODES));
        }
        $confidenceRank = $this->confidenceQueryBounds($maxEdges, $timeoutMs, $minConfidence);
        $edgeKinds = self::selectedEdgeKinds($edgeKinds, self::IMPACT_EDGE_KINDS, 'dependency');

        $deadline = $this->now() + ($timeoutMs * 1_000_000);
        // One read transaction over both phases, so the details are loaded
        // from the same snapshot the search ran over; a scan that commits in
        // between cannot take away a row the search counted on.
        $own = !$this->pdo->inTransaction() && $this->pdo->beginTransaction();
        try {
            $search = $this->cycleSearch($projectId, $edgeKinds, $confidenceRank, $minConfidence, $limit, $maxNodes, $maxEdges, $deadline, $includeSelfLoops, $touchingIds, $withinType);
        } finally {
            if ($own) {
                $this->pdo->commit();
            }
        }
        $truncationReasons = $search['truncation_reasons'];
        // A bounded search must not read as an exhaustive one: "Found 0
        // dependency cycle components" is the same sentence a genuinely acyclic
        // project gets, and a cycle living beyond the edge cap is invisible in
        // it. Naming the bound in the summary is what lets a caller tell the two
        // apart without reading bounds.truncation_reasons.
        $cycles = $search['cycles'];
        $summary = sprintf('Found %d dependency cycle component%s.', count($cycles), count($cycles) === 1 ? '' : 's');
        if ($truncationReasons !== []) {
            $summary .= sprintf(' The search was truncated (%s), so cycles beyond that bound are not reported.', implode(', ', $truncationReasons));
        }

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            $summary,
            [
                'cycles' => $cycles,
                'bounds' => [
                    'limit' => $limit, 'max_nodes' => $maxNodes, 'max_edges' => $maxEdges,
                    'timeout_ms' => $timeoutMs, 'nodes_examined' => $search['nodes_examined'],
                    'edges_examined' => $search['edges_examined'], 'truncation_reasons' => $truncationReasons,
                ] + ($withinType ? ['within_type_truncated' => $search['within_type_truncated']] : []),
            ],
            $search['evidence'],
            [
                'Cycles are derived from the selected static dependency relationships and confidence threshold.',
                'Imports and re-exports that carry only types are erased at compile time and are not treated as dependencies, so a loop closed by nothing but `import type` is not reported.',
            ],
            $truncationReasons !== [],
        );
    }

    /**
     * Both phases of the cycle search: the whole selected graph searched as
     * numbered endpoint pairs, then the reported components' details loaded.
     *
     * @param list<string> $edgeKinds
     * @param array<string, int> $confidenceRank
     * @param list<string> $touchingIds
     * @return array{nodes_examined: int, edges_examined: int, truncation_reasons: list<string>, cycles: list<array<string, mixed>>, evidence: list<array<string, mixed>>, within_type_truncated: bool}
     */
    private function cycleSearch(string $projectId, array $edgeKinds, array $confidenceRank, string $minConfidence, int $limit, int $maxNodes, int $maxEdges, int $deadline, bool $includeSelfLoops, array $touchingIds, bool $withinType = false): array
    {
        $graph = $this->cycleSearchGraph($projectId, $edgeKinds, $confidenceRank[$minConfidence], $maxNodes, $maxEdges, $deadline);
        $truncation = new Truncation();
        $truncation->add(...$graph['truncation_reasons']);
        $componentScan = $this->stronglyConnectedComponents($graph['adjacency'], $graph['reverse'], $deadline);
        if ($componentScan['timed_out']) {
            $truncation->add('time_limit');
        }
        // A single self-recursive symbol is ordinary recursion, not an
        // architectural tangle, so self-loops are opt-in.
        $components = [];
        foreach ($componentScan['components'] as $component) {
            if (count($component) > 1 || ($includeSelfLoops && isset($graph['self_loops'][$component[0]]))) {
                $components[] = $component;
            }
        }
        $nodeIds = $graph['node_ids'];
        $components = array_map(static function (array $component) use ($nodeIds): array {
            $members = [];
            foreach ($component as $index) {
                $members[] = $nodeIds[$index];
            }
            sort($members, SORT_STRING);

            return $members;
        }, $components);
        // Recursion inside one type is opt-in for the same reason a self-loop is; asked for, it is marked.
        $declaringType = $components !== [] && (!$includeSelfLoops || $withinType) ? $this->declaringTypes(array_merge(...$components)) : [];
        if (!$includeSelfLoops && !$withinType && $components !== []) {
            $components = array_values(array_filter($components, static fn(array $members): bool => !OneTypeCycles::withinOneType($members, $declaringType)));
        }
        if ($touchingIds !== []) {
            $touching = array_fill_keys($touchingIds, true);
            $components = array_values(array_filter($components, static function (array $members) use ($touching): bool {
                foreach ($members as $id) {
                    if (isset($touching[$id])) {
                        return true;
                    }
                }

                return false;
            }));
        }
        // Largest first. Asked for, the cycles that stay inside one type (a
        // self-loop among them) come after every other, under a limit of their
        // own: they neither cut a cycle between types nor mark that count as cut.
        $largestFirst = static fn(array $a, array $b): int => (count($b) <=> count($a)) ?: ($a[0] <=> $b[0]);
        $inside = [];
        if ($withinType) {
            $between = [];
            foreach ($components as $members) {
                if (count($members) === 1 || OneTypeCycles::withinOneType($members, $declaringType)) {
                    $inside[] = $members;
                } else {
                    $between[] = $members;
                }
            }
            $components = $between;
            usort($inside, $largestFirst);
        }
        usort($components, $largestFirst);
        [$components, $cut] = Page::cut($components, $limit);
        if ($cut) {
            $truncation->add('result_limit');
        }
        [$inside, $insideCut] = Page::cut($inside, $limit);
        $listed = [...$components, ...$inside];

        $detail = $this->cycleDetails($listed, $graph, $confidenceRank, $includeSelfLoops);
        $truncation->add(...$detail['truncation_reasons']);
        if ($withinType) {
            $detail['cycles'] = $this->marked($detail['cycles'], count($components), $inside, $declaringType);
        }

        return [
            'nodes_examined' => count($nodeIds), 'edges_examined' => count($graph['edge_ids']),
            'truncation_reasons' => $truncation->reasons(),
            'cycles' => $detail['cycles'], 'evidence' => $detail['evidence'],
            'within_type_truncated' => $insideCut,
        ];
    }

    /**
     * The selected dependency graph as numbered adjacency lists, read in one narrow pass.
     *
     * Each node is numbered in the order the edge walk first meets it, and the
     * search runs over those numbers rather than over the ids. An edge keeps its
     * id and its two ends, so the details of the few edges inside a reported
     * component can be loaded afterwards in the order the walk read them.
     *
     * The walk stops at the first edge whose new node would exceed max_nodes;
     * that edge's source still counts when it was new, as it always has.
     *
     * @param list<string> $edgeKinds
     * @return array{node_ids: list<string>, node_index: array<string, int>, adjacency: list<list<int>>, reverse: list<list<int>>, self_loops: array<int, true>, edge_ids: list<string>, edge_sources: list<int>, edge_targets: list<int>, truncation_reasons: list<string>}
     */
    private function cycleSearchGraph(string $projectId, array $edgeKinds, int $minimumRank, int $maxNodes, int $maxEdges, int $deadline): array
    {
        $placeholders = implode(',', array_fill(0, count($edgeKinds), '?'));
        // Only what the search and the erased-import test read: the attributes
        // of any other kind are never decoded, so they are not read either.
        $statement = $this->pdo->prepare(
            "SELECT e.id, e.kind, e.source_id, e.target_id, CASE WHEN e.kind IN ('imports', 're_exports') THEN e.attributes_json END AS attributes_json " .
            'FROM edges e WHERE e.project_id = ? AND ' . EnrichedFacts::excludeSql('e') . ' ' .
            sprintf('AND e.kind IN (%s) ', $placeholders) .
            'AND ' . ConfidenceRank::atLeast('e.confidence') . ' ' .
            'ORDER BY e.source_id, e.target_id, e.kind, e.id LIMIT ?',
        );
        $statement->execute([$projectId, ...$edgeKinds, $minimumRank, $maxEdges + 1]);
        $nodeIds = $nodeIndex = $adjacency = $reverse = $selfLoops = $edgeIds = $edgeSources = $edgeTargets = [];
        $nodeLimitHit = false;
        // The graph is built in local arrays, each end looked up once and
        // written out, because this runs once per selected edge: on a scan of
        // this repository (85,000 edges) writing through one nested $graph
        // array, and numbering both ends in a loop, cost 80 ms of 470.
        $reasons = $this->streamBounded(
            $statement,
            $maxEdges,
            $deadline,
            static function (array $row) use (&$nodeIds, &$nodeIndex, &$adjacency, &$reverse, &$selfLoops, &$edgeIds, &$edgeSources, &$edgeTargets, &$nodeLimitHit, $maxNodes): bool {
                // Dropped before the node cap sees it, so a pair joined by
                // nothing but erased type imports contributes neither the edge
                // nor the two nodes it would have introduced. Only imports
                // carry attributes here, so any other edge is kept unread.
                if ($row['attributes_json'] !== null && self::isErasedTypeEdge($row)) {
                    return true;
                }
                $sourceId = (string) $row['source_id'];
                $targetId = (string) $row['target_id'];
                $source = $nodeIndex[$sourceId] ?? null;
                if ($source === null) {
                    if (count($nodeIds) >= $maxNodes) {
                        $nodeLimitHit = true;

                        return false;
                    }
                    $source = $nodeIndex[$sourceId] = count($nodeIds);
                    $nodeIds[] = $sourceId;
                    $adjacency[] = [];
                    $reverse[] = [];
                }
                $target = $nodeIndex[$targetId] ?? null;
                if ($target === null) {
                    if (count($nodeIds) >= $maxNodes) {
                        $nodeLimitHit = true;

                        return false;
                    }
                    $target = $nodeIndex[$targetId] = count($nodeIds);
                    $nodeIds[] = $targetId;
                    $adjacency[] = [];
                    $reverse[] = [];
                }
                $adjacency[$source][] = $target;
                $reverse[$target][] = $source;
                if ($source === $target) {
                    $selfLoops[$source] = true;
                }
                $edgeIds[] = (string) $row['id'];
                $edgeSources[] = $source;
                $edgeTargets[] = $target;

                return true;
            },
        );
        // The node cap is the one stop condition streamBounded cannot see, so it
        // reports itself here rather than being folded into a row reason.
        if ($nodeLimitHit) {
            $reasons[] = 'node_limit';
        }

        return [
            'node_ids' => $nodeIds, 'node_index' => $nodeIndex, 'adjacency' => $adjacency, 'reverse' => $reverse, 'self_loops' => $selfLoops,
            'edge_ids' => $edgeIds, 'edge_sources' => $edgeSources, 'edge_targets' => $edgeTargets, 'truncation_reasons' => $reasons,
        ];
    }

    /**
     * Members, relationships and evidence for the reported components, loaded for those alone.
     *
     * Each component lists its first 100 members and the first 200 edges that
     * join two of them, in the order the search read them; trimming either is
     * reported as member_limit or internal_edge_limit, and a member or edge
     * whose row cannot be loaded as missing_detail.
     *
     * @param list<list<string>> $components member ids, each component sorted
     * @param array{node_index: array<string, int>, edge_ids: list<string>, edge_sources: list<int>, edge_targets: list<int>} $graph
     * @param array<string, int> $confidenceRank
     * @return array{cycles: list<array<string, mixed>>, evidence: list<array<string, mixed>>, truncation_reasons: list<string>}
     */
    private function cycleDetails(array $components, array $graph, array $confidenceRank, bool $includeSelfLoops = false): array
    {
        $componentOf = [];
        foreach ($components as $componentIndex => $component) {
            foreach ($component as $id) {
                $componentOf[$graph['node_index'][$id]] = $componentIndex;
            }
        }
        $internal = array_fill(0, count($components), []);
        if ($componentOf !== []) {
            foreach ($graph['edge_sources'] as $position => $source) {
                $componentIndex = $componentOf[$source] ?? null;
                // A self-call inside a cycle is recursion, not one of the
                // relationships that make the cycle.
                if ($componentIndex !== null && ($componentOf[$graph['edge_targets'][$position]] ?? null) === $componentIndex
                    && ($includeSelfLoops || $graph['edge_targets'][$position] !== $source)) {
                    $internal[$componentIndex][] = $position;
                }
            }
        }
        $sampledIds = $memberIds = [];
        foreach ($components as $componentIndex => $component) {
            foreach (array_slice($internal[$componentIndex], 0, 200) as $position) {
                $sampledIds[] = $graph['edge_ids'][$position];
            }
            array_push($memberIds, ...array_slice($component, 0, 100));
        }
        $edgeRows = $this->rowsByIds(
            'SELECT e.id, e.kind, e.source_id, e.target_id, e.origin, e.confidence, e.start_line, e.end_line, ' . EdgeEvidencePath::sql() . ' AS relative_path ' .
            'FROM edges e LEFT JOIN files f ON f.id = e.file_id WHERE e.id IN (%s)',
            $sampledIds,
        );
        $nodeRows = $this->rowsByIds('SELECT id, kind, canonical_name, display_name, confidence FROM nodes WHERE id IN (%s)', $memberIds);
        $boundaryMap = $this->boundaryNames($memberIds);

        $cycles = $evidence = $truncationReasons = [];
        foreach ($components as $componentIndex => $component) {
            $edgeTruncated = count($internal[$componentIndex]) > 200;
            $memberTruncated = count($component) > 100;
            // Per-cycle member/edge trimming is real result truncation; surface
            // it on the envelope so dependency_cycles never reports truncated:false
            // over demonstrably truncated cycle detail.
            if ($memberTruncated) {
                $truncationReasons[] = 'member_limit';
            }
            if ($edgeTruncated) {
                $truncationReasons[] = 'internal_edge_limit';
            }
            $cycleEdges = [];
            $minimum = 3;
            // Both phases read one snapshot, so a row is missing only where the
            // graph itself lacks it: an edge left pointing at a node that is
            // gone. The cycle says so rather than shrinking without a word.
            $missing = false;
            foreach (array_slice($internal[$componentIndex], 0, 200) as $position) {
                $edge = $edgeRows[$graph['edge_ids'][$position]] ?? null;
                if ($edge === null) {
                    $missing = true;
                    continue;
                }
                $minimum = min($minimum, $confidenceRank[$edge['confidence']]);
                $cycleEdges[] = [
                    'id' => $edge['id'], 'kind' => $edge['kind'], 'source_id' => $edge['source_id'],
                    'target_id' => $edge['target_id'], 'origin' => $edge['origin'], 'confidence' => $edge['confidence'],
                ];
                if ($edge['relative_path'] !== null && count($evidence) < 500) {
                    $evidence[] = [
                        'component_index' => $componentIndex, 'edge_id' => $edge['id'], 'path' => $edge['relative_path'],
                        'start_line' => $edge['start_line'], 'end_line' => $edge['end_line'],
                    ];
                }
            }
            $members = [];
            foreach (array_slice($component, 0, 100) as $id) {
                if (isset($nodeRows[$id])) {
                    $members[] = $nodeRows[$id] + ['boundaries' => $boundaryMap[$id] ?? []];
                } else {
                    $missing = true;
                }
            }
            if ($missing) {
                $truncationReasons[] = 'missing_detail';
            }
            $cycles[] = [
                'size' => count($component),
                'minimum_confidence' => array_search($minimum, $confidenceRank, true),
                // Full membership (pre-slice) so callers such as architecture_health
                // can flag every participant, not just the first 100.
                'member_ids' => $component,
                'members' => $members,
                'relationships' => $cycleEdges,
                'truncated' => $edgeTruncated || $memberTruncated || $missing,
                'truncation_reasons' => array_values(array_filter([$memberTruncated ? 'member_limit' : null, $edgeTruncated ? 'internal_edge_limit' : null, $missing ? 'missing_detail' : null])),
            ];
        }

        return ['cycles' => $cycles, 'evidence' => $evidence, 'truncation_reasons' => $truncationReasons];
    }

    /**
     * Each cycle marked: whether it stays inside one type, and that type's
     * canonical name (null for a cycle between types, and for a self-loop on
     * a symbol no type declares).
     *
     * @param list<array<string, mixed>> $cycles the `$between` cycles between types, then one per `$inside`
     * @param list<list<string>> $inside member ids of the cycles inside one type
     * @param array<string, string> $declaringType member id => type id
     * @return list<array<string, mixed>>
     */
    private function marked(array $cycles, int $between, array $inside, array $declaringType): array
    {
        $typeIds = [];
        foreach ($inside as $index => $members) {
            $typeIds[$between + $index] = $declaringType[$members[0]] ?? null;
        }
        $names = NodeQueries::canonicalNames($this->pdo, array_values(array_unique(array_filter($typeIds, 'is_string'))));

        return array_map(static function (array $cycle, int $index) use ($between, $typeIds, $names): array {
            $typeId = $typeIds[$index] ?? null;

            return $cycle + [
                'within_type' => $index >= $between,
                'type' => $typeId === null ? null : ($names[$typeId] ?? $typeId),
            ];
        }, $cycles, array_keys($cycles));
    }

    /**
     * The class, interface, trait or enum that contains each of these members.
     *
     * @param list<string> $memberIds
     * @return array<string, string> member id => type id
     */
    private function declaringTypes(array $memberIds): array
    {
        $types = [];
        foreach (ChunkedInQuery::rows(
            $this->pdo,
            "SELECT e.target_id, e.source_id FROM edges e JOIN nodes p ON p.id = e.source_id WHERE e.kind = 'contains' "
            . "AND p.kind IN ('" . implode("', '", OneTypeCycles::TYPE_KINDS) . "') AND e.target_id IN (%s)",
            array_values(array_unique($memberIds)),
            mode: \PDO::FETCH_NUM,
        ) as [$member, $type]) {
            $types[(string) $member] = (string) $type;
        }

        return $types;
    }

    /**
     * Rows keyed by their id column, fetched in chunks small enough for SQLite's parameter limit.
     *
     * @param string $sql a query whose `%s` is replaced by the placeholders of one chunk
     * @param list<string> $ids
     * @return array<string, array<string, mixed>>
     */
    private function rowsByIds(string $sql, array $ids): array
    {
        $rows = [];
        foreach (ChunkedInQuery::rows($this->pdo, $sql, array_values(array_unique($ids))) as $row) {
            $rows[$row['id']] = $row;
        }

        return $rows;
    }
}
