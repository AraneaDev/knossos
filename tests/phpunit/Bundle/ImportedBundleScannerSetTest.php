<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Bundle;

use Knossos\Bundle\GraphBundleService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * An imported bundle carries a graph but no test findings (findings are derived
 * data a bundle does not hold). Its scan stores the fallback scanner-set hash, so
 * the next scan of that project is a real one that produces findings instead of
 * the no-change fast path, which would keep the graph and its zero findings.
 */
#[Group('test-fidelity')]
#[Group('bundle')]
#[Group('typescript-scanner')]
final class ImportedBundleScannerSetTest extends KnossosTestCase
{
    public function testAnImportedBundleHoldsNoFindingsSoItsScannerSetNeverMatchesARealScan(): void
    {
        $root = self::scratchPath('bundle-findings');
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/typescript/momus', $root);
        $direct = $this->freshTestDatabase();
        (new ProjectScanService($direct, self::repositoryRoot(), [$root]))->scan($root);
        $imported = $this->freshTestDatabase();

        (new GraphBundleService($imported))->import((new GraphBundleService($direct))->export((string) $direct->query('SELECT id FROM projects')->fetchColumn()));

        self::assertGreaterThan(0, (int) $direct->query('SELECT COUNT(*) FROM test_findings')->fetchColumn());
        assertSame(0, (int) $imported->query('SELECT COUNT(*) FROM test_findings')->fetchColumn());
        assertSame(hash('sha256', 'bundle'), (string) $imported->query('SELECT scanner_set_hash FROM scans')->fetchColumn());
    }

    public function testAnImportedScanStoresTheFallbackHashNotTheBundlesOwn(): void
    {
        $root = self::scratchPath('bundle-hash');
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/typescript/momus', $root);
        $direct = $this->freshTestDatabase();
        (new ProjectScanService($direct, self::repositoryRoot(), [$root]))->scan($root);
        $original = (string) $direct->query('SELECT scanner_set_hash FROM scans LIMIT 1')->fetchColumn();

        $imported = $this->freshTestDatabase();
        (new GraphBundleService($imported))->import((new GraphBundleService($direct))->export((string) $direct->query('SELECT id FROM projects')->fetchColumn()));
        $stored = (string) $imported->query('SELECT scanner_set_hash FROM scans LIMIT 1')->fetchColumn();

        assertNotSame($original, $stored);
        assertSame(hash('sha256', 'bundle'), $stored);
    }
}
