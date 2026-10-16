<?php

declare(strict_types=1);

namespace Knossos\Watch;

use Closure;
use Knossos\Scan\ProjectWriterLock;
use PDO;
use Throwable;

/**
 * Says when another process scans the project: the model's `scan_project`,
 * `knossos scan` from a shell, another session's server. Such a scan holds
 * the project's write lease and nothing else a watcher reads, so without
 * this the pane shows nothing while it runs and a scan started meanwhile
 * reports the project busy.
 *
 * Polled between the watcher's own scans, so a lease it sees is someone
 * else's. Each start is said once (`external_scan`, with who holds the lease
 * and for how long, again when another process takes it over) and so is its
 * end (`external_scan_ended`).
 */
final class ExternalScanProbe
{
    private bool $scanning = false;

    /** The process the last start named. */
    private ?int $pid = null;

    /** This host's name, against which a lease's host is told apart. */
    private string $localHost;

    /**
     * @param (Closure(): ?array{pid: ?int, host?: ?string, held_seconds: int})|null $holder who holds the lease, if anyone
     * @param string|null $localHost this host's name; gethostname() when null
     */
    public function __construct(private readonly ?Closure $holder, ?string $localHost = null)
    {
        $this->localHost = $localHost ?? (string) gethostname();
    }

    /**
     * Who holds a project's write lease in a graph database, as the probe
     * reads it.
     *
     * @return Closure(): ?array{pid: ?int, host: ?string, held_seconds: int}
     */
    public static function holderIn(PDO $pdo, string $projectId): Closure
    {
        $lock = new ProjectWriterLock($pdo);

        return static fn(): ?array => $lock->holder($projectId);
    }

    /**
     * Read the lease once and say whether another process began or ended a
     * scan since the last poll.
     *
     * @param callable(array<string, mixed>): void $emit
     */
    public function poll(callable $emit): void
    {
        if ($this->holder === null) {
            return;
        }
        try {
            $holder = ($this->holder)();
        } catch (Throwable) {
            // A database busy with that very scan says nothing new: keep what was said.
            return;
        }
        // Present before and still held by the same process: nothing new. Another process taking the lease
        // between two polls is a new start, so the pane names the scan that runs now.
        if ($holder === null ? !$this->scanning : $this->scanning && $holder['pid'] === $this->pid) {
            return;
        }
        $this->scanning = $holder !== null;
        $this->pid = $holder['pid'] ?? null;
        if ($holder === null) {
            $emit(['event' => 'external_scan_ended']);

            return;
        }
        // A writer on another host, a container among them: its process id
        // names some other process here, so its host is said instead.
        $host = $holder['host'] ?? null;
        $emit($host !== null && $host !== $this->localHost
            ? ['event' => 'external_scan', 'holder_pid' => null, 'holder_host' => $host, 'held_seconds' => $holder['held_seconds']]
            : ['event' => 'external_scan', 'holder_pid' => $holder['pid'], 'held_seconds' => $holder['held_seconds']]);
    }
}
