<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Roots\AllowedRoots;
use Knossos\Scan\ScanOptions;
use Knossos\Scan\ScanTarget;
use PDO;
use Throwable;

/**
 * An incremental scan the person asked for from the architecture pane.
 *
 * The pane offers it when the snapshot is stale or the tree has drifted, so
 * the figures it draws catch up without waiting for the next edit. It writes
 * under the same rules as `turn-brief`: only inside a root the operator
 * allowed, and incrementally, so it never creates a database. It scans a
 * project that is already known. The one exception is the first scan right
 * after the person allowed a root in the pane: a path that is exactly one
 * of the allowed roots and has no project yet gets its first full scan.
 * A path merely inside an allowed root still answers `unscanned`. Unlike a turn brief it
 * reports nothing about the change itself: the pane reloads its dashboard
 * after it.
 */
final readonly class RescanService
{
    /**
     * @param PDO $pdo an existing, migrated graph database
     * @param string $databasePath where $pdo lives; locates `roots.json`
     * @param string $installationRoot where the scanner workers live
     */
    public function __construct(
        private PDO $pdo,
        private string $databasePath,
        private string $installationRoot,
    ) {}

    /**
     * Rescan the project that owns `$path`, or say why not.
     *
     * Statuses: `ok`, `not-allowed` (with `roots_file` and `refused_root`),
     * `missing`, `unscanned` and `scan-failed` (with `reason`).
     *
     * @return array<string, mixed>
     */
    public function rescan(string $path): array
    {
        $absolute = realpath($path) ?: $path;
        $envelope = [
            'path' => $absolute, 'project_root' => null, 'project_id' => null, 'snapshot_id' => null,
            'scanned_at' => null, 'scan_ms' => null, 'reason' => null, 'roots_file' => null, 'refused_root' => null,
        ];
        [$allowed, $project, $refusal] = (new ScanTarget($this->pdo, $this->databasePath))->resolve($absolute);
        $first = $project === null && ($refusal['status'] ?? null) === 'unscanned' && $this->isAllowedRoot($allowed, $absolute);
        if ($project === null && !$first) {
            return ($refusal ?? []) + $envelope;
        }
        $root = $first ? $absolute : (string) $project['root_realpath'];
        $started = hrtime(true);
        try {
            // Recorded in the ledger, so a turn brief whose edits this scan took in can still report them.
            $scan = (new ScannerFactory($this->pdo, $this->installationRoot, $this->databasePath))->ledgered($allowed)->scan($root, new ScanOptions(mode: $first ? 'full' : 'incremental'));
        } catch (Throwable $failure) {
            return ['status' => 'scan-failed', 'reason' => $failure->getMessage(), 'project_root' => $root] + $envelope;
        }

        return [
            'status' => 'ok',
            'project_root' => $root,
            'project_id' => $scan->projectId,
            'snapshot_id' => $scan->snapshotId,
            'scanned_at' => time(),
            'scan_ms' => intdiv(hrtime(true) - $started, 1_000_000),
        ] + $envelope;
    }

    /** Whether `$absolute` is, after realpath, exactly one of the roots allowed right now. */
    private function isAllowedRoot(AllowedRoots $allowed, string $absolute): bool
    {
        foreach ($allowed->current() as $root) {
            if ((realpath($root) ?: $root) === $absolute) {
                return true;
            }
        }

        return false;
    }
}
