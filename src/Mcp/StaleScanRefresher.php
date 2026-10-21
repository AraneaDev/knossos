<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use Knossos\Cancellation\CancellationToken;
use Knossos\Cancellation\ScanCancelledException;
use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\Drift\DriftCounts;
use Knossos\Query\RefreshPolicy;
use Knossos\Query\StalenessSnapshot;
use Knossos\Runtime\ServerEnvironment;
use Knossos\Scan\ProjectScanner;
use Knossos\Scan\ScanOptions;
use Throwable;

/**
 * What `refresh_if_stale` does before a read tool answers: probe the graph,
 * and rescan it first when it is stale and the rescan fits the budget.
 *
 * Kept apart from {@see ToolService} because it is the one step of a call
 * that writes: it decides from the staleness probe and {@see RefreshPolicy}
 * whether a scan may run inside the caller's query, and runs it.
 */
final readonly class StaleScanRefresher
{
    /**
     * @param ProjectScanner $scanner what rescans a stale project
     * @param ArchitectureQueryService $queries where the project root is read, and the services the probe and policy come from
     * @param ServerEnvironment|null $environment the allowed roots; null admits every root, as a hand-built ToolService does
     */
    public function __construct(
        private ProjectScanner $scanner,
        private ArchitectureQueryService $queries,
        private ?ServerEnvironment $environment = null,
    ) {}

    /**
     * Repair a stale graph before answering, when that fits the budget.
     *
     * Returns warnings and never throws for a scan fault: a query answerable
     * from the previous graph must still be answered. Cancellation is the one
     * exception, because the caller asked for it.
     *
     * Nothing is reported when the refresh succeeds. The staleness attached to
     * the same result already says 'fresh', so a second announcement would put
     * a line on every response for the case that needs no attention.
     *
     * Returns the verdict it probed alongside its warnings, so the enricher can
     * attach that verdict instead of running the oracle again — and returns
     * null for it whenever a scan was attempted, because the graph it probed is
     * not the graph the answer came from.
     *
     * @param array<string, mixed> $arguments
     * @return array{0: list<string>, 1: ?StalenessSnapshot}
     */
    public function refreshIfStale(array $arguments, ?CancellationToken $cancellation): array
    {
        // Normalised for the same reason string() normalises: a padded id looks
        // up no project, and silently skipping the refresh would leave the
        // caller with a stale answer their refresh_if_stale asked to avoid.
        $projectId = ToolArguments::normalized($arguments['project_id'] ?? null);
        if ($projectId === '') {
            return [[], null];
        }
        // Outside the allowed roots the probe would read files and run git, so
        // there is nothing to refresh from here. No warning: the enricher's
        // confined probe attaches 'unverified' with guidance saying why.
        if ($this->environment !== null) {
            $root = $this->queries->projectRoot($projectId);
            if ($root !== null && !$this->environment->admitsRoot($root)) {
                return [[], null];
            }
        }
        // The snapshot comes from the probe rather than being assembled here,
        // so the verdict and the scan it describes cannot be read at two
        // different moments.
        $snapshot = $this->queries->services()->stalenessProbe->snapshot($projectId);
        $staleness = $snapshot->staleness;
        if (($staleness['state'] ?? null) !== 'stale') {
            return [[], $snapshot];
        }
        // A 'stale' verdict can come from a newer failed scan attempt rather
        // than from measured drift, leaving no change set to cost the rescan
        // against. Guessing a size there is how an unbounded scan gets back in.
        //
        // Passed on split three ways rather than summed: the policy caps its
        // estimate at the previous full scan's duration, and that cap only
        // holds for a change set of deletions alone. A total alone would hide
        // the difference.
        $drift = isset($staleness['changed_files_since'])
            ? new DriftCounts(
                (int) $staleness['changed_files_since'],
                (int) $staleness['added_files_since'],
                (int) $staleness['deleted_files_since'],
                ($staleness['added_files_truncated'] ?? false) === true,
            )
            : new DriftCounts(0, 0, 0);
        if ($drift->total() < 1) {
            return [['refresh_if_stale: the graph is stale but the change set is unknown; call scan_project to refresh.'], $snapshot];
        }
        $root = $this->queries->projectRoot($projectId);
        $decision = $this->queries->services()->refreshPolicy->decide($projectId, $drift, $this->refreshBudgetMs($root));
        if (!$decision->refresh) {
            return [['refresh_if_stale: ' . (string) $decision->reason], $snapshot];
        }
        if ($root === null) {
            return [['refresh_if_stale: the project root is unknown; serving the last complete graph.'], $snapshot];
        }
        try {
            $this->scanner->scan($root, new ScanOptions(cancellation: $cancellation));
            return [[], null];
        } catch (ScanCancelledException $cancelled) {
            // A client-requested cancellation is not a rescan failure to paper
            // over; propagate it so the transport can surface/suppress it.
            throw $cancelled;
        } catch (Throwable $error) {
            // No snapshot: a failed attempt leaves a scan row behind, and that
            // row is itself part of the staleness verdict.
            return [[sprintf('refresh_if_stale: rescan failed (%s); serving the last complete graph.', ToolErrorMapper::publicMessage($error))], null];
        }
    }

    /**
     * The project's `limits.refresh_budget_ms`, read from its knossos.json now
     * rather than from the last scan: the key changes no scan output, so an
     * edit to it does not make the graph stale and would otherwise wait for
     * an unrelated rescan to take effect. A configuration that cannot be read
     * gets the default; the rescan, if it runs, reports that fault itself.
     */
    private function refreshBudgetMs(?string $root): int
    {
        if ($root === null) {
            return RefreshPolicy::DEFAULT_BUDGET_MS;
        }
        try {
            $allowed = $this->environment !== null ? $this->environment->roots : [$root];

            return ProjectConfigurationLoader::load($root, $allowed)->refreshBudgetMs ?? RefreshPolicy::DEFAULT_BUDGET_MS;
        } catch (Throwable) {
            return RefreshPolicy::DEFAULT_BUDGET_MS;
        }
    }
}
