<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Runtime;

use Knossos\Runtime\ScannerFactory;
use Knossos\Scan\ProjectWriterLock;
use Knossos\Scan\ScanLedger;
use Knossos\Scan\ScanOptions;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The scanners {@see ScannerFactory} builds: the ledgered one records a scan
 * of an existing project, the one under the caller's own lease does not.
 */
#[Group('runtime-factory')]
final class ScannerFactoryTest extends KnossosTestCase
{
    /** Appends a line to a fixture file, so the next scan changes it. */
    private static function touch(string $root): void
    {
        $file = $root . '/src/Core/Greeter.php';
        file_put_contents($file, file_get_contents($file) . "\n// touched\n");
    }

    public function testTheLedgeredScannerRecordsARescan(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        try {
            self::touch($root);

            $result = (new ScannerFactory($pdo, self::repositoryRoot(), ':memory:'))->ledgered([$root])->scan($root, new ScanOptions(mode: 'incremental'));

            assertSame($result->snapshotId, (new ScanLedger($pdo))->activeSnapshot($projectId));
            assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM scan_ledger')->fetchColumn());
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testTheScanUnderAnOwnLeaseLeavesTheLedgerToItsCaller(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        try {
            self::touch($root);
            $lease = (new ProjectWriterLock($pdo))->acquire($projectId);
            try {
                (new ScannerFactory($pdo, self::repositoryRoot(), ':memory:'))->underOwnLease([$root])->scan($root, new ScanOptions(mode: 'incremental', lease: $lease));
            } finally {
                $lease->release();
            }

            assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM scan_ledger')->fetchColumn());
        } finally {
            $this->removeTempTree($root);
        }
    }
}
