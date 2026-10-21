<?php

declare(strict_types=1);

namespace Knossos\Store;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Applies the schema migrations a graph database needs.
 *
 * Runs on every connection rather than as a deploy step, because the database is
 * derived state a user may delete at will and the server has to rebuild it
 * unattended. Applied migrations are recorded, so re-running is a no-op.
 */
final readonly class MigrationRunner
{
    public function __construct(
        private PDO $pdo,
        private string $migrationDirectory,
    ) {}

    /**
     * Apply any migrations this database has not yet recorded.
     *
     * @return list<string> versions applied by this invocation
     */
    public function migrate(): array
    {
        if (!is_dir($this->migrationDirectory)) {
            throw new RuntimeException(sprintf('Migration directory does not exist: %s', $this->migrationDirectory));
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (' .
            'version TEXT PRIMARY KEY, checksum TEXT NOT NULL, applied_at TEXT NOT NULL' .
            ')',
        );

        $applied = [];
        foreach ($this->migrationFiles() as $file) {
            $version = basename($file, '.sql');
            $sql = self::readMigration($file);
            $checksum = hash('sha256', $sql);

            $existing = $this->recordedChecksum($version);
            if ($existing !== null) {
                $this->assertSameChecksum($existing, $checksum, $version);
                continue;
            }

            // Migrations marked no-transaction manage their own transaction
            // boundaries. This exists for table rebuilds: PRAGMA foreign_keys
            // is a silent no-op inside a transaction, so a rebuild that must
            // disable enforcement (dropping a parent table would otherwise
            // cascade into its children) cannot run under the runner's own
            // transaction.
            $ownTransaction = !str_starts_with($sql, '-- migrate:no-transaction');
            if (!$this->claimVersion($version, $checksum, $ownTransaction)) {
                continue;
            }
            if ($ownTransaction) {
                $this->applyInOwnTransaction($version, $checksum, $sql);
            } elseif (!$this->applyWithoutTransaction($version, $checksum, $sql)) {
                continue;
            }
            $applied[] = $version;
        }

        return $applied;
    }

    /**
     * The migration files in the directory, in the order they apply.
     *
     * @return list<string>
     */
    private function migrationFiles(): array
    {
        // An unreadable migration directory must not silently apply zero
        // migrations: glob() returns an empty array (not false) for it, so the
        // false check below never fires. Fail loudly instead.
        if (!is_readable($this->migrationDirectory)) {
            throw new RuntimeException('Unable to enumerate migration files.');
        }
        $files = glob(rtrim($this->migrationDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.sql');
        if ($files === false) {
            throw new RuntimeException('Unable to enumerate migration files.');
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /** The SQL of one migration file. */
    private static function readMigration(string $file): string
    {
        // Check readability first so an unreadable migration fails with our
        // RuntimeException rather than a bare file_get_contents warning.
        if (!is_readable($file)) {
            throw new RuntimeException(sprintf('Unable to read migration: %s', $file));
        }
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException(sprintf('Unable to read migration: %s', $file));
        }

        return $sql;
    }

    /**
     * Takes the write lock and looks again whether `$version` is applied:
     * false when it is (by another process, since the unlocked check), true
     * when this process is to apply it. An own-transaction migration keeps
     * the lock's transaction open for its body; for a no-transaction one the
     * lock is only a probe, released here, because the body must run outside
     * a transaction.
     */
    private function claimVersion(string $version, string $checksum, bool $ownTransaction): bool
    {
        // The loser of the race waits here and then skips.
        SqliteBusy::beginImmediate($this->pdo);
        try {
            $existing = $this->recordedChecksum($version);
            if ($existing !== null) {
                $this->assertSameChecksum($existing, $checksum, $version);
                $this->pdo->exec('COMMIT');
                return false;
            }
            if (!$ownTransaction) {
                $this->pdo->exec('COMMIT');
            }
        } catch (Throwable $error) {
            $this->rollBackQuietly();
            throw $error;
        }

        return true;
    }

    /**
     * Runs a migration body and records its version in the transaction
     * {@see self::claimVersion()} opened, so the schema change and its
     * bookkeeping commit atomically.
     */
    private function applyInOwnTransaction(string $version, string $checksum, string $sql): void
    {
        try {
            $this->pdo->exec($sql);
            $this->recordVersion($version, $checksum);
            $this->pdo->exec('COMMIT');
        } catch (Throwable $error) {
            // PDO::inTransaction() does not see a transaction opened with a
            // plain BEGIN, so roll back at the SQL level.
            $this->rollBackQuietly();
            throw $error;
        }
    }

    /**
     * Runs a no-transaction migration body, which opens its own transaction
     * and should leave its last one open, and records its version in that
     * transaction, then restores foreign-key enforcement.
     *
     * @return bool false when another process applied the same version first
     */
    private function applyWithoutTransaction(string $version, string $checksum, string $sql): bool
    {
        try {
            $this->pdo->exec($sql);
            $this->recordVersion($version, $checksum);
            try {
                $this->pdo->exec('COMMIT');
            } catch (PDOException $error) {
                // A legacy no-transaction migration committed its own
                // transaction, so the version insert above auto-committed
                // instead and SQLite answers "cannot commit - no transaction is
                // active": nothing is left to commit. Any other failure to
                // commit is a real one. Such a migration keeps a small re-run
                // window (a crash between its COMMIT and the version insert);
                // migrations should instead leave their final transaction open
                // for the runner so the two commit atomically.
                if (!str_contains($error->getMessage(), 'no transaction is active')) {
                    throw $error;
                }
            }
            // Restore the connection's foreign-key contract: a migration
            // that left its transaction open for the runner cannot
            // re-enable enforcement itself, since PRAGMA foreign_keys is
            // a no-op inside a transaction.
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        } catch (Throwable $error) {
            $this->rollBackQuietly();
            // A failed rebuild may abort between PRAGMA foreign_keys
            // OFF and ON; the connection contract is enforcement on.
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            // The lock probe does not span the body, so another process
            // can finish the same rebuild first and this one then fails
            // on the objects it created. A version recorded by then was
            // applied, just not by this process.
            if (str_contains($error->getMessage(), 'already exists')) {
                $existing = $this->recordedChecksum($version);
                if ($existing !== null) {
                    $this->assertSameChecksum($existing, $checksum, $version);
                    return false;
                }
            }
            throw $error;
        }

        return true;
    }

    /** The checksum recorded for a version, or null when it is not applied. */
    private function recordedChecksum(string $version): ?string
    {
        $statement = $this->pdo->prepare('SELECT checksum FROM schema_migrations WHERE version = :version');
        $statement->execute(['version' => $version]);
        $existing = $statement->fetchColumn();

        return $existing === false ? null : (string) $existing;
    }

    /** Refuse a migration whose file no longer matches the checksum recorded when it was applied. */
    private function assertSameChecksum(string $recorded, string $checksum, string $version): void
    {
        if (!hash_equals($recorded, $checksum)) {
            throw new RuntimeException(sprintf('Applied migration checksum changed: %s', $version));
        }
    }

    /** Roll back an open transaction, tolerating the case where none is active. */
    private function rollBackQuietly(): void
    {
        try {
            $this->pdo->exec('ROLLBACK');
        } catch (Throwable) {
            // No transaction was active.
        }
    }

    /** Record an applied migration, which is what makes re-running a no-op. */
    private function recordVersion(string $version, string $checksum): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO schema_migrations(version, checksum, applied_at) VALUES (:version, :checksum, :applied_at)',
        );
        $insert->execute([
            'version' => $version,
            'checksum' => $checksum,
            'applied_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }
}
