<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use Knossos\Result\ResultEnvelope;

/**
 * What depends on a component: `impact_analysis`'s conservative static blast radius.
 *
 * Walks backward along the dependency relationships, the wider set the
 * cycle search reads too, so a change's dependants are everything that could
 * notice it rather than only what a request passes through. It over-reports
 * by design and says so; change impact builds on it, one analysis per
 * changed component, under one shared deadline.
 */
final readonly class ImpactAnalysisQuery extends AbstractArchitectureQueryService
{
    /** The most members of a class or module the walk starts from. */
    private const MAX_MEMBERS = 500;

    /**
     * The largest `limit` a caller may set: the pane's rings read up to this
     * many dependants. The MCP tool keeps its own, smaller bound.
     */
    public const MAX_LIMIT = 1500;

    /**
     * Conservative static blast radius of changing a symbol; over-reports by design and says so.
     *
     * Reads as its phases: validate the bounds, resolve the symbol to one
     * component, walk its dependants breadth-first, and report them grouped by
     * distance and confidence.
     *
     * @param list<string> $edgeKinds
     * @param bool $seedMembers start the walk from a class's or module's members too, each dependant found
     *                          through one naming it as `via_member`
     * @param bool $frameworkHooks count what uses a class as depending on the framework hooks it declares
     *                             ({@see FrameworkHookMethods}): the framework calls them whenever the class is used
     * @param bool $rings add `rings`: the dependants counted one hop out, two, and further, tests apart
     * @param bool $links add `links`: every edge the walk read, as `[source id, target id]`, rediscoveries included
     */
    public function impactAnalysis(string $projectId, string $symbol, int $maxDepth = 6, int $limit = 100, array $edgeKinds = [], string $minConfidence = 'possible', int $timeoutMs = 1000, ?int $deadline = null, bool $seedMembers = true, bool $frameworkHooks = true, bool $rings = false, bool $links = false): ResultEnvelope
    {
        $project = $this->project($projectId);
        if ($maxDepth < 1 || $maxDepth > 8) {
            throw new InvalidArgumentException('max_depth must be between 1 and 8.');
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(sprintf('Limit must be between 1 and %d.', self::MAX_LIMIT));
        }
        $confidenceRank = $this->confidenceThreshold($timeoutMs, $minConfidence);
        $edgeKinds = self::selectedEdgeKinds($edgeKinds, self::IMPACT_EDGE_KINDS, 'impact');
        $candidates = $this->resolve($projectId, $symbol);
        if (count($candidates) !== 1) {
            return self::unresolvedImpact($project, $symbol, $candidates);
        }
        $target = $candidates[0];
        // A caller (e.g. changed_files_impact fanning out over many components)
        // can pass one shared deadline so the whole request is bounded, instead
        // of each analysis resetting its own timeout.
        $deadline ??= $this->now() + ($timeoutMs * 1_000_000);
        $truncation = new Truncation();
        $search = $this->searchDependants($projectId, $target, $maxDepth, $limit, $edgeKinds, $confidenceRank[$minConfidence], $deadline, $truncation, $seedMembers, $frameworkHooks);

        $envelope = $this->impactEnvelope(
            $project,
            $target,
            $search['dependants'],
            ['max_depth' => $maxDepth, 'limit' => $limit, 'timeout_ms' => $timeoutMs, 'visited_states' => $search['visited']],
            $truncation,
        );
        if (!$rings && !$links) {
            return $envelope;
        }
        $data = $envelope->data;
        if ($rings) {
            $data['rings'] = $this->rings($data['dependants']);
        }
        if ($links) {
            $data['links'] = $search['links'];
        }

        return $envelope->withData($data);
    }

    /**
     * The dependants counted one hop out, two, and three or more, each ring's
     * tests counted apart from its other members and those by the confidence
     * of their path.
     *
     * @param list<array<string, mixed>> $dependants
     * @return list<array{hop: int, count: int, tests: int, by_confidence: array{certain: int, probable: int, possible: int}}>
     */
    private function rings(array $dependants): array
    {
        $roles = $this->roles(array_map(static fn(array $row): string => (string) $row['node']['id'], $dependants));
        $rings = [];
        foreach ([1, 2, 3] as $hop) {
            $rings[$hop] = ['hop' => $hop, 'count' => 0, 'tests' => 0, 'by_confidence' => ['certain' => 0, 'probable' => 0, 'possible' => 0]];
        }
        foreach ($dependants as $row) {
            $hop = min(3, (int) $row['distance']);
            if (ReportableComponent::isTest(array_column($roles[(string) $row['node']['id']] ?? [], 'role'))) {
                ++$rings[$hop]['tests'];
                continue;
            }
            ++$rings[$hop]['count'];
            ++$rings[$hop]['by_confidence'][(string) $row['path_confidence']];
        }

        return array_values($rings);
    }

    /**
     * The answer when the symbol resolved to no component or to several.
     *
     * @param array<string, mixed> $project
     * @param list<array<string, mixed>> $candidates
     */
    private static function unresolvedImpact(array $project, string $symbol, array $candidates): ResultEnvelope
    {
        return new ResultEnvelope(
            $project['id'],
            $project['active_scan_id'],
            $candidates === [] ? sprintf('No component matched "%s".', $symbol) : 'Impact analysis requires one unambiguous component.',
            ['query' => $symbol, 'candidates' => $candidates, 'dependants' => [], 'counts' => ['by_distance' => [], 'by_confidence' => ['certain' => 0, 'probable' => 0, 'possible' => 0]], 'entry_points' => []],
            [],
            [$candidates === [] ? self::UNMATCHED_ADVICE : self::AMBIGUOUS_ADVICE],
        );
    }

    /**
     * The breadth-first walk outward from the target along inbound edges,
     * each dependant recorded at its shortest distance with the strongest
     * confidence any path of that length gives it.
     *
     * @param array<string, mixed> $target
     * @param list<string> $edgeKinds
     * @return array{dependants: list<array<string, mixed>>, visited: int, links: list<array{0: string, 1: string}>}
     */
    private function searchDependants(string $projectId, array $target, int $maxDepth, int $limit, array $edgeKinds, int $minimumRank, int $deadline, Truncation $truncation, bool $seedMembers, bool $frameworkHooks): array
    {
        [$queue, $seen, $bestRank, $throughMember] = $this->seedQueue($projectId, $target, $seedMembers);
        // A head index instead of array_shift, which re-indexes the whole queue
        // on every pop; the visit order is the same first-in, first-out order.
        $head = 0;
        $dependants = [];
        $recordIndex = [];
        $edgesTruncated = false;
        $visited = 0;
        // Every edge read, by "source>target", into the node it counts for.
        $links = [];
        while (isset($queue[$head])) {
            // No visit bound: only accepted dependants are queued, and at most
            // $limit are accepted, so at most $limit + 1 states are visited.
            if ($this->now() > $deadline) {
                $truncation->add('time_limit');
                break;
            }
            [$current, $distance] = $queue[$head];
            unset($queue[$head]);
            ++$head;
            $pathConfidence = $bestRank[$current['id']];
            ++$visited;
            if ($distance >= $maxDepth) {
                continue;
            }
            foreach ($this->dependantEdges($projectId, $current, $edgeKinds, $minimumRank, $frameworkHooks, $edgesTruncated) as $edge) {
                if ($edge['source_id'] !== $target['id']) {
                    $links[$edge['source_id'] . '>' . $current['id']] = [(string) $edge['source_id'], (string) $current['id']];
                }
                if (isset($seen[$edge['source_id']])) {
                    $this->relaxRank($edge, $distance, $pathConfidence, $bestRank, $dependants, $recordIndex);
                    continue;
                }
                $node = $this->node($edge['source_id']);
                if ($node === null) {
                    continue;
                }
                $seen[$node['id']] = true;
                $dependants[] = $this->recordDependant($node, $edge, $current, $distance, $pathConfidence, $bestRank, $throughMember);
                // limit+1 semantics: keep exactly $limit, flag truncation only
                // when a further dependant actually exists.
                if (count($dependants) > $limit) {
                    array_pop($dependants);
                    $truncation->add('result_limit');
                    break 2;
                }
                $recordIndex[$node['id']] = array_key_last($dependants);
                $queue[] = [$node, $distance + 1];
            }
        }
        if ($edgesTruncated) {
            // A hub with more than 500 inbound edges of the selected kinds had
            // some silently dropped; surface it instead of claiming completeness.
            $truncation->add('per_node_edge_limit');
        }

        return ['dependants' => $dependants, 'visited' => $visited, 'links' => array_values($links)];
    }

    /**
     * The walk's starting state: the target, and its members when the walk
     * seeds them, all at distance 0 and certain.
     *
     * @param array<string, mixed> $target
     * @return array{0: list<array{0: array<string, mixed>, 1: int}>, 1: array<string, true>, 2: array<string, int>, 3: array<string, string>}
     *         the queue, the seen ids, the best rank per id, and the member each seeded id was reached through
     */
    private function seedQueue(string $projectId, array $target, bool $seedMembers): array
    {
        $queue = [[$target, 0]];
        $seen = [$target['id'] => true];
        // Best confidence rank found for each node at its shortest distance.
        // Read at dequeue rather than carried in the queued tuple: every
        // rediscovery at distance d+1 comes from a level-d node, and FIFO order
        // dequeues all level-d nodes before any level-d+1 node, so by the time
        // a node is dequeued no further upgrade of its rank is possible and the
        // value read here is final.
        $bestRank = [$target['id'] => 3];
        // A class or module is changed through its members too: the walk starts from
        // each of them as well, and what it finds through one names that member.
        $throughMember = [];
        foreach ($seedMembers ? $this->members($projectId, $target) : [] as $member) {
            if (isset($seen[$member['id']])) {
                continue;
            }
            $queue[] = [$member, 0];
            $seen[$member['id']] = true;
            $bestRank[$member['id']] = 3;
            $throughMember[$member['id']] = (string) $member['canonical_name'];
        }

        return [$queue, $seen, $bestRank, $throughMember];
    }

    /**
     * The edges into one dequeued node, and those read through its stand-ins.
     *
     * @param array<string, mixed> $current
     * @param list<string> $edgeKinds
     * @return list<array<string, mixed>>
     */
    private function dependantEdges(string $projectId, array $current, array $edgeKinds, int $minimumRank, bool $frameworkHooks, bool &$edgesTruncated): array
    {
        // A call through an interface or a parent goes to the supertype's method, so what calls that may
        // run this one: its edges count as this node's, the supertype method itself never listed for it.
        // Nothing points at a property: its class's methods read it, so what uses the class may use it.
        // Which implementation runs, or which use touches the property, is a guess, so such an edge is
        // probable at best.
        $edges = $this->impactEdges($projectId, $current['id'], $edgeKinds, $minimumRank, $edgesTruncated);
        $standIns = StandInExpansion::of($this->pdo, $projectId, [(string) $current['id']], $edgeKinds);
        foreach ($standIns['through'] as [$standIn]) {
            foreach ($this->impactEdges($projectId, $standIn, $edgeKinds, $minimumRank, $edgesTruncated) as $edge) {
                $edges[] = StandInExpansion::downgradeCertain($edge);
            }
        }
        // Constructing a class runs its constructor, and the graph records that against the class: what
        // constructs it is this constructor's dependant, at the construction's own confidence. Only that edge:
        // a type in a signature constructs nothing. The class itself is never listed for it.
        foreach ($standIns['constructed'] as [$class]) {
            array_push($edges, ...$this->impactEdges($projectId, $class, [ConstructedClasses::EDGE_KIND], $minimumRank, $edgesTruncated));
        }
        // The framework calls a hook (`booted`, `newFactory`) whenever its class is used, so nothing names it:
        // what uses the class or one of its members depends on the hook. Which use runs it is a guess.
        if ($frameworkHooks && $current['kind'] === 'method' && FrameworkHookMethods::isHookName((string) $current['display_name'])) {
            foreach (FrameworkHookMethods::among($this->pdo, $projectId, [(string) $current['id']]) as $class) {
                foreach ($this->impactEdges($projectId, $class, $edgeKinds, $minimumRank, $edgesTruncated, withMembers: true) as $edge) {
                    if ($edge['source_id'] !== $current['id']) {
                        $edges[] = StandInExpansion::downgradeCertain($edge);
                    }
                }
            }
        }

        return $edges;
    }

    /**
     * Raise an already recorded dependant when an alternate path of the same
     * length carries a higher confidence (max per distance) rather than
     * keeping the first-discovered, weaker value.
     *
     * @param array<string, mixed> $edge
     * @param array<array-key, int> $bestRank
     * @param list<array<string, mixed>> $dependants
     * @param array<string, int> $recordIndex
     */
    private function relaxRank(array $edge, int $distance, int $pathConfidence, array &$bestRank, array &$dependants, array $recordIndex): void
    {
        $existingIndex = $recordIndex[$edge['source_id']] ?? null;
        if ($existingIndex === null || $dependants[$existingIndex]['distance'] !== $distance + 1) {
            return;
        }
        $candidateRank = min($pathConfidence, self::CONFIDENCE_RANK[$edge['confidence']]);
        if ($candidateRank > $bestRank[$edge['source_id']]) {
            // Raise the record, the hop that justifies it (and
            // so its evidence), and the rank its own dependants
            // inherit together, so the three never disagree.
            $bestRank[$edge['source_id']] = $candidateRank;
            $dependants[$existingIndex]['path_confidence'] = self::rankName($candidateRank);
            $dependants[$existingIndex]['via'] = $this->impactHop($edge);
        }
    }

    /**
     * A newly found dependant's record, its rank and the member it was
     * reached through remembered for its own dependants.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $edge
     * @param array<string, mixed> $current
     * @param array<array-key, int> $bestRank
     * @param array<array-key, string> $throughMember
     * @return array<string, mixed>
     */
    private function recordDependant(array $node, array $edge, array $current, int $distance, int $pathConfidence, array &$bestRank, array &$throughMember): array
    {
        $bestRank[$node['id']] = min($pathConfidence, self::CONFIDENCE_RANK[$edge['confidence']]);
        $member = $throughMember[$current['id']] ?? null;
        if ($member !== null) {
            $throughMember[$node['id']] = $member;
        }

        return [
            'node' => $node,
            'distance' => $distance + 1,
            'path_confidence' => self::rankName($bestRank[$node['id']]),
            'via' => $this->impactHop($edge),
        ] + ($member === null ? [] : ['via_member' => $member]);
    }

    /**
     * What a class-like or module target contains, down two levels (a module's
     * classes and their methods), at most {@see self::MAX_MEMBERS}. Empty for
     * any other kind.
     *
     * @param array<string, mixed> $target
     * @return list<array<string, mixed>>
     */
    private function members(string $projectId, array $target): array
    {
        if (!in_array($target['kind'], ['class', 'interface', 'trait', 'enum', 'module', 'file'], true)) {
            return [];
        }
        $statement = $this->pdo->prepare(
            "SELECT target_id FROM edges WHERE project_id = :project AND source_id = :source AND kind = 'contains' ORDER BY target_id",
        );
        $members = [];
        $frontier = [(string) $target['id']];
        for ($level = 0; $level < 2 && $frontier !== []; ++$level) {
            $next = [];
            foreach ($frontier as $parent) {
                $statement->execute(['project' => $projectId, 'source' => $parent]);
                foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $child) {
                    $node = $this->node((string) $child);
                    if ($node === null) {
                        continue;
                    }
                    $members[] = $node;
                    $next[] = (string) $child;
                    if (count($members) >= self::MAX_MEMBERS) {
                        return $members;
                    }
                }
            }
            $frontier = $next;
        }

        return $members;
    }

    /**
     * The impact_analysis envelope: the dependants with their roles and
     * boundaries, tallied by distance and confidence, and the entry points
     * among them.
     *
     * Only the first truncation reason is reported. The walk stops at the
     * deadline or at the result limit, whichever comes first, and a dropped
     * edge on some hub is the reason only when neither stopped it.
     *
     * @param array<string, mixed> $project
     * @param array<string, mixed> $target
     * @param list<array<string, mixed>> $dependants
     * @param array{max_depth: int, limit: int, timeout_ms: int, visited_states: int} $bounds
     */
    private function impactEnvelope(array $project, array $target, array $dependants, array $bounds, Truncation $truncation): ResultEnvelope
    {
        // Resolve roles for all dependants in one batched pass rather than one
        // query per accepted dependant during the BFS.
        $roleMap = $this->roles(array_map(static fn(array $record): string => $record['node']['id'], $dependants));
        foreach ($dependants as &$dependant) {
            $dependant['roles'] = $roleMap[$dependant['node']['id']] ?? [];
        }
        unset($dependant);
        $boundaryMap = $this->boundaryNames(array_map(static fn(array $record): string => $record['node']['id'], $dependants));
        foreach ($dependants as &$record) {
            $record['boundaries'] = $boundaryMap[$record['node']['id']] ?? [];
        }
        unset($record);
        $byDistance = [];
        $byConfidence = ['certain' => [], 'probable' => [], 'possible' => []];
        $entryPoints = [];
        $evidence = [];
        foreach ($dependants as $record) {
            $byDistance[$record['distance']][] = $record;
            $byConfidence[$record['path_confidence']][] = $record['node'];
            if ($this->isEntryPoint($record)) {
                $entryPoints[] = $record;
            }
            if ($record['via']['evidence'] !== null) {
                $evidence[] = ['dependant_id' => $record['node']['id']] + $record['via']['evidence'];
            }
        }
        ksort($byDistance, SORT_NUMERIC);
        return new ResultEnvelope(
            $project['id'],
            $project['active_scan_id'],
            sprintf('Found %d potential static dependant%s within depth %d.', count($dependants), count($dependants) === 1 ? '' : 's', $bounds['max_depth']),
            [
                'target' => $target,
                'dependants' => array_map(
                    static fn(array $r): array => ['node' => $r['node'], 'distance' => $r['distance'], 'path_confidence' => $r['path_confidence'], 'via' => $r['via']]
                        + (isset($r['via_member']) ? ['via_member' => $r['via_member']] : []),
                    $dependants,
                ),
                'counts' => [
                    'by_distance' => array_map('count', $byDistance),
                    'by_confidence' => array_map('count', $byConfidence),
                ],
                'entry_points' => $entryPoints,
                'bounds' => $bounds + ['truncation_reason' => $truncation->reasons()[0] ?? null],
            ],
            $evidence,
            ['Impact is a conservative static blast radius; it does not guarantee that a dependant will break.'],
            $truncation->any(),
        );
    }

    /**
     * Edge kinds that count as a dependency for impact, a wider set than flow.
     *
     * @param list<string> $edgeKinds
     * @param bool $withMembers also the edges into what `$targetId` contains
     * @return list<array<string, mixed>>
     */
    private function impactEdges(string $projectId, string $targetId, array $edgeKinds, int $minimumConfidence, bool &$truncated = false, bool $withMembers = false): array
    {
        $placeholders = implode(',', array_fill(0, count($edgeKinds), '?'));
        $into = $withMembers
            ? "(e.target_id = ? OR e.target_id IN (SELECT target_id FROM edges WHERE project_id = e.project_id AND kind = 'contains' AND source_id = ?)) "
            : 'e.target_id = ? ';
        $statement = $this->pdo->prepare(
            'SELECT e.*, f.relative_path, source.display_name AS source_name, target.display_name AS target_name ' .
            'FROM edges e JOIN nodes source ON source.id = e.source_id JOIN nodes target ON target.id = e.target_id ' .
            'LEFT JOIN files f ON f.id = e.file_id WHERE e.project_id = ? AND ' . $into .
            sprintf('AND e.kind IN (%s) ', $placeholders) .
            "AND CASE e.confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
            'ORDER BY CASE e.confidence WHEN \'certain\' THEN 3 WHEN \'probable\' THEN 2 ELSE 1 END DESC, e.kind, e.id LIMIT 501',
        );
        $statement->execute([$projectId, $targetId, ...($withMembers ? [$targetId] : []), ...$edgeKinds, $minimumConfidence]);
        $rows = $statement->fetchAll();
        if (count($rows) > 500) {
            $truncated = true;
            $rows = array_slice($rows, 0, 500);
        }
        return $rows;
    }

    /**
     * One breadth-first step outward, carrying the weakest confidence seen along the path.
     *
     * @param array<string, mixed> $edge @return array<string, mixed>
     */
    private function impactHop(array $edge): array
    {
        $step = self::hopStep($edge['kind']);

        return [
            'edge_id' => $edge['id'], 'kind' => $edge['kind'], 'step' => $step, 'source_id' => $edge['source_id'],
            'target_id' => $edge['target_id'], 'origin' => $edge['origin'], 'confidence' => $edge['confidence'],
            'attributes' => self::decode($edge['attributes_json']),
            'explanation' => $step === 'identity'
                ? self::identityExplanation($edge)
                : sprintf('%s depends through --%s (%s, %s)--> %s', $edge['source_name'], $edge['kind'], $edge['confidence'], $edge['origin'], $edge['target_name']),
            'evidence' => $edge['relative_path'] === null ? null : [
                'path' => $edge['relative_path'], 'start_line' => $edge['start_line'], 'end_line' => $edge['end_line'],
            ],
        ];
    }

    /**
     * Whether a dependant is a way into the system, by the definition the briefs share.
     *
     * @param array<string, mixed> $record
     */
    private function isEntryPoint(array $record): bool
    {
        return EntryPointCriteria::matches((string) $record['node']['kind'], array_column($record['roles'], 'role'));
    }

    /**
     * The confidence name for a rank in {@see self::CONFIDENCE_RANK}.
     *
     * @param int $rank 1 (possible) to 3 (certain)
     */
    private static function rankName(int $rank): string
    {
        return (string) array_search($rank, self::CONFIDENCE_RANK, true);
    }
}
