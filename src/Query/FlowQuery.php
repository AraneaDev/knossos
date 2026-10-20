<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use Knossos\Enrichment\Profiles;
use Knossos\Result\ResultEnvelope;
use Knossos\Store\ChunkedInQuery;

/**
 * How one component can reach another: `explain_flow`'s bounded path search.
 *
 * Walks forward along the flow relationships only, a narrower set than the
 * dependency kinds impact and cycles read, because a flow is meant to read as
 * a route a request could take. Every walk is bounded by depth, by the states
 * it may queue, and by a deadline, and a cut walk says which bound cut it.
 */
final readonly class FlowQuery extends AbstractArchitectureQueryService
{
    /**
     * States explain_flow may queue. It also bounds the states visited, since
     * a state is visited at most once and only after it was queued, so no
     * separate visit bound is needed.
     */
    private const MAX_FLOW_STATES = 10_000;

    /**
     * The deepest path a caller may ask for. Twelve, not eight: a scan reaches
     * its worker process in about nine hops (ProjectScanService::scan to
     * WorkerProcessSupervisor::start), and a cap of eight could never answer
     * that question however long the search ran.
     */
    public const MAX_DEPTH = 12;

    /**
     * The search budget when the caller names none. 1000 ms gave up on a
     * seven-hop search after 463 states that 5000 ms answered.
     */
    public const DEFAULT_TIMEOUT_MS = 3000;

    /**
     * Paths by which one component can reach another, with the edges that justify each hop.
     *
     * Reads as its phases: validate the bounds, resolve both endpoints to one
     * component each, search, rank the routes found, and report them.
     *
     * @param list<string> $edgeKinds
     * @param bool $bothDirections when `from` reaches `to` by no route, look the other way too (within a timeout of
     *                             its own): the answer then runs from `to` to `from`, and `reversed` says so. A
     *                             forward search a bound cut is answered as it is: it may have had a route left
     * @param ?string $profile pins every path to this profile: no enricher edge tagged only with other profiles is
     *                         followed. Without it, the first profiled edge a path crosses binds that path
     */
    public function explainFlow(string $projectId, string $from, string $to, int $maxDepth = 6, int $maxPaths = 5, array $edgeKinds = [], string $minConfidence = 'possible', int $timeoutMs = self::DEFAULT_TIMEOUT_MS, bool $bothDirections = false, ?string $profile = null): ResultEnvelope
    {
        $project = $this->project($projectId);
        [$confidenceRank, $edgeKinds] = $this->flowArguments($maxDepth, $maxPaths, $edgeKinds, $minConfidence, $timeoutMs, $profile);

        $fromCandidates = $this->resolve($projectId, $from);
        $toCandidates = $this->resolve($projectId, $to);
        if (count($fromCandidates) !== 1 || count($toCandidates) !== 1) {
            return self::unresolvedFlow($project, $from, $to, $fromCandidates, $toCandidates, $profile);
        }
        $bounds = [$maxDepth, $maxPaths, $edgeKinds, $confidenceRank[$minConfidence], $timeoutMs, $profile];
        $flow = $this->flowBetween($project, $fromCandidates[0], $toCandidates[0], ...$bounds);
        if (!$bothDirections) {
            return $flow;
        }
        if ($flow->data['paths'] === [] && !$flow->truncated) {
            // The answer is the other direction's, but the branches the forward search cut off still happened.
            $back = $this->flowBetween($project, $toCandidates[0], $fromCandidates[0], ...[...$bounds, $flow->data['profile_filter']['pruned_branches']]);
            if ($back->data['paths'] !== []) {
                return $back->withData(['reversed' => true] + $back->data);
            }
        }

        return $flow->withData(['reversed' => false] + $flow->data);
    }

    /**
     * The routes from `$source` to `$target`, searched and ranked within the bounds, as the envelope reports them.
     *
     * @param array<string, mixed> $project
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     * @param list<string> $edgeKinds
     * @param ?int $forwardPruned on the reverse search of `both_directions`, the branches the forward search cut off
     */
    private function flowBetween(array $project, array $source, array $target, int $maxDepth, int $maxPaths, array $edgeKinds, int $minimumRank, int $timeoutMs, ?string $profile, ?int $forwardPruned = null): ResultEnvelope
    {
        $projectId = (string) $project['id'];
        $endpointTruncated = false;
        $sourceSet = $this->flowEndpointSet($projectId, $source, $endpointTruncated);
        $targetSet = $this->flowEndpointSet($projectId, $target, $endpointTruncated);
        $deadline = $this->now() + ($timeoutMs * 1_000_000);
        $truncation = new Truncation();
        $search = $this->searchFlows($projectId, $sourceSet, $targetSet, $maxDepth, $maxPaths, $edgeKinds, $minimumRank, $deadline, $truncation, $profile);
        if ($endpointTruncated) {
            $truncation->add('endpoint_expansion_limit');
        }
        $paths = self::rankedPaths($search['paths'], $maxPaths, $truncation);

        return self::flowEnvelope(
            $project,
            ['from' => $source, 'to' => $target, 'paths' => $paths],
            ['max_depth' => $maxDepth, 'max_paths' => $maxPaths, 'timeout_ms' => $timeoutMs, 'visited_states' => $search['visited'], 'queued_states' => $search['queued']],
            $truncation,
            ['requested' => $profile, 'pruned_branches' => $search['pruned']] + ($forwardPruned === null ? [] : ['forward_pruned_branches' => $forwardPruned]),
        );
    }

    /**
     * The validated bounds of an explain_flow call, with the confidence ranks
     * and the flow edge kinds the search then reads.
     *
     * @param list<string> $edgeKinds
     * @return array{0: array<string, int>, 1: list<string>}
     */
    private function flowArguments(int $maxDepth, int $maxPaths, array $edgeKinds, string $minConfidence, int $timeoutMs, ?string $profile): array
    {
        if ($maxDepth < 1 || $maxDepth > self::MAX_DEPTH) {
            throw new InvalidArgumentException(sprintf('max_depth must be between 1 and %d.', self::MAX_DEPTH));
        }
        if ($maxPaths < 1 || $maxPaths > 20) {
            throw new InvalidArgumentException('max_paths must be between 1 and 20.');
        }
        $confidenceRank = $this->confidenceThreshold($timeoutMs, $minConfidence);
        self::validateProfile($profile);

        return [$confidenceRank, self::selectedEdgeKinds($edgeKinds, self::FLOW_EDGE_KINDS, 'flow')];
    }

    /** Refuse a `profile` argument that no enricher edge could carry; null pins nothing and passes. */
    public static function validateProfile(?string $profile): void
    {
        if ($profile !== null && !Profiles::isName($profile)) {
            throw new InvalidArgumentException('profile must be 1 to 64 letters, digits, "_", "." or "-".');
        }
    }

    /**
     * The answer when an endpoint resolved to no component or to several.
     *
     * Names the endpoint that failed, and says which way it failed: with two
     * endpoints, "one unambiguous component each" leaves a caller guessing
     * both which end to fix and whether to search for it or narrow it.
     *
     * @param array<string, mixed> $project
     * @param list<array<string, mixed>> $fromCandidates
     * @param list<array<string, mixed>> $toCandidates
     */
    private static function unresolvedFlow(array $project, string $from, string $to, array $fromCandidates, array $toCandidates, ?string $profile): ResultEnvelope
    {
        $unmatched = [];
        if ($fromCandidates === []) {
            $unmatched[] = $from;
        }
        if ($toCandidates === []) {
            $unmatched[] = $to;
        }
        $warnings = [];
        if ($unmatched !== []) {
            $warnings[] = self::UNMATCHED_ADVICE;
        }
        if (count($fromCandidates) > 1 || count($toCandidates) > 1) {
            $warnings[] = self::AMBIGUOUS_ADVICE;
        }

        return new ResultEnvelope(
            $project['id'],
            $project['active_scan_id'],
            $unmatched === []
                ? 'Flow endpoints require one unambiguous component each.'
                : sprintf('No component matched %s.', implode(' or ', array_map(static fn(string $name): string => sprintf('"%s"', $name), $unmatched))),
            [
                'from' => ['query' => $from, 'candidates' => $fromCandidates],
                'to' => ['query' => $to, 'candidates' => $toCandidates],
                'paths' => [],
                'profile_filter' => ['requested' => $profile, 'pruned_branches' => 0],
            ],
            [],
            $warnings,
        );
    }

    /**
     * The breadth-first search from the source set to the target set, one
     * witness kept per distinct route.
     *
     * Routes are keyed by signature: the cap and the max_paths budget are both
     * about distinct ROUTES, and two call sites between the same pair of
     * symbols are one route written twice. Counting the copies let a hot pair
     * spend the whole search before any other route was reached.
     *
     * Each queued path also carries its profile candidates (null while no
     * profiled edge bound it) and the indexes of its unprofiled hops. An edge
     * whose profiles the candidates exclude is not followed, and the branch it
     * would have started is counted in `pruned`: profiles only remove states.
     *
     * @param array<string, array<string, mixed>> $sourceSet
     * @param array<string, array<string, mixed>> $targetSet
     * @param list<string> $edgeKinds
     * @return array{paths: array<string, array<string, mixed>>, visited: int, queued: int, pruned: int}
     */
    private function searchFlows(string $projectId, array $sourceSet, array $targetSet, int $maxDepth, int $maxPaths, array $edgeKinds, int $minimumRank, int $deadline, Truncation $truncation, ?string $profile): array
    {
        // A head index instead of array_shift, which re-indexes the whole queue
        // on every pop; each popped slot is unset so its path copy is released.
        $queue = [];
        $head = 0;
        foreach ($sourceSet as $start) {
            $queue[] = [[$start], [], [$start['id'] => true], $profile === null ? null : [$profile], []];
        }
        // Every queued state carries a copy of its path, so the visit bound
        // alone let one wide node queue far more than it could ever visit.
        $queued = count($queue);
        $paths = [];
        $visited = 0;
        $pruned = 0;
        // Each edge's profiles, read once per search: a wide node is expanded once per path that reaches it.
        $edgeProfiles = [];
        $flowEdgesTruncated = false;
        $candidateCap = $maxPaths * 20;
        while (isset($queue[$head]) && count($paths) < $candidateCap) {
            if ($this->now() > $deadline) {
                $truncation->add('time_limit');
                break;
            }
            [$nodes, $hops, $seen, $candidates, $unprofiled] = $queue[$head];
            unset($queue[$head]);
            ++$head;
            ++$visited;
            if (count($hops) >= $maxDepth) {
                continue;
            }
            $last = $nodes[array_key_last($nodes)];
            $edges = [
                ...$this->flowEdges($projectId, $last['id'], $edgeKinds, $minimumRank, $flowEdgesTruncated),
                ...$this->implementationSteps($projectId, $last['id'], $edgeKinds, $minimumRank),
            ];
            foreach ($edges as $index => $edge) {
                // Up to 500 edges per node: the clock is read on the first and
                // every 64th, so one wide node cannot run past the deadline.
                if ($index % 64 === 0 && $this->now() > $deadline) {
                    $truncation->add('time_limit');
                    break 2;
                }
                // The goal may be reached even when it is already in $seen: the
                // source is pre-seeded, so a self-flow (from == to) depends on
                // matching the target before the visited-guard skips it.
                $isTarget = isset($targetSet[$edge['target_id']]);
                if (isset($seen[$edge['target_id']]) && !$isTarget) {
                    continue;
                }
                if (!array_key_exists($edge['id'], $edgeProfiles)) {
                    $edgeProfiles[$edge['id']] = $edge['origin'] === 'enricher' ? self::edgeProfiles((string) $edge['attributes_json']) : null;
                }
                [$newCandidates, $unprofiledHop] = self::narrowProfiles($candidates, $edgeProfiles[$edge['id']]);
                if ($newCandidates === []) {
                    // The edge's profiles contradict the path's: no deployment takes both.
                    ++$pruned;
                    continue;
                }
                $newUnprofiled = $unprofiledHop ? [...$unprofiled, count($hops)] : $unprofiled;
                // The edge query joins the target row, so a dangling target is
                // already excluded and no lookup per edge is needed.
                $next = [
                    'id' => $edge['target_id'], 'kind' => $edge['target_kind'], 'canonical_name' => $edge['target_canonical_name'],
                    'display_name' => $edge['target_display_name'], 'confidence' => $edge['target_confidence'],
                ];
                $newNodes = [...$nodes, $next];
                $newHops = [...$hops, $edge];
                if ($isTarget) {
                    $candidate = $this->path($newNodes, $newHops, $newCandidates, $newUnprofiled);
                    $existing = $paths[$candidate['signature']] ?? null;
                    // Keep the best-scoring witness, not merely the first one
                    // found: two call sites for the same route can carry
                    // different confidence, and the answer should show the
                    // strongest evidence that route has. Its profile stays the one
                    // its own hops prove; the other witness's go to `also_in`.
                    $paths[$candidate['signature']] = $existing === null ? $candidate : self::mergedWitness($candidate, $existing);
                    if (count($paths) >= $candidateCap) {
                        $truncation->add('candidate_limit');
                        break 2;
                    }
                    continue;
                }
                if ($queued >= self::MAX_FLOW_STATES) {
                    // A full queue refuses new states but keeps searching the
                    // ones it holds: they cost no more memory, and any of them
                    // may be one hop from the target, as this edge's target
                    // check above already allows.
                    $truncation->add('queue_limit');
                    continue;
                }
                $newSeen = $seen;
                $newSeen[$next['id']] = true;
                $queue[] = [$newNodes, $newHops, $newSeen, $newCandidates, $newUnprofiled];
                ++$queued;
            }
        }
        if ($flowEdgesTruncated) {
            // A node with more than 500 outbound edges of the selected kinds had
            // some silently dropped; record it rather than claim completeness.
            $truncation->add('per_node_edge_limit');
        }

        return ['paths' => $paths, 'visited' => $visited, 'queued' => $queued, 'pruned' => $pruned];
    }

    /**
     * A path's profile candidates after it takes an edge with `$profiles`, and whether that hop is unprofiled.
     *
     * An edge without profiles (null: every non-enricher edge, and an
     * enricher edge without a valid one) leaves the candidates as they were.
     * An unbound path (null) binds to the edge's profiles; a bound one keeps
     * the profiles both share, and an empty list means they share none.
     *
     * @param ?list<string> $candidates sorted
     * @param ?list<string> $profiles the edge's, sorted
     * @return array{0: ?list<string>, 1: bool}
     */
    private static function narrowProfiles(?array $candidates, ?array $profiles): array
    {
        if ($profiles === null) {
            return [$candidates, true];
        }
        if ($candidates === null) {
            return [$profiles, false];
        }

        return [array_values(array_intersect($candidates, $profiles)), false];
    }

    /**
     * The sorted profiles an edge's attributes name, or null when they name none.
     *
     * The rule is checked again rather than trusted: a value stored before
     * the validator checked it, or carried over from such a run, that is not
     * a valid profile reads as no profile, never as one nothing matches.
     *
     * @return ?list<string>
     */
    private static function edgeProfiles(string $attributesJson): ?array
    {
        if (!str_contains($attributesJson, '"' . Profiles::ATTRIBUTE . '"')) {
            return null;
        }
        $names = Profiles::names(self::decode($attributesJson)[Profiles::ATTRIBUTE] ?? null);
        if ($names === null) {
            return null;
        }
        sort($names);

        return $names;
    }

    /**
     * One route from two witnesses of the same node sequence: the
     * best-scoring one, as before, with `profile` still the binding its own
     * hops prove. `also_in` gathers the other witness's profiles (and those it
     * had gathered), less the kept one's own; an unbound witness adds none.
     *
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private static function mergedWitness(array $candidate, array $existing): array
    {
        [$kept, $other] = self::comparePaths($candidate, $existing) < 0 ? [$candidate, $existing] : [$existing, $candidate];
        $also = array_diff([...$kept['also_in'], ...$other['also_in'], ...(array) $other['profile']], (array) $kept['profile']);
        $also = array_values(array_unique($also));
        sort($also);
        $kept['also_in'] = $also;

        return $kept;
    }

    /**
     * A candidate set as a path reports it: the one name, the sorted names, or null when unbound.
     *
     * @param ?list<string> $candidates
     * @return string|list<string>|null
     */
    private static function reportedProfile(?array $candidates): string|array|null
    {
        if ($candidates === null) {
            return null;
        }
        $names = array_values(array_unique($candidates));
        sort($names);

        return count($names) === 1 ? $names[0] : $names;
    }

    /**
     * The routes found, strongest first, trimmed to the max_paths budget.
     *
     * One entry per distinct route already; this only orders them, which
     * decides which survive the budget.
     *
     * @param array<string, array<string, mixed>> $paths
     * @return list<array<string, mixed>>
     */
    private static function rankedPaths(array $paths, int $maxPaths, Truncation $truncation): array
    {
        $paths = array_values($paths);
        usort($paths, static fn(array $left, array $right): int => self::comparePaths($left, $right));
        if (count($paths) > $maxPaths) {
            $paths = array_slice($paths, 0, $maxPaths);
            // Recorded after any time or visit reason, never in place of one:
            // a timed-out search must not report only path trimming.
            $truncation->add('path_limit');
        }

        return $paths;
    }

    /**
     * The explain_flow envelope: the routes, the evidence for each hop, and
     * the bounds that say how complete the search was.
     *
     * @param array<string, mixed> $project
     * @param array{from: array<string, mixed>, to: array<string, mixed>, paths: list<array<string, mixed>>} $flow
     * @param array{max_depth: int, max_paths: int, timeout_ms: int, visited_states: int, queued_states: int} $bounds
     * @param array{requested: ?string, pruned_branches: int, forward_pruned_branches?: int} $profileFilter the pinned profile and the
     *                                                                                                     branches a profile conflict cut off
     */
    private static function flowEnvelope(array $project, array $flow, array $bounds, Truncation $truncation, array $profileFilter): ResultEnvelope
    {
        $truncationReasons = $truncation->reasons();
        $evidence = [];
        foreach ($flow['paths'] as $pathIndex => $path) {
            foreach ($path['hops'] as $hopIndex => $hop) {
                if ($hop['evidence'] !== null) {
                    $evidence[] = ['path_index' => $pathIndex, 'hop_index' => $hopIndex] + $hop['evidence'];
                }
            }
        }
        $count = count($flow['paths']);
        $summary = $count === 0 ? 'No supported static flow was found within the configured bounds.' : sprintf('Found %d plausible static flow%s.', $count, $count === 1 ? '' : 's');
        if ($truncation->any()) {
            $summary .= sprintf(' The search was truncated (%s).', implode(', ', $truncationReasons));
        }
        $summary .= self::prunedSentence($profileFilter['pruned_branches'], '');
        $summary .= self::prunedSentence($profileFilter['forward_pruned_branches'] ?? 0, ' in the forward search');

        return new ResultEnvelope(
            $project['id'],
            $project['active_scan_id'],
            $summary,
            $flow + [
                'bounds' => $bounds + ['truncation_reason' => $truncationReasons[0] ?? null, 'truncation_reasons' => $truncationReasons],
                'profile_filter' => $profileFilter,
            ],
            $evidence,
            ['Flows are plausible statically supported paths, not proof of runtime execution.'],
            $truncation->any(),
        );
    }

    /** The summary's sentence on the branches a profile conflict cut off, or nothing when there were none. */
    private static function prunedSentence(int $pruned, string $where): string
    {
        if ($pruned === 0) {
            return '';
        }

        return sprintf(' %d %s cut off by a profile conflict%s.', $pruned, $pruned === 1 ? 'branch was' : 'branches were', $where);
    }

    /**
     * A `class`- or `interface`-kind endpoint stands for itself and its contained members:
     * `contains` is not a flow edge, so without expansion a class query can never
     * descend into the methods that actually carry calls/constructs edges.
     *
     * Expansion follows `contains` edges regardless of the query's `min_confidence`:
     * containment is structural, not a flow relationship, so it isn't filtered by the
     * same confidence threshold as calls/constructs edges.
     *
     * @param array<string, mixed> $node
     * @return array<string, array<string, mixed>> id => node row
     */
    private function flowEndpointSet(string $projectId, array $node, bool &$truncated): array
    {
        $set = [$node['id'] => $node];
        if (!in_array($node['kind'], ['class', 'interface'], true)) {
            return $set;
        }
        $statement = $this->pdo->prepare(
            'SELECT n.id, n.kind, n.canonical_name, n.display_name, n.confidence FROM edges e ' .
            'JOIN nodes n ON n.id = e.target_id WHERE e.project_id = :project AND e.source_id = :source ' .
            "AND e.kind = 'contains' ORDER BY n.canonical_name, n.id LIMIT 201",
        );
        $statement->execute(['project' => $projectId, 'source' => $node['id']]);
        $rows = $statement->fetchAll();
        if (count($rows) > 200) {
            $truncated = true;
            $rows = array_slice($rows, 0, 200);
        }
        foreach ($rows as $row) {
            $set[$row['id']] = $row;
        }
        return $set;
    }

    /**
     * Edge kinds that count as control or data flow for reachability.
     *
     * @param list<string> $edgeKinds @return list<array<string, mixed>>
     */
    private function flowEdges(string $projectId, string $sourceId, array $edgeKinds, int $minimumConfidence, bool &$truncated = false): array
    {
        $placeholders = implode(',', array_fill(0, count($edgeKinds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT e.*, f.relative_path, source.display_name AS source_name, target.display_name AS target_name, ' .
            'target.kind AS target_kind, target.canonical_name AS target_canonical_name, ' .
            'target.display_name AS target_display_name, target.confidence AS target_confidence ' .
            'FROM edges e JOIN nodes source ON source.id = e.source_id JOIN nodes target ON target.id = e.target_id ' .
            'LEFT JOIN files f ON f.id = e.file_id WHERE e.project_id = ? AND e.source_id = ? ' .
            sprintf('AND e.kind IN (%s) ', $placeholders) .
            "AND CASE e.confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
            'ORDER BY CASE e.confidence WHEN \'certain\' THEN 3 WHEN \'probable\' THEN 2 ELSE 1 END DESC, e.kind, e.id LIMIT 501',
        );
        $statement->execute([$projectId, $sourceId, ...$edgeKinds, $minimumConfidence]);
        $rows = $statement->fetchAll();
        if (count($rows) > 500) {
            $truncated = true;
            $rows = array_slice($rows, 0, 500);
        }
        return $rows;
    }

    /**
     * A call to an interface or parent method lands on that method in the
     * graph, while an implementation or override is what may run: a step from
     * the method to each of them, shaped as an edge row. Which one runs is a
     * guess, so the step is derived and probable, and only taken when calls
     * are followed and probable hops are allowed.
     *
     * @param list<string> $edgeKinds
     * @return list<array<string, mixed>>
     */
    private function implementationSteps(string $projectId, string $sourceId, array $edgeKinds, int $minimumConfidence): array
    {
        if (!in_array('calls', $edgeKinds, true) || $minimumConfidence > self::CONFIDENCE_RANK['probable']) {
            return [];
        }
        $targets = SupertypeMethods::implementationsOf($this->pdo, $projectId, [$sourceId])[$sourceId] ?? [];
        if ($targets === []) {
            return [];
        }
        $sql = 'SELECT target.id, target.kind, target.canonical_name, target.display_name, target.confidence, source.display_name AS source_name '
            . 'FROM nodes target JOIN nodes source ON source.id = ? WHERE target.project_id = ? AND target.id IN (%s) ORDER BY target.canonical_name';
        $steps = [];
        foreach (ChunkedInQuery::rows($this->pdo, $sql, $targets, [$sourceId, $projectId]) as $target) {
            $steps[] = [
                'id' => 'derived:implemented_by:' . $sourceId . '>' . $target['id'], 'kind' => 'implemented_by',
                'source_id' => $sourceId, 'target_id' => $target['id'], 'origin' => 'derived', 'confidence' => 'probable',
                'attributes_json' => '{}', 'relative_path' => null, 'start_line' => null, 'end_line' => null,
                'source_name' => $target['source_name'], 'target_name' => $target['display_name'],
                'target_kind' => $target['kind'], 'target_canonical_name' => $target['canonical_name'],
                'target_display_name' => $target['display_name'], 'target_confidence' => $target['confidence'],
            ];
        }

        return $steps;
    }

    /**
     * Rank one flow against another: strongest evidence first.
     *
     * Shared by the sort and by the choice between two witnesses of the same
     * route, so a duplicate is resolved on exactly the criteria that decide the
     * final order rather than on discovery order.
     *
     * @param array<string, mixed> $left @param array<string, mixed> $right
     */
    private static function comparePaths(array $left, array $right): int
    {
        return ($right['score']['minimum_confidence'] <=> $left['score']['minimum_confidence'])
            ?: ($left['score']['hops'] <=> $right['score']['hops'])
            ?: ($right['score']['semantic_edges'] <=> $left['score']['semantic_edges'])
            ?: ($left['signature'] <=> $right['signature']);
    }

    /**
     * The evidence path for a node, or null when it has none.
     *
     * `profile` is the binding these hops prove; `also_in` starts empty and
     * only a merge with another witness of the same node sequence fills it.
     *
     * @param list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $edges
     * @param ?list<string> $candidates the profiles the path is valid in, null when no profiled edge bound it
     * @param list<int> $unprofiled indexes of the hops whose profile is unknown
     * @return array<string, mixed>
     */
    private function path(array $nodes, array $edges, ?array $candidates, array $unprofiled): array
    {
        $rank = self::CONFIDENCE_RANK;
        $semantic = ['routes_to', 'dispatches', 'handles', 'listens_to', 'binds', 'observes', 'uses_middleware', 'calls_endpoint', 'resolves_to', 'launches'];
        $hops = [];
        $minimum = 3;
        $semanticCount = 0;
        foreach ($edges as $edge) {
            $minimum = min($minimum, $rank[$edge['confidence']]);
            if (in_array($edge['kind'], $semantic, true)) {
                ++$semanticCount;
            }
            $evidence = $edge['relative_path'] === null ? null : [
                'path' => $edge['relative_path'], 'start_line' => $edge['start_line'], 'end_line' => $edge['end_line'],
            ];
            $step = self::hopStep($edge['kind']);
            $hops[] = [
                'edge_id' => $edge['id'], 'kind' => $edge['kind'], 'step' => $step, 'source_id' => $edge['source_id'],
                'target_id' => $edge['target_id'], 'origin' => $edge['origin'], 'confidence' => $edge['confidence'],
                'attributes' => self::decode($edge['attributes_json']),
                'explanation' => $step === 'identity'
                    ? self::identityExplanation($edge)
                    : sprintf('%s --%s (%s, %s)--> %s', $edge['source_name'], $edge['kind'], $edge['confidence'], $edge['origin'], $edge['target_name']),
                'evidence' => $evidence,
            ];
        }
        return [
            'nodes' => $nodes,
            'hops' => $hops,
            'score' => ['minimum_confidence' => $minimum, 'hops' => count($hops), 'semantic_edges' => $semanticCount],
            'signature' => implode('>', array_column($nodes, 'id')),
            'profile' => self::reportedProfile($candidates),
            'unprofiled_hops' => $unprofiled,
            'also_in' => [],
        ];
    }
}
