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
 * and for how long) and so is its end (`external_scan_ended`).
 */
final class ExternalScanProbe
{
    private bool $scanning = false;

    /** @param (Closure(): ?array{pid: ?int, held_seconds: int})|null $holder who holds the lease, if anyone */
    public function __construct(private readonly ?Closure $holder) {}

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
        if (($holder !== null) === $this->scanning) {
            return;
        }
        $this->scanning = $holder !== null;
        $emit($holder === null
            ? ['event' => 'external_scan_ended']
            : ['event' => 'external_scan', 'holder_pid' => $holder['pid'], 'held_seconds' => $holder['held_seconds']]);
    }
}
