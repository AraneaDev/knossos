<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Discovery\AllowedRoots;
use Knossos\Result\ResultEnvelope;
use Knossos\Scan\ProjectPathResolver;
use Knossos\Scan\ProjectScanner;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ProjectWriterLock;
use Knossos\Scan\ScanLedger;
use Knossos\Scan\TreeFingerprint;
use Knossos\Store\StableId;
use PDO;
use Throwable;

/**
 * A scanner that records each scan of an existing project in the
 * {@see ScanLedger}: what every file it changed held before it, and the
 * policy violations those files held before it. Every writer the Claude
 * Code mod can meet scans through it: the pane's rescan, `knossos scan`
 * (and so the live watcher's scan processes) and the MCP `scan_project`.
 *
 * It takes the project's write lease first and reads the graph under it,
 * then scans under the same lease: no other writer can scan between the
 * read of the snapshot the scan starts from and the scan itself, so each
 * entry's `from` is the snapshot that scan really started from. Before
 * scanning it fingerprints the tree and compares it with the hashes the
 * graph holds, which names the files the scan is about to change while the
 * graph still describes them; their violations are read then, one file at
 * a time so a later reader can take each file's from the first scan that
 * changed it. A first scan (no project yet), or a scan of a path that is
 * not exactly a project's root, is passed through unrecorded: there is no
 * earlier graph of it to describe.
 */
final readonly class LedgeredScanner implements ProjectScanner
{
    /** Files whose violations are read before a scan; past it, that scan records none. */
    public const MAX_CHECKED = 20;

    /** How long a scan waits for another writer's lease before it reports the project busy, in milliseconds. */
    public const LEASE_WAIT_MS = 30_000;

    /**
     * @param PDO $pdo the graph database the scan writes
     * @param ProjectScanService $service the scan itself
     * @param list<array<string, mixed>>|null $policies the policies to check; null reads the project's knossos.json
     * @param ProjectWriterLock|null $lock the project's write lease; the database's own unless a test gives one
     */
    public function __construct(
        private PDO $pdo,
        private ProjectScanService $service,
        private AllowedRoots $roots,
        private ?array $policies = null,
        private ?ProjectWriterLock $lock = null,
    ) {}

    /**
     * A ledgered scan with a {@see ProjectScanService} of `$roots` as the scan itself.
     *
     * @param AllowedRoots|list<string> $roots
     */
    public static function local(PDO $pdo, string $installationRoot, AllowedRoots|array $roots, ?array $policies = null): self
    {
        $allowed = AllowedRoots::of($roots);
        return new self($pdo, new ProjectScanService($pdo, $installationRoot, $allowed), $allowed, $policies);
    }

    /**
     * Scans `$root` as {@see ProjectScanService::scan()} does, with the same
     * arguments (by position or by name), recording what the scan changed
     * when it is a scan of an existing project.
     */
    public function scan(string $root, mixed ...$options): ResultEnvelope
    {
        $real = realpath($root) ?: $root;
        $project = (new ProjectPathResolver($this->pdo))->resolve($real);
        if ($project === null || (string) $project['root_realpath'] !== $real) {
            // A first scan has no project yet, but its id is the root's: wait for
            // another writer's lease on it as a rescan does, and hand the lease on.
            $lease = ($this->lock ?? new ProjectWriterLock($this->pdo))
                ->acquire(StableId::project('root:' . $real), self::LEASE_WAIT_MS, ProjectScanService::cancellationIn($options));
            try {
                return $this->service->scan($root, ...$options, lease: $lease);
            } finally {
                $lease->release();
            }
        }
        $projectId = (string) $project['id'];
        // Another writer's scan is mostly about to end: wait for it (unless the scan is cancelled), and scan what it left.
        $lease = ($this->lock ?? new ProjectWriterLock($this->pdo))->acquire($projectId, self::LEASE_WAIT_MS, ProjectScanService::cancellationIn($options));
        try {
            $ledger = new ScanLedger($this->pdo);
            $from = $ledger->activeSnapshot($projectId);
            $before = $ledger->hashes($projectId);
            $baselines = $this->baselines($projectId, $real, $before);
            $result = $this->service->scan($root, ...$options, lease: $lease);
            $ledger->record($projectId, $from, $result->snapshotId, $before, $ledger->hashes($projectId), $baselines);
            return $result;
        } finally {
            $lease->release();
        }
    }

    /**
     * The violations each file about to change holds now, by path; null when
     * the project declares no policies, the tree cannot be read, or more than
     * {@see self::MAX_CHECKED} files are about to change.
     *
     * @param array<string, string> $graph the hashes the graph holds
     * @return array<string, array{violations: array<string, array<string, mixed>>, truncated: bool}>|null
     */
    private function baselines(string $projectId, string $root, array $graph): ?array
    {
        $policies = FileViolationQuery::policies($root, $this->policies);
        if ($policies === []) {
            return null;
        }
        try {
            $tree = TreeFingerprint::of($root, $this->roots, false);
        } catch (Throwable) {
            return null;
        }
        $changing = array_keys(TreeFingerprint::changes($graph, $tree));
        if (count($changing) > self::MAX_CHECKED) {
            return null;
        }
        $query = new FileViolationQuery($this->pdo);
        $baselines = [];
        foreach ($changing as $path) {
            $found = $query->inFiles($projectId, $policies, [(string) $path]);
            if ($found !== null) {
                $baselines[(string) $path] = $found;
            }
        }
        return $baselines;
    }
}
