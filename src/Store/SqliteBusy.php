<?php

declare(strict_types=1);

namespace Knossos\Store;

use PDO;
use PDOException;
use Throwable;

/**
 * Recognises the driver error for a contended SQLite write lock, and takes the
 * lock with retries.
 */
final class SqliteBusy
{
    /** The code a caller is given for a write that met another process's write lock. */
    public const CODE = 'KNOSSOS_DATABASE_BUSY';

    /** Said for such a write, a scan's commit most often. */
    public const MESSAGE = 'The graph database is busy: another process, usually a scan, is writing it. Nothing was changed; retry in a moment.';

    /** Whether a contended write lock is behind this failure, however many layers wrapped it. */
    public static function anywhere(Throwable $error): bool
    {
        for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException && self::is($cause)) {
                return true;
            }
        }

        return false;
    }

    /** Pauses between the retries of BEGIN IMMEDIATE: 200, 400 and 800 ms. */
    private const BACKOFF_MICROSECONDS = [200_000, 400_000, 800_000];

    /**
     * SQLite reports a contended WAL write lock as SQLSTATE HY000 with a
     * "database is locked"/"busy" message (distinct from the 23000 UNIQUE
     * collision on a lock row). Both mean "come back later", so callers
     * surface them as a busy error rather than a raw driver error.
     */
    public static function is(PDOException $error): bool
    {
        if ((string) $error->getCode() !== 'HY000') {
            return false;
        }
        $message = strtolower($error->getMessage());

        // The phrases themselves: a bare "busy" also matched a table named busy_jobs.
        return str_contains($message, 'database is locked') || str_contains($message, 'database table is locked') || str_contains($message, 'database is busy');
    }

    /**
     * Take the write lock, waiting out a holder that outlasts the busy timeout.
     *
     * The connection's busy timeout covers a short hold. A longer one raised a
     * raw driver error after the scan's worker work was already done, or at
     * startup when two processes migrated at once, so the lock is retried with
     * a growing pause and only then reported as busy.
     */
    public static function beginImmediate(PDO $pdo): void
    {
        foreach (self::BACKOFF_MICROSECONDS as $pause) {
            try {
                $pdo->exec('BEGIN IMMEDIATE');

                return;
            } catch (PDOException $error) {
                if (!self::is($error)) {
                    throw $error;
                }
            }
            usleep($pause);
        }
        try {
            $pdo->exec('BEGIN IMMEDIATE');
        } catch (PDOException $error) {
            if (!self::is($error)) {
                throw $error;
            }
            throw new ScanBusyException('The graph database is busy; another writer holds the lock.', 0, $error);
        }
    }
}
