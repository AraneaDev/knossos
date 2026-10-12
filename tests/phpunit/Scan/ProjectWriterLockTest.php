<?php

declare(strict_types=1);

namespace Knossos\Tests\Scan;

use Knossos\Scan\ProjectWriterLease;
use Knossos\Scan\ProjectWriterLock;
use Knossos\Store\ScanBusyException;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('scan-locks')]
final class ProjectWriterLockTest extends TestCase
{
    private function createSchema(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE scan_locks (project_id TEXT PRIMARY KEY, owner_token TEXT NOT NULL, acquired_at INTEGER NOT NULL)');
        return $pdo;
    }

    private function rowCount(PDO $pdo, string $projectId): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM scan_locks WHERE project_id = :project');
        $stmt->execute(['project' => $projectId]);
        return (int) $stmt->fetchColumn();
    }

    private function rowExists(PDO $pdo, string $projectId): bool
    {
        return $this->rowCount($pdo, $projectId) === 1;
    }

    private function fetchRow(PDO $pdo, string $projectId): array
    {
        $rows = $pdo->query('SELECT owner_token, acquired_at FROM scan_locks WHERE project_id = ' . $pdo->quote($projectId), PDO::FETCH_ASSOC)->fetchAll();
        assertSame(true, count($rows) === 1);
        return $rows[0];
    }

    public function testAcquireCreatesLeaseAndInsertsRow(): void
    {
        $pdo = $this->createSchema();
        $lock = new ProjectWriterLock($pdo);

        $lease = $lock->acquire('proj_1');

        assertSame('proj_1', $lease->projectId());
        assertSame(true, $this->rowExists($pdo, 'proj_1'));
        $lease->release();
    }

    public function testAcquirePopulatesOwnerTokenAndAcquiredAt(): void
    {
        $pdo = $this->createSchema();
        $lock = new ProjectWriterLock($pdo);

        $lease = $lock->acquire('proj_1');

        $row = $this->fetchRow($pdo, 'proj_1');
        // Who holds it, so a lease whose process is gone can be taken over (see testAcquireTakesOverALeaseWhoseProcessIsGone).
        assertSame(1, preg_match('/^' . getmypid() . '@' . preg_quote((string) gethostname(), '/') . ':[0-9a-f]{32}$/', $row['owner_token']));
        assertSame(true, $row['acquired_at'] > 0);
        assertSame(true, abs($row['acquired_at'] - time()) < 5);
        $lease->release();
    }

    public function testReleaseRemovesRowAndIsIdempotent(): void
    {
        $pdo = $this->createSchema();
        $lock = new ProjectWriterLock($pdo);

        $lease = $lock->acquire('proj_1');
        assertSame(true, $this->rowExists($pdo, 'proj_1'));

        $lease->release();
        assertSame(false, $this->rowExists($pdo, 'proj_1'));

        $lease->release();
        assertSame(false, $this->rowExists($pdo, 'proj_1'));
    }

    public function testDestructAutomaticallyReleasesLease(): void
    {
        $pdo = $this->createSchema();
        $lock = new ProjectWriterLock($pdo);

        (function () use ($lock, $pdo): void {
            $lease = $lock->acquire('proj_2');
            assertSame(true, $this->rowExists($pdo, 'proj_2'));
            unset($lease);
        })();

        assertSame(false, $this->rowExists($pdo, 'proj_2'));
    }

    public function testReleaseOnUnmatchedTokenStillIssuesZeroRowDelete(): void
    {
        $pdo = $this->createSchema();
        $lock = new ProjectWriterLock($pdo);

        $stmt = $pdo->prepare('INSERT INTO scan_locks(project_id, owner_token, acquired_at) VALUES (:project, :token, :acquired)');
        $stmt->execute(['project' => 'proj_1', 'token' => 'real-owner-token', 'acquired' => time()]);

        $lease = new ProjectWriterLease($pdo, 'proj_1', 'fake-token');
        $lease->release();

        assertSame(true, $this->rowExists($pdo, 'proj_1'));
    }

    public function testAcquireThrowsScanBusyExceptionOnUniqueCollision(): void
    {
        $pdo = $this->createSchema();
        $lock = new ProjectWriterLock($pdo);
        $lease = $lock->acquire('proj_3');

        $error = captureThrows(
            static fn(): ProjectWriterLease => $lock->acquire('proj_3'),
            ScanBusyException::class,
        );

        assertSame(true, $error instanceof ScanBusyException);
        // Who holds it and for how long, so the caller can tell a scan from a stuck one.
        assertSame(sprintf('A scan is already running for project proj_3 (pid %d, held 0s).', getmypid()), $error->getMessage());
        assertSame(true, $error->getPrevious() instanceof PDOException);
        $lease->release();
    }

    public function testAcquireExpiresStaleLeaseAndInsertsFreshRow(): void
    {
        $pdo = $this->createSchema();
        $clock = static fn(): int => 2_000_000_000;
        $lock = new ProjectWriterLock($pdo, leaseSeconds: 60, clock: $clock);

        $stmt = $pdo->prepare('INSERT INTO scan_locks(project_id, owner_token, acquired_at) VALUES (:project, :token, :acquired)');
        $stmt->execute([
            'project' => 'proj_4',
            'token' => 'stale-token',
            'acquired' => 1_999_999_900,
        ]);

        $lease = $lock->acquire('proj_4');

        assertSame('proj_4', $lease->projectId());
        $row = $this->fetchRow($pdo, 'proj_4');
        assertSame(true, $row['owner_token'] !== 'stale-token');
        assertSame(1, preg_match('/:[0-9a-f]{32}$/', $row['owner_token']));
        assertSame(2_000_000_000, (int) $row['acquired_at']);
        $lease->release();
    }

    /** A scan killed by a signal never runs its lease's destructor: its lease is taken over at once, not an hour later. */
    public function testAcquireTakesOverALeaseWhoseProcessIsGone(): void
    {
        $pdo = $this->createSchema();
        $this->holdLease($pdo, 'proj_dead', '4242@' . gethostname() . ':' . str_repeat('a', 32));
        $lock = new ProjectWriterLock($pdo, alive: static fn(int $pid): bool => $pid !== 4242);

        $lease = $lock->acquire('proj_dead');

        assertSame(1, preg_match('/^' . getmypid() . '@/', $this->fetchRow($pdo, 'proj_dead')['owner_token']));
        $lease->release();
    }

    /** A holder still running keeps its lease, and so does one on another host, whose processes this one cannot see. */
    public function testAcquireLeavesALeaseItCannotProveAbandoned(): void
    {
        $pdo = $this->createSchema();
        $held = [
            'proj_live' => ['4242@' . gethostname() . ':' . str_repeat('b', 32), static fn(int $pid): bool => true],
            'proj_remote' => ['4242@another-host:' . str_repeat('c', 32), static fn(int $pid): bool => false],
            // A lease an older knossos took names no process.
            'proj_old' => [str_repeat('d', 32), static fn(int $pid): bool => false],
        ];
        foreach ($held as $project => [$token, $alive]) {
            $this->holdLease($pdo, $project, $token);
            captureThrows(static fn(): ProjectWriterLease => (new ProjectWriterLock($pdo, alive: $alive))->acquire($project), ScanBusyException::class);
            assertSame($token, $this->fetchRow($pdo, $project)['owner_token'], $project);
        }
    }

    /** The operating system's answer: a process that has exited is gone, this one runs. */
    public function testAcquireAsksTheSystemWhetherTheHolderRuns(): void
    {
        $pdo = $this->createSchema();
        $process = proc_open([PHP_BINARY, '-r', 'sleep(30);'], [], $pipes);
        assertSame(true, is_resource($process));
        $exited = proc_get_status($process)['pid'];
        proc_terminate($process, 9);
        proc_close($process);
        $this->holdLease($pdo, 'proj_exited', $exited . '@' . gethostname() . ':' . str_repeat('e', 32));
        $this->holdLease($pdo, 'proj_running', getmypid() . '@' . gethostname() . ':' . str_repeat('f', 32));

        (new ProjectWriterLock($pdo))->acquire('proj_exited')->release();
        captureThrows(static fn(): ProjectWriterLease => (new ProjectWriterLock($pdo))->acquire('proj_running'), ScanBusyException::class);
        assertSame(true, $this->rowExists($pdo, 'proj_running'));
    }

    private function holdLease(PDO $pdo, string $projectId, string $token): void
    {
        $pdo->prepare('INSERT INTO scan_locks(project_id, owner_token, acquired_at) VALUES (?, ?, ?)')->execute([$projectId, $token, time()]);
    }

    public function testAcquireRethrowsNonUniquePdoExceptionAfterRollback(): void
    {
        $execCalls = [];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturnCallback(function (string $sql) use (&$execCalls) {
            $execCalls[] = $sql;
            return 0;
        });
        $pdo->method('prepare')->willThrowException(new PDOException('disk full', 123));

        $lock = new ProjectWriterLock($pdo);

        $error = captureThrows(
            static fn(): ProjectWriterLease => $lock->acquire('proj_5'),
            PDOException::class,
        );

        assertSame(true, $error instanceof PDOException);
        assertSame('disk full', $error->getMessage());
        assertSame(['BEGIN IMMEDIATE', 'ROLLBACK'], $execCalls);
    }

    public function testAcquireRollsBackOnGenericThrowable(): void
    {
        $execCalls = [];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturnCallback(function (string $sql) use (&$execCalls) {
            $execCalls[] = $sql;
            return 0;
        });
        $pdo->method('prepare')->willThrowException(new RuntimeException('boom'));

        $lock = new ProjectWriterLock($pdo);

        $error = captureThrows(
            static fn(): ProjectWriterLease => $lock->acquire('proj_6'),
            RuntimeException::class,
        );

        assertSame(true, $error instanceof RuntimeException);
        assertSame('boom', $error->getMessage());
        assertSame(['BEGIN IMMEDIATE', 'ROLLBACK'], $execCalls);
    }

    public function testAcquireRollsBackEvenIfRollbackItselfFails(): void
    {
        $execCalls = [];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturnCallback(function (string $sql) use (&$execCalls) {
            $execCalls[] = $sql;
            if ($sql === 'ROLLBACK') {
                throw new RuntimeException('rollback failed');
            }
            return 0;
        });
        $pdo->method('prepare')->willThrowException(new PDOException('disk full', 123));

        $lock = new ProjectWriterLock($pdo);

        $error = captureThrows(
            static fn(): ProjectWriterLease => $lock->acquire('proj_7'),
            PDOException::class,
        );

        assertSame(true, $error instanceof PDOException);
        assertSame('disk full', $error->getMessage());
        assertSame(['BEGIN IMMEDIATE', 'ROLLBACK'], $execCalls);
    }

    public function testAcquireWithDefaultLeaseSecondsDoesNotExpireRowAtExactBoundary(): void
    {
        $pdo = $this->createSchema();
        $clock = static fn(): int => 1_000_000;
        // Default leaseSeconds = 3600 (no explicit value)
        $lock = new ProjectWriterLock($pdo, clock: $clock);

        // Manually insert a row at clock - 3600 (exact boundary)
        // With default leaseSeconds=3600: expired = clock - 3600, DELETE clause is `acquired_at < expired`
        // Strict < means the row at exactly expired is NOT deleted → INSERT collides → ScanBusyException
        // With mutated leaseSeconds=3599: expired = clock - 3599, row < expired → DELETE fires → INSERT succeeds
        $stmt = $pdo->prepare('INSERT INTO scan_locks(project_id, owner_token, acquired_at) VALUES (:project, :token, :acquired)');
        $stmt->execute([
            'project' => 'proj_boundary',
            'token' => 'boundary-token',
            'acquired' => 1_000_000 - 3600,
        ]);

        $error = captureThrows(
            static fn(): ProjectWriterLease => $lock->acquire('proj_boundary'),
            ScanBusyException::class,
        );

        assertSame(true, $error instanceof ScanBusyException);
    }

    public function testAcquireWithDefaultLeaseSecondsExpiresRowOlderThan3600Seconds(): void
    {
        $pdo = $this->createSchema();
        $clock = static fn(): int => 1_000_000;
        $lock = new ProjectWriterLock($pdo, clock: $clock);

        // Row at clock - 3601 (just past the default boundary)
        // With default leaseSeconds=3600: row < expired → DELETE fires → INSERT succeeds
        // With mutated leaseSeconds=3601: expired = clock - 3601, row NOT < expired → INSERT collides
        $stmt = $pdo->prepare('INSERT INTO scan_locks(project_id, owner_token, acquired_at) VALUES (:project, :token, :acquired)');
        $stmt->execute([
            'project' => 'proj_3601',
            'token' => 'old-token',
            'acquired' => 1_000_000 - 3601,
        ]);

        $lease = $lock->acquire('proj_3601');

        assertSame('proj_3601', $lease->projectId());
        $row = $this->fetchRow($pdo, 'proj_3601');
        assertSame(true, $row['owner_token'] !== 'old-token');
        assertSame(1_000_000, (int) $row['acquired_at']);
        $lease->release();
    }

    public function testAcquireRollsBackEvenIfRollbackItselfFailsOnGenericThrowable(): void
    {
        $execCalls = [];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturnCallback(function (string $sql) use (&$execCalls) {
            $execCalls[] = $sql;
            if ($sql === 'ROLLBACK') {
                throw new RuntimeException('rollback failed');
            }
            return 0;
        });
        $pdo->method('prepare')->willThrowException(new RuntimeException('boom'));

        $lock = new ProjectWriterLock($pdo);

        $error = captureThrows(
            static fn(): ProjectWriterLease => $lock->acquire('proj_8'),
            RuntimeException::class,
        );

        assertSame(true, $error instanceof RuntimeException);
        assertSame('boom', $error->getMessage());
        assertSame(['BEGIN IMMEDIATE', 'ROLLBACK'], $execCalls);
    }

    public function testAcquireBindingsIncludeAllRequiredKeys(): void
    {
        $execCalls = [];
        $executeBindings = [];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturnCallback(function (string $sql) use (&$execCalls) {
            $execCalls[] = $sql;
            return 0;
        });
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$executeBindings): PDOStatement {
            $stmt = $this->createStub(PDOStatement::class);
            $stmt->method('execute')->willReturnCallback(function (array $bindings) use (&$executeBindings, $sql): bool {
                $executeBindings[$sql] = $bindings;
                return true;
            });
            return $stmt;
        });

        $lock = new ProjectWriterLock($pdo, leaseSeconds: 3600);

        $lease = $lock->acquire('proj_bindings');

        assertSame('proj_bindings', $lease->projectId());

        $deleteSql = 'DELETE FROM scan_locks WHERE project_id = :project AND acquired_at < :expired';
        $insertSql = 'INSERT INTO scan_locks(project_id, owner_token, acquired_at) VALUES (:project, :token, :acquired)';

        assertSame(true, isset($executeBindings[$deleteSql]));
        assertSame(['project', 'expired'], array_keys($executeBindings[$deleteSql]));

        assertSame(true, isset($executeBindings[$insertSql]));
        assertSame(['project', 'token', 'acquired'], array_keys($executeBindings[$insertSql]));

        $lease->release();
    }

    /** PDO reports SQLSTATE as a string code; reflection reproduces that on a crafted exception. */
    private function pdoExceptionWithSqlstate(string $message, string $sqlstate): PDOException
    {
        $error = new PDOException($message);
        $code = new \ReflectionProperty(\Exception::class, 'code');
        $code->setValue($error, $sqlstate);
        return $error;
    }

    public function testAcquireTranslatesSqliteBusyIntoScanBusyException(): void
    {
        $error = $this->pdoExceptionWithSqlstate('SQLSTATE[HY000]: General error: 5 database is locked', 'HY000');
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('prepare')->willThrowException($error);

        $lock = new ProjectWriterLock($pdo);

        $thrown = captureThrows(
            static fn(): ProjectWriterLease => $lock->acquire('proj_busy'),
            ScanBusyException::class,
        );

        assertSame(true, $thrown instanceof ScanBusyException);
        assertSame($error, $thrown->getPrevious());
    }

    public function testAcquireTranslatesBusyKeywordIntoScanBusyException(): void
    {
        $error = $this->pdoExceptionWithSqlstate('database is busy', 'HY000');
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('prepare')->willThrowException($error);

        $lock = new ProjectWriterLock($pdo);

        $thrown = captureThrows(
            static fn(): ProjectWriterLease => $lock->acquire('proj_busy2'),
            ScanBusyException::class,
        );

        assertSame(true, $thrown instanceof ScanBusyException);
    }

    public function testAcquireDoesNotTreatOtherHy000ErrorsAsBusy(): void
    {
        $error = $this->pdoExceptionWithSqlstate('SQLSTATE[HY000]: disk I/O error', 'HY000');
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('prepare')->willThrowException($error);

        $lock = new ProjectWriterLock($pdo);

        $thrown = captureThrows(
            static fn(): ProjectWriterLease => $lock->acquire('proj_disk'),
            PDOException::class,
        );

        assertSame('SQLSTATE[HY000]: disk I/O error', $thrown->getMessage());
    }

    public function testAcquireOnGenuinelyLockedDatabaseThrowsScanBusy(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-lock-') ?: sys_get_temp_dir() . '/knossos-lock-' . bin2hex(random_bytes(6));
        try {
            $holder = new PDO('sqlite:' . $path);
            $holder->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $holder->exec('PRAGMA journal_mode=WAL');
            $holder->exec('CREATE TABLE scan_locks (project_id TEXT PRIMARY KEY, owner_token TEXT NOT NULL, acquired_at INTEGER NOT NULL)');

            $writer = new PDO('sqlite:' . $path);
            $writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $writer->exec('PRAGMA busy_timeout=0');

            // The holder takes the WAL write lock; the writer's BEGIN IMMEDIATE
            // then fails immediately with SQLSTATE HY000 "database is locked".
            $holder->exec('BEGIN IMMEDIATE');
            $holder->exec("INSERT INTO scan_locks VALUES ('other', 'tok', 1)");

            $lock = new ProjectWriterLock($writer);
            $thrown = captureThrows(
                static fn(): ProjectWriterLease => $lock->acquire('proj_contended'),
                ScanBusyException::class,
            );
            assertSame(true, $thrown instanceof ScanBusyException);

            $holder->exec('ROLLBACK');
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function testAcquireWithRecentRowDoesNotExpireUnderDefaultLeaseSeconds(): void
    {
        $pdo = $this->createSchema();
        $clock = static fn(): int => 1_000_000;
        $lock = new ProjectWriterLock($pdo, clock: $clock);

        // Row at clock - 1 (just 1 second old): NOT expired by default leaseSeconds=3600
        // Original: expired = clock - 3600, row > expired → DELETE doesn't fire → INSERT collides → ScanBusyException
        // Mutated M#10 ($now + vs -): expired = clock + 3600, row < expired → DELETE fires → INSERT succeeds (no exception)
        $stmt = $pdo->prepare('INSERT INTO scan_locks(project_id, owner_token, acquired_at) VALUES (:project, :token, :acquired)');
        $stmt->execute([
            'project' => 'proj_recent',
            'token' => 'recent-token',
            'acquired' => 999_999,
        ]);

        $error = captureThrows(
            static fn(): ProjectWriterLease => $lock->acquire('proj_recent'),
            ScanBusyException::class,
        );

        assertSame(true, $error instanceof ScanBusyException);
        // A lease an older knossos took names no process: only its age is known.
        assertSame('A scan is already running for project proj_recent (held 1s).', $error->getMessage());
    }

    public function testHolderNamesTheProcessAndHowLongItHasHeldTheLease(): void
    {
        $pdo = $this->createSchema();
        $lock = new ProjectWriterLock($pdo, clock: static fn(): int => 1_000_040);
        $pdo->prepare('INSERT INTO scan_locks(project_id, owner_token, acquired_at) VALUES (?, ?, ?)')->execute(['proj_held', '4242@host-a:' . str_repeat('a', 32), 1_000_000]);
        $pdo->prepare('INSERT INTO scan_locks(project_id, owner_token, acquired_at) VALUES (?, ?, ?)')->execute(['proj_old', str_repeat('d', 32), 1_000_030]);

        assertSame(['pid' => 4242, 'host' => 'host-a', 'held_seconds' => 40], $lock->holder('proj_held'));
        assertSame(['pid' => null, 'host' => null, 'held_seconds' => 10], $lock->holder('proj_old'));
        assertSame(null, $lock->holder('proj_free'));
    }

    /** A lease whose process on this host is gone holds nothing: the next acquire takes it over, so nobody is scanning. */
    public function testHolderIgnoresALeaseWhoseProcessIsGone(): void
    {
        $pdo = $this->createSchema();
        $this->holdLease($pdo, 'proj_dead', '4242@' . gethostname() . ':' . str_repeat('a', 32));

        assertSame(null, (new ProjectWriterLock($pdo, alive: static fn(int $pid): bool => false))->holder('proj_dead'));
        assertSame(4242, (new ProjectWriterLock($pdo, alive: static fn(int $pid): bool => true))->holder('proj_dead')['pid'] ?? null);
    }

    /** A caller that may wait polls the lease until its holder lets go. */
    public function testAcquireWaitsForAHolderThatLetsGo(): void
    {
        $pdo = $this->createSchema();
        $this->holdLease($pdo, 'proj_wait', '4242@' . gethostname() . ':' . str_repeat('a', 32));
        $slept = [];
        $sleep = function (int $ms) use (&$slept, $pdo): void {
            $slept[] = $ms;
            if (count($slept) === 3) {
                $pdo->exec("DELETE FROM scan_locks WHERE project_id = 'proj_wait'");
            }
        };
        $lock = new ProjectWriterLock($pdo, alive: static fn(int $pid): bool => true, sleep: $sleep);

        $lease = $lock->acquire('proj_wait', waitMs: 30_000);

        assertSame([250, 250, 250], $slept);
        assertSame(1, preg_match('/^' . getmypid() . '@/', $this->fetchRow($pdo, 'proj_wait')['owner_token']));
        $lease->release();
    }

    /** A holder that keeps the lease past the wait is reported busy, after exactly the wait, and a caller that may not wait never sleeps. */
    public function testAcquireGivesUpAfterTheWait(): void
    {
        $pdo = $this->createSchema();
        $this->holdLease($pdo, 'proj_stuck', '4242@' . gethostname() . ':' . str_repeat('a', 32));
        $slept = 0;
        $lock = new ProjectWriterLock($pdo, alive: static fn(int $pid): bool => true, sleep: static function (int $ms) use (&$slept): void {
            $slept += $ms;
        });

        captureThrows(static fn(): ProjectWriterLease => $lock->acquire('proj_stuck', waitMs: 1_000), ScanBusyException::class);
        assertSame(1_000, $slept);
        captureThrows(static fn(): ProjectWriterLease => $lock->acquire('proj_stuck'), ScanBusyException::class);
        assertSame(1_000, $slept);
    }
}
