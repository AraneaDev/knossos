<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Scanner\Worker\WorkerErrorCode;
use Knossos\Scanner\Worker\WorkerException;
use PDO;

/**
 * Refuses a rescan that would reconcile away a failed language's facts.
 *
 * A rescan must never do so, whatever its mode: doing so prunes the last
 * good facts for a worker that failed and can replace a healthy graph with
 * an empty one. That holds for a requested full rescan as much as an
 * incremental one, so such a scan fails closed and the caller can repair
 * the worker and retry without data loss. A full rescan whose failed
 * languages hold no facts in the graph has nothing to lose, and degrades
 * per language as a first scan does; refusing it would leave no way to
 * update the other languages until the broken worker is repaired.
 */
final readonly class DegradedRescanGuard
{
    public function __construct(private PDO $pdo) {}

    /**
     * Throw when this scan of `$projectId` lost a worker and reconciling it would cost the graph facts.
     *
     * @throws WorkerException with code `DegradedIncremental` when the scan must not go on
     */
    public function check(ScanPlan $plan, LanguageScanResult $language, string $projectId): void
    {
        if (!$plan->hadActiveScan || $language->workerDiagnostics === []) {
            return;
        }
        $owners = array_values(array_unique(array_map(
            static fn(array $diagnostic): string => (string) ($diagnostic['owner'] ?? 'unknown'),
            $language->workerDiagnostics,
        )));
        if ($plan->effectiveMode !== 'full' || $this->graphHoldsFactsOf($projectId, $owners)) {
            $failed = array_map(
                static fn(array $diagnostic): string => ($diagnostic['owner'] ?? 'unknown') . ': ' . ($diagnostic['code'] ?? WorkerErrorCode::Failed->value),
                $language->workerDiagnostics,
            );
            throw new WorkerException(
                WorkerErrorCode::DegradedIncremental->value,
                'Scan aborted to preserve the last good graph. Failed workers: ' . implode(', ', $failed)
                    . '. Fix the worker (run `knossos doctor`) and rescan; the graph is unchanged.',
                terminatingSignal: array_values(array_filter(array_column($language->workerDiagnostics, 'terminating_signal')))[0] ?? null,
            );
        }
    }

    /**
     * Whether the project's graph holds any node owned by one of these scanners.
     *
     * Every fact a scanner contributes is owned by a key that starts with its
     * id and a colon, and every file it analysed carries at least its file
     * node, so a scanner with no node under that prefix has nothing to lose.
     *
     * @param list<string> $scannerIds
     */
    private function graphHoldsFactsOf(string $projectId, array $scannerIds): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM nodes WHERE project_id = :project AND owner_key >= :low AND owner_key < :high LIMIT 1',
        );
        foreach ($scannerIds as $scannerId) {
            // ';' is the character after ':', so the range is exactly the
            // keys that start with "<id>:" and the owner index can serve it.
            $statement->execute(['project' => $projectId, 'low' => $scannerId . ':', 'high' => $scannerId . ';']);
            $found = $statement->fetchColumn() !== false;
            $statement->closeCursor();
            if ($found) {
                return true;
            }
        }

        return false;
    }
}
