<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Closure;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The races and failure paths of MigrationRunner::migrate(): a version another
 * process records between the unlocked check and the write lock, a
 * no-transaction rebuild that loses to another process, rollback with nothing
 * to roll back, and the directory and file guards.
 */
#[Group('migration-runner')]
final class MigrationRunnerRaceTest extends TestCase
{
    private string $tempDir;
    private string $tempSqlite;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/knossos-migrations-race-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
        $this->tempSqlite = sys_get_temp_dir() . '/knossos-mig-race-' . uniqid('', true) . '.sqlite';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            @chmod($this->tempDir, 0777);
            foreach (glob($this->tempDir . '/*') ?: [] as $f) {
                @chmod($f, 0666);
                @unlink($f);
            }
            @rmdir($this->tempDir);
        }
        foreach ([$this->tempSqlite, ...(glob($this->tempSqlite . '-*') ?: [])] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    // ----- helpers -----

    private function writeMigration(string $name, string $sql): string
    {
        $path = $this->tempDir . '/' . $name;
        file_put_contents($path, $sql);

        return $path;
    }

    /**
     * A connection to the test database whose exec() runs a one-shot hook just
     * before a given statement reaches the driver, so another "process" (a
     * second connection) can act at exactly that point.
     */
    private function hookedConnection(): HookedPdo
    {
        $pdo = new HookedPdo('sqlite:' . $this->tempSqlite, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private function otherProcess(): PDO
    {
        return SqliteConnection::open($this->tempSqlite);
    }

    /** @return array<string, string> version => checksum */
    private function recorded(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT version, checksum FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_KEY_PAIR);

        return is_array($rows) ? array_map('strval', $rows) : [];
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name");
        $statement->execute(['name' => $table]);

        return $statement->fetchColumn() !== false;
    }

    private function recordVersion(PDO $pdo, string $version, string $checksum): void
    {
        $insert = $pdo->prepare('INSERT INTO schema_migrations(version, checksum, applied_at) VALUES (:v, :c, :a)');
        $insert->execute(['v' => $version, 'c' => $checksum, 'a' => '2026-01-01T00:00:00Z']);
    }

    private function foreignKeys(PDO $pdo): string
    {
        return (string) $pdo->query('PRAGMA foreign_keys')->fetchColumn();
    }

    /** No transaction is left open: a fresh write lock starts and ends cleanly. */
    private function assertNoOpenTransaction(PDO $pdo): void
    {
        $pdo->exec('BEGIN IMMEDIATE');
        $pdo->exec('ROLLBACK');
        $this->addToAssertionCount(1);
    }

    private function skipWhenRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Root reads files regardless of their mode, so chmod 000 cannot make them unreadable.');
        }
    }

    // ----- a version recorded between the unlocked check and the write lock -----

    public function testAVersionRecordedJustBeforeTheLockWithTheSameChecksumIsSkipped(): void
    {
        $sql = 'CREATE TABLE raced (id INTEGER PRIMARY KEY);';
        $this->writeMigration('001_raced.sql', $sql);
        $pdo = $this->hookedConnection();
        $other = $this->otherProcess();
        $pdo->onceBefore('BEGIN IMMEDIATE', function () use ($other, $sql): void {
            $this->recordVersion($other, '001_raced', hash('sha256', $sql));
        });

        $applied = (new MigrationRunner($pdo, $this->tempDir))->migrate();

        assertSame([], $applied);
        assertSame(false, $this->tableExists($pdo, 'raced'), 'the migration body must not run a second time');
        assertSame(['001_raced' => hash('sha256', $sql)], $this->recorded($pdo));
        $this->assertNoOpenTransaction($pdo);
    }

    public function testAVersionRecordedJustBeforeTheLockWithAnotherChecksumThrowsAndReleasesTheLock(): void
    {
        $this->writeMigration('001_raced.sql', 'CREATE TABLE raced (id INTEGER PRIMARY KEY);');
        $pdo = $this->hookedConnection();
        $other = $this->otherProcess();
        $pdo->onceBefore('BEGIN IMMEDIATE', function () use ($other): void {
            $this->recordVersion($other, '001_raced', 'deadbeef');
        });

        $error = captureThrows(
            fn() => (new MigrationRunner($pdo, $this->tempDir))->migrate(),
            RuntimeException::class,
        );

        assertSame('Applied migration checksum changed: 001_raced', $error->getMessage());
        assertSame(false, $this->tableExists($pdo, 'raced'));
        assertSame(['001_raced' => 'deadbeef'], $this->recorded($other));
        $this->assertNoOpenTransaction($pdo);
        // The other process can write too, so the lock was not left held.
        $this->assertNoOpenTransaction($other);
    }

    // ----- a no-transaction rebuild that another process completed first -----

    /**
     * Arrange a no-transaction migration whose body fails because another
     * process created its table first, optionally recording the version there.
     *
     * @return array{HookedPdo, PDO, string} the runner's connection, the other one, the migration checksum
     */
    private function lostRebuild(string $body, ?Closure $other): array
    {
        $this->writeMigration('001_first.sql', 'CREATE TABLE first (id INTEGER PRIMARY KEY);');
        $this->writeMigration('002_rebuild.sql', $body);
        $pdo = $this->hookedConnection();
        $otherPdo = $this->otherProcess();
        $checksum = hash('sha256', $body);
        $pdo->onceBefore($body, static function () use ($other, $otherPdo, $checksum): void {
            if ($other !== null) {
                $other($otherPdo, $checksum);
            }
        });

        return [$pdo, $otherPdo, $checksum];
    }

    private const REBUILD = "-- migrate:no-transaction\nPRAGMA foreign_keys = OFF;\nCREATE TABLE rebuilt (id INTEGER PRIMARY KEY);\n";

    public function testALostNoTransactionRebuildWhoseVersionIsNowRecordedCountsAsApplied(): void
    {
        [$pdo, , $checksum] = $this->lostRebuild(self::REBUILD, function (PDO $other, string $checksum): void {
            $other->exec('CREATE TABLE rebuilt (id INTEGER PRIMARY KEY)');
            $this->recordVersion($other, '002_rebuild', $checksum);
        });

        $applied = (new MigrationRunner($pdo, $this->tempDir))->migrate();

        assertSame(['001_first'], $applied, 'a version another process applied is not reported as applied here');
        assertSame('1', $this->foreignKeys($pdo));
        assertSame($checksum, $this->recorded($pdo)['002_rebuild']);
        $this->assertNoOpenTransaction($pdo);
    }

    public function testALostNoTransactionRebuildWithoutARecordedVersionRethrowsTheDriverError(): void
    {
        [$pdo] = $this->lostRebuild(self::REBUILD, static function (PDO $other): void {
            $other->exec('CREATE TABLE rebuilt (id INTEGER PRIMARY KEY)');
        });

        $error = captureThrows(
            fn() => (new MigrationRunner($pdo, $this->tempDir))->migrate(),
            PDOException::class,
        );

        $this->assertStringContainsString('already exists', $error->getMessage());
        assertSame('1', $this->foreignKeys($pdo));
        assertSame(['001_first'], array_keys($this->recorded($pdo)));
    }

    public function testALostNoTransactionRebuildRecordedUnderAnotherChecksumThrows(): void
    {
        [$pdo] = $this->lostRebuild(self::REBUILD, function (PDO $other): void {
            $other->exec('CREATE TABLE rebuilt (id INTEGER PRIMARY KEY)');
            $this->recordVersion($other, '002_rebuild', 'deadbeef');
        });

        $error = captureThrows(
            fn() => (new MigrationRunner($pdo, $this->tempDir))->migrate(),
            RuntimeException::class,
        );

        assertSame('Applied migration checksum changed: 002_rebuild', $error->getMessage());
        assertSame('1', $this->foreignKeys($pdo));
    }

    public function testANoTransactionFailureOtherThanAlreadyExistsIsRethrownEvenWhenTheVersionIsRecorded(): void
    {
        $body = "-- migrate:no-transaction\nPRAGMA foreign_keys = OFF;\nTHIS IS NOT VALID SQL;\n";
        [$pdo] = $this->lostRebuild($body, function (PDO $other, string $checksum): void {
            $this->recordVersion($other, '002_rebuild', $checksum);
        });

        $error = captureThrows(
            fn() => (new MigrationRunner($pdo, $this->tempDir))->migrate(),
            PDOException::class,
        );

        $this->assertStringContainsString('syntax error', strtolower($error->getMessage()));
        assertSame('1', $this->foreignKeys($pdo));
    }

    public function testAnOwnTransactionMigrationFailingWithAlreadyExistsIsRethrown(): void
    {
        $this->writeMigration('001_create.sql', 'CREATE TABLE dup (id INTEGER PRIMARY KEY);');
        $body = 'CREATE TABLE dup (id INTEGER PRIMARY KEY, extra TEXT);';
        $this->writeMigration('002_create_again.sql', $body);
        $pdo = $this->hookedConnection();
        $other = $this->otherProcess();
        // Make the version recorded with the matching checksum by the time the
        // runner looks after the failure, exactly the state the no-transaction
        // tolerance accepts. The runner's lock is released around the insert so
        // the other connection can write, then re-taken for the body.
        $pdo->onceBefore($body, function () use ($pdo, $other, $body): void {
            $pdo->exec('COMMIT');
            $this->recordVersion($other, '002_create_again', hash('sha256', $body));
            $pdo->exec('BEGIN IMMEDIATE');
        });

        $error = captureThrows(
            fn() => (new MigrationRunner($pdo, $this->tempDir))->migrate(),
            PDOException::class,
        );

        $this->assertStringContainsString('already exists', $error->getMessage());
        assertSame(['001_create', '002_create_again'], array_keys($this->recorded($pdo)));
        $this->assertNoOpenTransaction($pdo);
    }

    // ----- rollback with no open transaction -----

    public function testAFailingNoTransactionMigrationThatNeverBeganRethrowsItsOwnError(): void
    {
        $this->writeMigration('001_bad.sql', "-- migrate:no-transaction\nTHIS IS NOT VALID SQL;\n");
        $pdo = SqliteConnection::open($this->tempSqlite);

        $error = captureThrows(
            fn() => (new MigrationRunner($pdo, $this->tempDir))->migrate(),
            PDOException::class,
        );

        $this->assertStringContainsString('syntax error', strtolower($error->getMessage()));
        $this->assertStringNotContainsString('no transaction is active', $error->getMessage());
        assertSame([], $this->recorded($pdo));
    }

    public function testAFailingNoTransactionMigrationThatAlreadyCommittedRethrowsItsOwnError(): void
    {
        $this->writeMigration(
            '001_committed_then_bad.sql',
            "-- migrate:no-transaction\nBEGIN;\nCREATE TABLE kept (id INTEGER PRIMARY KEY);\nCOMMIT;\nTHIS IS NOT VALID SQL;\n",
        );
        $pdo = SqliteConnection::open($this->tempSqlite);

        $error = captureThrows(
            fn() => (new MigrationRunner($pdo, $this->tempDir))->migrate(),
            PDOException::class,
        );

        $this->assertStringContainsString('syntax error', strtolower($error->getMessage()));
        $this->assertStringNotContainsString('no transaction is active', $error->getMessage());
        assertSame(true, $this->tableExists($pdo, 'kept'), 'the migration committed this itself');
        assertSame([], $this->recorded($pdo));
        $this->assertNoOpenTransaction($pdo);
    }

    // ----- directory and file guards -----

    public function testAnUnreadableDirectoryThrowsInsteadOfApplyingNothing(): void
    {
        $this->skipWhenRoot();
        $this->writeMigration('001_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY);');
        chmod($this->tempDir, 0000);
        $pdo = SqliteConnection::open($this->tempSqlite);

        $error = captureThrows(
            fn() => (new MigrationRunner($pdo, $this->tempDir))->migrate(),
            RuntimeException::class,
        );

        assertSame('Unable to enumerate migration files.', $error->getMessage());
        assertSame([], $this->recorded($pdo));
    }

    public function testAnUnreadableMigrationFileThrowsAndRecordsNothing(): void
    {
        $this->skipWhenRoot();
        $path = $this->writeMigration('001_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY);');
        chmod($path, 0000);
        $pdo = SqliteConnection::open($this->tempSqlite);

        $error = captureThrows(
            fn() => (new MigrationRunner($pdo, $this->tempDir))->migrate(),
            RuntimeException::class,
        );

        assertSame('Unable to read migration: ' . $path, $error->getMessage());
        assertSame([], $this->recorded($pdo));
    }

    public function testOnlySqlFilesAreAppliedAndATrailingSeparatorIsAccepted(): void
    {
        $sql = 'CREATE TABLE a (id INTEGER PRIMARY KEY);';
        $this->writeMigration('001_a.sql', $sql);
        $this->writeMigration('002_notes.txt', 'THIS IS NOT VALID SQL');
        $this->writeMigration('003_b.sql.bak', 'THIS IS NOT VALID SQL');
        $this->writeMigration('README', 'THIS IS NOT VALID SQL');
        $pdo = SqliteConnection::open($this->tempSqlite);

        $applied = (new MigrationRunner($pdo, $this->tempDir . DIRECTORY_SEPARATOR))->migrate();

        assertSame(['001_a'], $applied);
        assertSame(['001_a' => hash('sha256', $sql)], $this->recorded($pdo));
        assertSame(true, $this->tableExists($pdo, 'a'));
    }

    public function testVersionsAreAppliedInLexicalOrderAndEachOnlyOnTheRunThatAppliedIt(): void
    {
        // Lexically "010" sorts before "2": the second migration writes into the
        // first's table, so a numeric order would fail with "no such table".
        // (A bare REFERENCES clause would not do: SQLite does not resolve it at
        // CREATE time.)
        $this->writeMigration('010_base.sql', 'CREATE TABLE base (id INTEGER PRIMARY KEY);');
        $this->writeMigration('2_child.sql', 'CREATE TABLE child (id INTEGER PRIMARY KEY); INSERT INTO base(id) VALUES (2);');
        $broken = $this->writeMigration('3_later.sql', 'THIS IS NOT VALID SQL');
        $pdo = SqliteConnection::open($this->tempSqlite);
        $runner = new MigrationRunner($pdo, $this->tempDir);

        captureThrows(static fn() => $runner->migrate(), PDOException::class);

        assertSame(['010_base', '2_child'], array_keys($this->recorded($pdo)));
        assertSame([2], array_map('intval', $pdo->query('SELECT id FROM base')->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertNoOpenTransaction($pdo);

        file_put_contents($broken, 'CREATE TABLE later (id INTEGER PRIMARY KEY);');

        assertSame(['3_later'], $runner->migrate());
        assertSame([], $runner->migrate());
        assertSame(['010_base', '2_child', '3_later'], array_keys($this->recorded($pdo)));
    }
}

/**
 * A PDO whose exec() runs a registered one-shot hook immediately before the
 * matching statement is sent to the driver.
 */
final class HookedPdo extends PDO
{
    /** @var array<string, Closure(): void> */
    private array $hooks = [];

    /** @param Closure(): void $hook */
    public function onceBefore(string $statement, Closure $hook): void
    {
        $this->hooks[$statement] = $hook;
    }

    public function exec(string $statement): int|false
    {
        if (isset($this->hooks[$statement])) {
            $hook = $this->hooks[$statement];
            unset($this->hooks[$statement]);
            $hook();
        }

        return parent::exec($statement);
    }
}
