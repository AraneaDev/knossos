<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Cancellation\CancellationToken;
use Knossos\Cancellation\ScanCancelledException;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ProjectWriterLock;
use Knossos\Scan\ScanFailureLog;
use Knossos\Scan\ScanOptions;
use Knossos\Store\ScanBusyException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

#[Group('scan')]
final class ScanFailureRecordingTest extends KnossosTestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            exec('rm -rf ' . escapeshellarg($path));
        }
        $this->cleanup = [];
        parent::tearDown();
    }

    private function temporaryDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($directory, 0o700, true);

        return $this->cleanup[] = $directory;
    }

    /** A one-file PHP project. */
    private function project(): string
    {
        $root = $this->temporaryDirectory('knossos-failure-project-');
        file_put_contents($root . '/Thing.php', "<?php\n\nnamespace App;\n\nfinal class Thing {}\n");

        return $root;
    }

    /** An installation whose PHP worker is `$body`; the other workers are not there. */
    private function installationWithWorker(string $body): string
    {
        $installation = $this->temporaryDirectory('knossos-failure-install-');
        mkdir($installation . '/workers/php/bin', 0o700, true);
        file_put_contents($installation . '/workers/php/bin/worker', "<?php\n" . $body . "\n");

        return $installation;
    }

    private function log(): ScanFailureLog
    {
        return new ScanFailureLog($this->temporaryDirectory('knossos-failure-log-') . '/scan-failures.jsonl');
    }

    private function service(PDO $pdo, string $installation, string $root, ScanFailureLog $log): ProjectScanService
    {
        return new ProjectScanService($pdo, $installation, [$root], failures: $log);
    }

    /** @return array<string, mixed> */
    private function only(ScanFailureLog $log): array
    {
        $recent = $log->recent(10);
        assertSame(1, count($recent));

        return $recent[0];
    }

    public function testAScanThatThrowsIsLoggedWithItsClassAndRethrown(): void
    {
        $log = $this->log();
        $root = $this->project();
        $service = new ProjectScanService($this->freshTestDatabase(), self::repositoryRoot(), [sys_get_temp_dir() . '/elsewhere'], failures: $log);

        assertThrows(fn() => $service->scan($root, new ScanOptions(startedBy: 'refresh')), Throwable::class);

        $entry = $this->only($log);
        assertSame('other', $entry['class']);
        assertSame('refresh', $entry['started_by']);
        assertSame(realpath($root) ?: $root, $entry['root']);
        assertSame(true, $entry['message'] !== '');
        assertSame(true, is_int($entry['elapsed_ms']));
        assertSame(null, $entry['signal']);
    }

    public function testABusyGraphIsALockFailure(): void
    {
        $log = $this->log();
        $root = $this->project();
        $pdo = $this->freshTestDatabase();
        $held = (new ProjectWriterLock($pdo))->acquire(ProjectScanService::projectIdOf(realpath($root) ?: $root));

        try {
            assertThrows(fn() => $this->service($pdo, self::repositoryRoot(), $root, $log)->scan($root), ScanBusyException::class);
        } finally {
            $held->release();
        }

        assertSame('lock', $this->only($log)['class']);
    }

    public function testAKilledWorkerOnARescanIsKilledWithItsSignal(): void
    {
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('posix is required to kill a worker.');
        }
        $log = $this->log();
        $root = $this->project();
        $pdo = $this->freshTestDatabase();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
        file_put_contents($root . '/Thing.php', "<?php\n\nnamespace App;\n\nfinal class Thing { public int \$changed = 1; }\n");
        $killer = $this->installationWithWorker('posix_kill(posix_getpid(), SIGKILL);');

        assertThrows(fn() => $this->service($pdo, $killer, $root, $log)->scan($root, new ScanOptions(startedBy: 'watch')), \Knossos\Scanner\Worker\WorkerException::class);

        $entry = $this->only($log);
        assertSame('killed', $entry['class']);
        assertSame(9, $entry['signal']);
        assertSame('watch', $entry['started_by']);
    }

    public function testAWorkerThatExitedOnItsOwnIsAWorkerFailure(): void
    {
        $log = $this->log();
        $root = $this->project();
        $pdo = $this->freshTestDatabase();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
        file_put_contents($root . '/Thing.php', "<?php\n\nnamespace App;\n\nfinal class Thing { public int \$changed = 2; }\n");
        $quitter = $this->installationWithWorker('exit(7);');

        assertThrows(fn() => $this->service($pdo, $quitter, $root, $log)->scan($root), \Knossos\Scanner\Worker\WorkerException::class);

        $entry = $this->only($log);
        assertSame('worker', $entry['class']);
        assertSame(null, $entry['signal']);
    }

    public function testACancelledScanIsNotLogged(): void
    {
        $log = $this->log();
        $root = $this->project();
        $token = new CancellationToken();
        $token->cancel();

        assertThrows(
            fn() => $this->service($this->freshTestDatabase(), self::repositoryRoot(), $root, $log)->scan($root, new ScanOptions(cancellation: $token)),
            ScanCancelledException::class,
        );

        assertSame([], $log->recent(10));
    }

    public function testAFirstScanWhoseWorkerWasKilledSucceedsDegradedAndIsLogged(): void
    {
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('posix is required to kill a worker.');
        }
        $log = $this->log();
        $root = $this->project();
        $killer = $this->installationWithWorker('posix_kill(posix_getpid(), SIGKILL);');

        $result = $this->service($this->freshTestDatabase(), $killer, $root, $log)->scan($root, new ScanOptions(startedBy: 'pane'));

        assertSame(true, $result->snapshotId !== '');
        $entry = $this->only($log);
        assertSame('killed', $entry['class']);
        assertSame(9, $entry['signal']);
        assertSame('pane', $entry['started_by']);
    }

    public function testAnInMemoryDatabaseWritesNothingAndDoesNotFail(): void
    {
        $root = $this->project();
        $service = new ProjectScanService($this->freshTestDatabase(), self::repositoryRoot(), [$root], failures: ScanFailureLog::forDatabase(':memory:'));

        assertSame(true, $service->scan($root)->snapshotId !== '');
    }
}
