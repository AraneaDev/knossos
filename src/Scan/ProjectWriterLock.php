<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Closure;
use Knossos\Store\ScanBusyException;
use Knossos\Store\SqliteBusy;
use PDO;
use PDOException;
use Throwable;

/**
 * Grants one writer per project at a time.
 *
 * Two concurrent scans of the same project would interleave their reconciliation
 * and leave a graph that matches neither. Leases carry an expiry so a crashed
 * scanner's lock is reclaimable rather than permanent.
 *
 * A lease also names its holder, `pid@host:random`. A scan killed by a signal
 * never runs its lease's destructor, so its lease would block every scan of
 * the project until it expired; one whose process on this host is gone is
 * taken over at once instead. A holder on another host, a process id that
 * still runs (even one reused), or a lease an older knossos took that names
 * no process all wait for the expiry, the safe side of the doubt.
 */
final readonly class ProjectWriterLock
{
    /** @var Closure(int): bool */
    private Closure $alive;

    /**
     * @param Closure|null $alive whether a process id on this host still runs; the operating system's answer unless a test gives its own
     */
    public function __construct(private PDO $pdo, private int $leaseSeconds = 3600, private ?Closure $clock = null, ?Closure $alive = null)
    {
        $this->alive = $alive ?? self::processRuns(...);
    }

    /** Take the project's write lease, or report that another scan holds it. */
    public function acquire(string $projectId): ProjectWriterLease
    {
        $now = $this->clock === null ? time() : ($this->clock)();
        $token = sprintf('%d@%s:%s', getmypid(), self::host(), bin2hex(random_bytes(16)));
        try {
            // BEGIN IMMEDIATE is inside the try: on a contended WAL database this is
            // exactly where SQLITE_BUSY surfaces, and it must be translated too.
            $this->pdo->exec('BEGIN IMMEDIATE');
            $delete = $this->pdo->prepare('DELETE FROM scan_locks WHERE project_id = :project AND acquired_at < :expired');
            $delete->execute(['project' => $projectId, 'expired' => $now - $this->leaseSeconds]);
            $this->dropAbandoned($projectId);
            $insert = $this->pdo->prepare('INSERT INTO scan_locks(project_id, owner_token, acquired_at) VALUES (:project, :token, :acquired)');
            $insert->execute(['project' => $projectId, 'token' => $token, 'acquired' => $now]);
            $this->pdo->exec('COMMIT');
        } catch (PDOException $error) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
            if ((string) $error->getCode() === '23000' || str_contains($error->getMessage(), 'UNIQUE constraint')) {
                throw new ScanBusyException(sprintf('A scan is already running for project %s.', $projectId), previous: $error);
            }
            if (SqliteBusy::is($error)) {
                throw new ScanBusyException(sprintf('The graph database is busy; another writer holds the lock (project %s).', $projectId), previous: $error);
            }
            throw $error;
        } catch (Throwable $error) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
            throw $error;
        }
        return new ProjectWriterLease($this->pdo, $projectId, $token, $this->clock);
    }

    /** Delete the project's lease when it names a process on this host that no longer runs. */
    private function dropAbandoned(string $projectId): void
    {
        $select = $this->pdo->prepare('SELECT owner_token FROM scan_locks WHERE project_id = :project');
        $select->execute(['project' => $projectId]);
        $held = $select->fetchColumn();
        if (!is_string($held) || preg_match('/^(\d+)@(.+):[0-9a-f]{32}$/', $held, $holder) !== 1) {
            return;
        }
        if ($holder[2] !== self::host() || ($this->alive)((int) $holder[1])) {
            return;
        }
        $delete = $this->pdo->prepare('DELETE FROM scan_locks WHERE project_id = :project AND owner_token = :token');
        $delete->execute(['project' => $projectId, 'token' => $held]);
    }

    private static function host(): string
    {
        $host = gethostname();
        return $host === false || $host === '' ? 'unknown' : $host;
    }

    /** Whether `$pid` runs: signal 0 checks without sending, and a process this user may not signal still exists. */
    private static function processRuns(int $pid): bool
    {
        if ($pid <= 0) {
            return true;
        }
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0) || posix_get_last_error() !== 3;
        }
        // Without posix, /proc where there is one; elsewhere nothing is proved gone.
        return !is_dir('/proc/self') || is_dir('/proc/' . $pid);
    }
}
