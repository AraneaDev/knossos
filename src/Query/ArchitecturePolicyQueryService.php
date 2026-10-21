<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Result\ResultEnvelope;
use Knossos\Scanner\Protocol\ConfidenceRank;
use Knossos\Store\EnrichedFacts;
use Knossos\Store\SharedNodeKinds;
use Throwable;

/**
 * Evaluates declared architecture policies and budgets against the graph.
 *
 * Answers whether a relationship crosses a boundary it should not, and whether a
 * change breaches a budget relative to a baseline snapshot. Violations carry the
 * offending edge and its evidence, because a policy failure a developer cannot
 * locate is a failure they will disable.
 */
final readonly class ArchitecturePolicyQueryService extends AbstractArchitectureQueryService
{
    /**
     * The edge budget for a full policy check, wherever one runs.
     *
     * A policy check that stops early can report only the violations it
     * reached, so every entry point must examine the same slice of the graph.
     * They did not: `check_architecture` and the quality gate scanned up to
     * 100,000 edges while `review_diff` and the CLI's default scanned 20,000.
     * On this repository's own graph the 20,000-edge check covered 64% of the
     * policy-relevant edges, reported itself truncated, and still exited 0, so
     * a violation in the rest would have passed CI. One constant keeps the
     * budgets from drifting apart again.
     */
    public const DEFAULT_MAX_EDGES = QueryBounds::MAX_EDGES;

    /**
     * Targets a policy may name besides a boundary: `@unassigned` is project
     * code in no boundary, `@external` is vendor code and builtins (the
     * `external_*` stand-ins, and packages a worker marks external, such as an
     * npm import), which never join one.
     */
    public const RESERVED_TARGETS = ProjectConfigurationLoader::RESERVED_BOUNDARY_NAMES;

    /**
     * Evaluate declared policies, returning each violation with the edge that breaches it.
     *
     * `$sourceFiles` scopes the check to edges whose source component is
     * declared in one of those project-relative files. A caller that only
     * wants those files' violations then pays for their edges alone, and
     * the edge and time limits (and `truncated`) speak for that scope rather
     * than for the whole project. Empty checks every edge.
     *
     * `$touchingIds` (at most 2,000; internal, no tool exposes it) narrows the
     * check the same way to the edges with either end among those components,
     * so a review can count the violations touching a change exactly rather
     * than filter the first page of the whole project's.
     *
     * Null `$policies` checks the ones the project declares in its
     * `knossos.json`, read live; a project that declares none, or whose file
     * cannot be read, is refused rather than reported as having no violations.
     *
     * `$include` (internal, no tool exposes it) keeps an edge in the check
     * only when it returns true for the edge's row (`source_id`, `target_id`,
     * `kind`). An edge it drops still counts against the edge budget but is
     * neither counted nor listed, so `violation_count` is exact for the edges
     * kept however many the rest would have filled the listing with.
     *
     * @param list<array<string, mixed>>|null $policies
     * @param list<string> $sourceFiles
     * @param list<string> $touchingIds
     * @param (Closure(array<string, mixed>): bool)|null $include
     */
    public function checkArchitecture(string $projectId, ?array $policies, string $minConfidence = 'possible', int $limit = 100, int $maxEdges = self::DEFAULT_MAX_EDGES, int $timeoutMs = 1000, array $sourceFiles = [], array $touchingIds = [], ?Closure $include = null): ResultEnvelope
    {
        $project = $this->project($projectId);
        self::assertLimit($limit);
        $confidenceRank = $this->confidenceQueryBounds($maxEdges, $timeoutMs, $minConfidence);
        $policies ??= self::declaredPolicies((string) $project['root_realpath']);
        $compiled = $this->compilePolicies($projectId, self::validatePolicies($policies));
        $allKinds = array_values(array_unique(array_merge([], ...array_column($compiled, 'edge_kinds'))));
        sort($allKinds, SORT_STRING);

        $deadline = $this->now() + ($timeoutMs * 1_000_000);
        $statement = $this->policyEdges($projectId, $allKinds, $confidenceRank[$minConfidence], $maxEdges, $sourceFiles, $touchingIds);
        // Streamed rather than collected: a gate asks for the largest bound the
        // checker accepts, and holding that many joined rows exhausted a 128 MB
        // limit. Memory is now the boundary map plus the violations kept, both
        // bounded by the graph's shape and the result limit rather than by the
        // edge count.
        $boundaries = $this->projectBoundaryNames($projectId);

        $truncation = new Truncation();
        $violations = [];
        $evidence = [];
        $violationCount = 0;
        $edgesExamined = 0;
        while (($edge = $statement->fetch()) !== false) {
            if ($edgesExamined >= $maxEdges) {
                $truncation->add('edge_limit');
                break;
            }
            ++$edgesExamined;
            if ($this->now() > $deadline) {
                $truncation->add('time_limit');
                break;
            }
            if ($include !== null && !$include($edge)) {
                continue;
            }
            $sourceBoundaryIds = array_column($boundaries[$edge['source_id']] ?? [], 'id');
            $targetBoundaryIds = array_column($boundaries[$edge['target_id']] ?? [], 'id');
            $effectiveTargets = $targetBoundaryIds !== [] ? $targetBoundaryIds
                : [str_starts_with((string) $edge['target_kind'], SharedNodeKinds::EXTERNAL_PREFIX) || (int) $edge['target_external'] === 1 ? '@external' : '@unassigned'];
            foreach ($compiled as $policy) {
                $reasons = self::violationReasons($policy, (string) $edge['kind'], $sourceBoundaryIds, $effectiveTargets);
                if ($reasons === []) {
                    continue;
                }
                // Keep an exact count past the collection limit so callers (e.g.
                // quality_gate) can compare a budget against the true violation
                // total rather than the capped, collected subset.
                ++$violationCount;
                if (count($violations) >= $limit) {
                    $truncation->add('result_limit');
                    continue;
                }
                $violations[] = self::violationRecord($policy['id'], $edge, $boundaries, $reasons);
                if ($edge['relative_path'] !== null && count($evidence) < $limit) {
                    $evidence[] = [
                        'policy_id' => $policy['id'], 'edge_id' => $edge['id'], 'path' => $edge['relative_path'],
                        'start_line' => $edge['start_line'], 'end_line' => $edge['end_line'],
                    ];
                }
            }
        }

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            self::summaryText($violationCount, count($violations), $truncation->reasons()),
            [
                'violations' => $violations,
                'policies_evaluated' => array_map(static fn(array $policy): array => [
                    'id' => $policy['id'], 'from_boundary_id' => $policy['from_id'], 'allow_target_ids' => $policy['allow'],
                    'deny_target_ids' => $policy['deny'], 'edge_kinds' => $policy['edge_kinds'],
                ], $compiled),
                'bounds' => [
                    'limit' => $limit, 'max_edges' => $maxEdges, 'timeout_ms' => $timeoutMs,
                    'edges_examined' => $edgesExamined, 'violation_count' => $violationCount,
                    'truncation_reasons' => $truncation->reasons(),
                ],
            ],
            $evidence,
            ['Policy violations are static graph findings; runtime behavior and dynamic dependencies may differ.'],
            $truncation->any(),
        );
    }

    /**
     * The validated policies with their boundary references resolved to ids;
     * the reserved targets stay as they are.
     *
     * @param list<array{id: string, from: string, allow: list<string>, deny: list<string>, kinds: list<string>}> $shapes
     * @return list<array{id: string, from_id: string, allow: list<string>, deny: list<string>, edge_kinds: list<string>}>
     */
    private function compilePolicies(string $projectId, array $shapes): array
    {
        $references = $this->boundaryReferences($projectId);
        $resolve = static fn(string $value): string => in_array($value, self::RESERVED_TARGETS, true) ? $value : $references->resolve($value);
        $compiled = [];
        foreach ($shapes as ['id' => $id, 'from' => $from, 'allow' => $allow, 'deny' => $deny, 'kinds' => $kinds]) {
            $compiled[] = [
                'id' => $id,
                'from_id' => $references->resolve($from),
                'allow' => array_values(array_unique(array_map($resolve, $allow))),
                'deny' => array_values(array_unique(array_map($resolve, $deny))),
                'edge_kinds' => $kinds,
            ];
        }

        return $compiled;
    }

    /**
     * Why one edge breaks one policy: a denied target it reaches, or a target
     * outside an allow list (an edge within the policy's own boundary is always
     * allowed). Empty when the policy does not govern the edge or it complies.
     *
     * @param array{id: string, from_id: string, allow: list<string>, deny: list<string>, edge_kinds: list<string>} $policy
     * @param list<string> $sourceBoundaryIds
     * @param list<string> $effectiveTargets the target's boundary ids, else `@external` or `@unassigned`
     * @return list<array{type: string, targets: list<string>}>
     */
    private static function violationReasons(array $policy, string $edgeKind, array $sourceBoundaryIds, array $effectiveTargets): array
    {
        if (!in_array($policy['from_id'], $sourceBoundaryIds, true) || !in_array($edgeKind, $policy['edge_kinds'], true)) {
            return [];
        }
        $reasons = [];
        $denied = array_values(array_intersect($effectiveTargets, $policy['deny']));
        if ($denied !== []) {
            $reasons[] = ['type' => 'denied_target', 'targets' => $denied];
        }
        $internal = in_array($policy['from_id'], $effectiveTargets, true);
        if ($policy['allow'] !== [] && !$internal && array_intersect($effectiveTargets, $policy['allow']) === []) {
            $reasons[] = ['type' => 'target_not_allowed', 'targets' => $effectiveTargets];
        }

        return $reasons;
    }

    /**
     * One violation as the check lists it: the policy, the offending edge,
     * both ends with their boundaries, and why.
     *
     * @param array<string, mixed> $edge a row of {@see self::policyEdges()}
     * @param array<string, list<array{id: string, name: string, source: string}>> $boundaries each component's boundaries by id
     * @param list<array{type: string, targets: list<string>}> $reasons
     * @return array<string, mixed>
     */
    private static function violationRecord(string $policyId, array $edge, array $boundaries, array $reasons): array
    {
        return [
            'policy_id' => $policyId,
            'relationship' => [
                'id' => $edge['id'], 'kind' => $edge['kind'], 'confidence' => $edge['confidence'],
                'origin' => $edge['origin'], 'source_id' => $edge['source_id'], 'target_id' => $edge['target_id'],
            ],
            'source' => ['id' => $edge['source_id'], 'kind' => $edge['source_kind'], 'canonical_name' => $edge['source_name']],
            'target' => ['id' => $edge['target_id'], 'kind' => $edge['target_kind'], 'canonical_name' => $edge['target_name']],
            'source_boundaries' => $boundaries[$edge['source_id']] ?? [],
            'target_boundaries' => $boundaries[$edge['target_id']] ?? [],
            'reasons' => $reasons,
        ];
    }

    /**
     * The check's one-line summary: the total found, how many are listed when
     * fewer, and whether a bound stopped the search.
     *
     * Reports the total found, not the page returned. Announcing "Found 4
     * declared architecture policy violations" over a listing capped at 4,
     * while bounds.violation_count said 322, understated the finding by two
     * orders of magnitude to anyone reading only the summary.
     *
     * @param list<string> $truncationReasons
     */
    private static function summaryText(int $violationCount, int $listed, array $truncationReasons): string
    {
        $summary = sprintf('Found %d declared architecture policy violation%s.', $violationCount, $violationCount === 1 ? '' : 's');
        if ($violationCount > $listed) {
            $summary .= sprintf(' Listing the first %d; raise limit to see more.', $listed);
        }
        if ($truncationReasons !== [] && !in_array('result_limit', $truncationReasons, true)) {
            $summary .= sprintf(' The search was truncated (%s), so violations beyond that bound are not counted.', implode(', ', $truncationReasons));
        }

        return $summary;
    }

    /**
     * The policies `$root/knossos.json` declares; refused when there are none
     * or the file cannot be read, so a broken file never reads as no rules.
     *
     * @return list<array<string, mixed>>
     */
    private static function declaredPolicies(string $root): array
    {
        try {
            $configuration = ProjectConfigurationLoader::load($root, [$root]);
        } catch (Throwable $error) {
            throw new InvalidArgumentException(sprintf('The policies in %s/knossos.json (or knossos.jsonc) could not be read: %s', $root, $error->getMessage()), 0, $error);
        }
        if ($configuration->policies === []) {
            throw new InvalidArgumentException(sprintf('%s declares no policies; pass policies to check.', $configuration->path ?? $root . '/knossos.json'));
        }

        return $configuration->policies;
    }

    /**
     * The structural rules of a policy list, checked without the database.
     *
     * Public so a caller can refuse a malformed list before doing any work
     * (the MCP layer runs it before refresh_if_stale rescans); the check
     * itself compiles through this, so the rules exist once.
     *
     * @param array<mixed> $policies
     * @return list<array{id: string, from: string, allow: list<string>, deny: list<string>, kinds: list<string>}>
     */
    public static function validatePolicies(array $policies): array
    {
        if (!array_is_list($policies) || $policies === [] || count($policies) > 50) {
            throw new InvalidArgumentException('policies must contain between 1 and 50 declarations.');
        }
        $shapes = [];
        $policyIds = [];
        foreach ($policies as $policy) {
            if (!is_array($policy)) {
                throw new InvalidArgumentException('Each policy must be an object.');
            }
            $unknown = array_diff(array_keys($policy), ['id', 'from_boundary', 'allow_targets', 'deny_targets', 'edge_kinds']);
            if ($unknown !== []) {
                throw new InvalidArgumentException('Policy contains unknown fields: ' . implode(', ', $unknown));
            }
            $id = $policy['id'] ?? null;
            $from = $policy['from_boundary'] ?? null;
            if (!is_string($id) || trim($id) === '' || mb_strlen($id) > 100) {
                throw new InvalidArgumentException('Policy id must be a non-empty string of at most 100 characters.');
            }
            if (isset($policyIds[$id])) {
                throw new InvalidArgumentException('Policy ids must be unique: ' . $id);
            }
            $policyIds[$id] = true;
            if (!is_string($from) || trim($from) === '') {
                throw new InvalidArgumentException('Policy from_boundary must be a non-empty boundary ID or name.');
            }
            $allow = self::policyList($policy, 'allow_targets');
            $deny = self::policyList($policy, 'deny_targets');
            if ($allow === [] && $deny === []) {
                throw new InvalidArgumentException('Policy must declare allow_targets or deny_targets.');
            }
            $kinds = self::policyList($policy, 'edge_kinds');
            $kinds = $kinds === [] ? self::POLICY_EDGE_KINDS : array_values(array_unique($kinds));
            if (array_diff($kinds, self::IMPACT_EDGE_KINDS) !== []) {
                throw new InvalidArgumentException('Policy edge_kinds contains an unsupported dependency relationship.');
            }
            $shapes[] = ['id' => $id, 'from' => $from, 'allow' => $allow, 'deny' => $deny, 'kinds' => $kinds];
        }

        return $shapes;
    }

    /**
     * The edges a policy check walks, in a stable order, one past the edge budget.
     *
     * @param list<string> $kinds
     * @param list<string> $sourceFiles empty for every edge, else only edges whose source is declared in one of them
     * @param list<string> $touchingIds empty for every edge, else only edges with either end among these components
     */
    private function policyEdges(string $projectId, array $kinds, int $confidenceRank, int $maxEdges, array $sourceFiles, array $touchingIds = []): \PDOStatement
    {
        if (count($sourceFiles) > 500) {
            throw new InvalidArgumentException('source_files must name at most 500 files.');
        }
        if (count($touchingIds) > 2000) {
            throw new InvalidArgumentException('touching_ids must name at most 2000 components.');
        }
        $placeholders = static fn(array $values): string => implode(',', array_fill(0, count($values), '?'));
        // Only the columns the evaluation and the violation record read. `e.*`
        // dragged attributes_json along for every edge, which is most of the
        // width of a row and is never looked at here.
        $statement = $this->pdo->prepare(
            'SELECT e.id, e.kind, e.source_id, e.target_id, e.confidence, e.origin, e.start_line, e.end_line, ' .
            'f.relative_path, source.kind AS source_kind, source.canonical_name AS source_name, ' .
            'target.kind AS target_kind, target.canonical_name AS target_name, ' .
            // An npm package the TypeScript worker imports is a `package` node marked external, vendor code all the same.
            "json_extract(target.attributes_json, '$.external') AS target_external FROM edges e " .
            'JOIN nodes source ON source.id = e.source_id JOIN nodes target ON target.id = e.target_id ' .
            // A policy judges native code by native facts: an enricher's edge is a guess, and
            // judging it would let an enricher's output fail a quality gate.
            'LEFT JOIN files f ON f.id = e.file_id WHERE e.project_id = ? AND ' . EnrichedFacts::excludeSql('e') . ' ' .
            sprintf('AND e.kind IN (%s) ', $placeholders($kinds)) .
            'AND ' . ConfidenceRank::atLeast('e.confidence') . ' ' .
            ($sourceFiles === [] ? '' : sprintf(
                'AND source.file_id IN (SELECT id FROM files WHERE project_id = ? AND relative_path IN (%s)) ',
                $placeholders($sourceFiles),
            ) . 'AND ' . SharedNodeKinds::ownedByFileSql('source.kind') . ' ') .
            // One JSON parameter however many ids: a list of 2,000 bound twice
            // would pass SQLite's 999-variable floor.
            ($touchingIds === [] ? '' : 'AND (e.source_id IN (SELECT value FROM json_each(?)) OR e.target_id IN (SELECT value FROM json_each(?))) ') .
            'ORDER BY e.source_id, e.target_id, e.kind, e.id LIMIT ?',
        );
        $scope = $sourceFiles === [] ? [] : [$projectId, ...$sourceFiles];
        if ($touchingIds !== []) {
            $touching = json_encode(array_values($touchingIds), JSON_THROW_ON_ERROR);
            $scope = [...$scope, $touching, $touching];
        }
        // bindValue keeps the integers integers; execute([...]) would send them as text.
        foreach ([$projectId, ...$kinds, $confidenceRank, ...$scope, $maxEdges + 1] as $index => $value) {
            $statement->bindValue($index + 1, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $statement->execute();

        return $statement;
    }

    /**
     * Validate and normalise the policy definitions.
     *
     * @param array<string, mixed> $policy @return list<string>
     */
    private static function policyList(array $policy, string $key): array
    {
        if (!array_key_exists($key, $policy)) {
            return [];
        }
        $values = $policy[$key];
        if (!is_array($values) || !array_is_list($values) || count($values) > 50) {
            throw new InvalidArgumentException(sprintf('Policy %s must be a list of at most 50 values.', $key));
        }
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 200) {
                throw new InvalidArgumentException(sprintf('Policy %s values must be non-empty strings of at most 200 characters.', $key));
            }
        }
        return array_values(array_unique($values));
    }
}
