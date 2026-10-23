<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Result\ResultEnvelope;
use PDO;
use Throwable;

/**
 * One-call architectural review of a change set: blast radius, boundary
 * policy violations touching the change, quality-gate delta, and cycles the
 * change participates in. Sections degrade to not_evaluated (with a reason)
 * instead of failing the whole call — a review with partial signal beats an
 * error. The `change` section is the one exception: it stays unguarded by
 * design, because it is the spine of the review and nothing downstream can
 * run without it.
 */
final readonly class ReviewDiffService extends AbstractArchitectureQueryService
{
    public function __construct(
        PDO $pdo,
        ?Closure $clock,
        private ChangeImpactQueryService $changeQueries,
        private ArchitecturePolicyQueryService $policyQueries,
        private QualityGateQueryService $gateQueries,
        private DependencyCycleQuery $cycleQueries,
    ) {
        parent::__construct($pdo, $clock);
    }

    /**
     * One-call review: impact, policy violations, gate delta, and cycles.
     *
     * @param list<string> $files
     * @param list<array<string, mixed>>|null $policies
     * @param array<string, int>|null $budgets
     */
    public function reviewDiff(string $projectId, ?string $baseRef = null, array $files = [], ?array $policies = null, ?array $budgets = null, ?string $baselineSnapshot = null, int $maxDepth = 4, int $limit = 100, string $minConfidence = 'possible', int $timeoutMs = 1000): ResultEnvelope
    {
        if ($files !== [] && $baseRef !== null) {
            throw new InvalidArgumentException('Provide either files or base_ref, not both.');
        }
        $project = $this->project($projectId);
        $change = $this->changeQueries->changedFilesImpact(
            $projectId,
            $files,
            $files === [],
            $files === [] ? $baseRef : null,
            $maxDepth,
            $limit,
            [],
            $minConfidence,
            $timeoutMs,
        );
        $touched = [];
        foreach ($change->data['direct_components'] as $component) {
            $touched[$component['id']] = true;
        }
        foreach ($change->data['impacted_components'] as $record) {
            $touched[$record['node']['id']] = true;
        }
        foreach ($this->fileComponents($projectId, $change->data['changed_files']) as $id) {
            $touched[$id] = true;
        }
        [$policies, $budgets, $configReason] = $this->withProjectConfig($project, $policies, $budgets);
        $touchingIds = array_map('strval', array_keys($touched));
        $policyCheck = $this->policySection($projectId, $policies, $touchingIds, $minConfidence, $timeoutMs, $configReason);
        $qualityGate = $this->gateSection($projectId, $project, $budgets, $policies, $baselineSnapshot, $baseRef, $configReason);
        $cycles = $this->cyclesSection($projectId, $touchingIds, $minConfidence, $timeoutMs);
        $sections = [$policyCheck, $qualityGate, $cycles];

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            self::summaryLine($change, $policyCheck->data, $qualityGate->data),
            [
                'change' => ['status' => 'evaluated'] + array_intersect_key($change->data, array_flip(['changed_files', 'unresolved_files', 'direct_components', 'impacted_components', 'git'])),
                'policy_check' => $policyCheck->data,
                'quality_gate' => $qualityGate->data,
                'cycles_touching_change' => $cycles->data,
                'bounds' => $change->data['bounds'] + ['cycle_scan_limit' => 100],
            ],
            array_slice([...$change->evidence, ...array_merge(...array_map(static fn(ReviewSection $section): array => $section->evidence, $sections))], 0, 100),
            array_values(array_unique([...$change->warnings, ...array_merge(...array_map(static fn(ReviewSection $section): array => $section->warnings, $sections))])),
            $change->truncated || $policyCheck->truncated || $qualityGate->truncated || $cycles->truncated,
        );
    }

    /**
     * The file components among the changed paths: a shell script or another
     * file no worker scans, which has no file row and so is no direct
     * component of the change, though the edges it is the source of
     * (`launches`) are the change's all the same.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private function fileComponents(string $projectId, array $paths): array
    {
        if ($paths === []) {
            return [];
        }
        $statement = $this->pdo->prepare(
            "SELECT id FROM nodes WHERE project_id = ? AND kind = 'file' AND file_id IS NULL " .
            'AND canonical_name IN (SELECT value FROM json_each(?)) ORDER BY id',
        );
        $statement->execute([$projectId, json_encode(array_values($paths), JSON_THROW_ON_ERROR)]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The boundary policy violations touching the change, against the
     * whole project's count.
     *
     * @param list<array<string, mixed>> $policies
     * @param list<string> $touchingIds
     */
    private function policySection(string $projectId, array $policies, array $touchingIds, string $minConfidence, int $timeoutMs, ?string $configReason): ReviewSection
    {
        if ($policies === []) {
            return ReviewSection::notEvaluated($configReason ?? 'No boundary policies declared in knossos.json or supplied.');
        }
        try {
            // Two checks: the whole project's for the total, and one scoped
            // to edges touching the change for those violations. Both
            // counts are the checks' exact `violation_count`, never the
            // length of a 100-violation page, and the touching ones are not
            // filtered out of the whole project's first page.
            $check = $this->policyQueries->checkArchitecture($projectId, $policies, $minConfidence, 100, ArchitecturePolicyQueryService::DEFAULT_MAX_EDGES, $timeoutMs);
            $touching = $touchingIds === [] ? null : $this->policyQueries->checkArchitecture(
                $projectId,
                $policies,
                $minConfidence,
                100,
                ArchitecturePolicyQueryService::DEFAULT_MAX_EDGES,
                $timeoutMs,
                touchingIds: $touchingIds,
            );
        } catch (InvalidArgumentException $error) {
            return ReviewSection::notEvaluated('Policy check failed: ' . $error->getMessage());
        }

        return new ReviewSection(
            [
                'status' => 'evaluated',
                'policies_evaluated' => count($check->data['policies_evaluated']),
                'total_violations' => (int) ($check->data['bounds']['violation_count'] ?? count($check->data['violations'])),
                'touching_violation_count' => $touching === null ? 0 : (int) ($touching->data['bounds']['violation_count'] ?? count($touching->data['violations'])),
                'violations_touching_change' => $touching === null ? [] : $touching->data['violations'],
            ],
            $touching === null ? $check->evidence : $touching->evidence,
            [...$check->warnings, ...($touching === null ? [] : $touching->warnings)],
            $check->truncated || ($touching !== null && $touching->truncated),
        );
    }

    /**
     * The quality gate against the given baseline snapshot, or the latest
     * retained one other than the active scan.
     *
     * @param array<string, mixed> $project
     * @param array<string, int> $budgets
     * @param list<array<string, mixed>> $policies
     */
    private function gateSection(string $projectId, array $project, array $budgets, array $policies, ?string $baselineSnapshot, ?string $baseRef, ?string $configReason): ReviewSection
    {
        if ($budgets === []) {
            return ReviewSection::notEvaluated($configReason ?? 'No quality budgets declared in knossos.json or supplied.');
        }
        $source = $baselineSnapshot === null ? 'previous_scan' : 'argument';
        $baselineSnapshot ??= $this->latestNonActiveSnapshot($projectId, (string) $project['active_scan_id']);
        if ($baselineSnapshot === null) {
            return ReviewSection::notEvaluated('No retained snapshot other than the active scan; scan again after the next change to establish a baseline.');
        }
        try {
            $gate = $this->gateQueries->qualityGate($projectId, $baselineSnapshot, $budgets, $policies);
            $baseline = $this->baseline($projectId, (string) $gate->data['baseline_snapshot'], $source);
        } catch (InvalidArgumentException $error) {
            return ReviewSection::notEvaluated('Quality gate failed: ' . $error->getMessage());
        }
        $warnings = $gate->warnings;
        if ($baseRef !== null && $source === 'previous_scan') {
            // The changed files come from base_ref; the gate's baseline does not.
            $warnings[] = sprintf(
                'The quality gate compared against the previous scan %s, not the graph of base_ref %s: it shows no regression since that scan. Pass baseline_snapshot with a scan of %s to gate against it.',
                $baseline['snapshot'],
                $baseRef,
                $baseRef,
            );
        }

        return new ReviewSection(
            [
                'status' => 'evaluated',
                'passed' => $gate->data['passed'],
                'checks' => $gate->data['checks'],
                'baseline_snapshot' => $gate->data['baseline_snapshot'],
                'baseline' => $baseline,
            ],
            $gate->evidence,
            $warnings,
        );
    }

    /**
     * The dependency cycles the change takes part in.
     *
     * @param list<string> $touchingIds
     */
    private function cyclesSection(string $projectId, array $touchingIds, string $minConfidence, int $timeoutMs): ReviewSection
    {
        // Filtered inside the search, before its limit of 100, so a touching
        // cycle is found however many others sort ahead of it. An empty
        // filter means every cycle, so a change touching nothing skips it.
        if ($touchingIds === []) {
            return new ReviewSection(['status' => 'evaluated', 'cycles' => []]);
        }
        try {
            $cycleResult = $this->cycleQueries->dependencyCycles($projectId, [], $minConfidence, 100, QueryBounds::MAX_NODES, QueryBounds::MAX_EDGES, $timeoutMs, touchingIds: $touchingIds);
        } catch (InvalidArgumentException $error) {
            return ReviewSection::notEvaluated('Cycle scan failed: ' . $error->getMessage());
        }

        return new ReviewSection(['status' => 'evaluated', 'cycles' => $cycleResult->data['cycles']], $cycleResult->evidence, $cycleResult->warnings, $cycleResult->truncated);
    }

    /**
     * The review's one-line summary.
     *
     * @param array<string, mixed> $policyCheck
     * @param array<string, mixed> $qualityGate
     */
    private static function summaryLine(ResultEnvelope $change, array $policyCheck, array $qualityGate): string
    {
        return sprintf(
            '%d changed file%s, %d impacted component%s, %s, gate %s.',
            count($change->data['changed_files']),
            count($change->data['changed_files']) === 1 ? '' : 's',
            count($change->data['impacted_components']),
            count($change->data['impacted_components']) === 1 ? '' : 's',
            $policyCheck['status'] === 'evaluated'
                ? sprintf('%d policy violation%s touching the change', $policyCheck['touching_violation_count'], $policyCheck['touching_violation_count'] === 1 ? '' : 's')
                : 'policies not evaluated',
            $qualityGate['status'] === 'evaluated' ? ($qualityGate['passed'] ? 'passed' : 'FAILED') : 'not evaluated',
        );
    }

    /**
     * Fill policies and budgets from the project configuration when not supplied.
     *
     * @param array<string, mixed> $project
     * @param list<array<string, mixed>>|null $policies
     * @param array<string, int>|null $budgets
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>, 2: ?string}
     */
    private function withProjectConfig(array $project, ?array $policies, ?array $budgets): array
    {
        if ($policies !== null && $budgets !== null) {
            return [$policies, $budgets, null];
        }
        $reason = null;
        try {
            $root = (string) $project['root_realpath'];
            $configuration = ProjectConfigurationLoader::load($root, [$root]);
            $policies ??= $configuration->policies;
            $budgets ??= $configuration->qualityBudgets;
        } catch (Throwable $error) {
            $reason = 'Project configuration unavailable: ' . substr($error->getMessage(), 0, 300);
            $policies ??= [];
            $budgets ??= [];
        }
        return [$policies, $budgets, $reason];
    }
    /**
     * What the gate compared against: the snapshot, whether it was asked for or
     * is the scan before the active one, and when it was taken.
     *
     * @return array{snapshot: string, source: string, captured_at: ?string, age_seconds: ?int}
     */
    private function baseline(string $projectId, string $snapshot, string $source): array
    {
        $statement = $this->pdo->prepare('SELECT captured_at FROM scan_snapshots WHERE project_id = :project AND scan_id = :scan');
        $statement->execute(['project' => $projectId, 'scan' => $snapshot]);
        $captured = $statement->fetchColumn();
        $at = is_string($captured) ? strtotime($captured) : false;

        return [
            'snapshot' => $snapshot,
            'source' => $source,
            'captured_at' => is_string($captured) ? $captured : null,
            'age_seconds' => $at === false ? null : max(0, time() - $at),
        ];
    }

    /** The most recent retained snapshot, used as the gate baseline. */

    private function latestNonActiveSnapshot(string $projectId, string $activeScanId): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT scan_id FROM scan_snapshots WHERE project_id = :project AND scan_id <> :active ' .
            'ORDER BY captured_at DESC, rowid DESC LIMIT 1',
        );
        $statement->execute(['project' => $projectId, 'active' => $activeScanId]);
        $scanId = $statement->fetchColumn();
        return is_string($scanId) ? $scanId : null;
    }
}
