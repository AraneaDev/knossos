<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan\Conventions;

use Knossos\Scan\Conventions\FolderFactsBuilder;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\FolderFactsStore;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The scan keeps the derived folder facts current, and a failure to do so never
 * costs the scan its result.
 */
#[Group('scan')]
final class FolderFactsScanTest extends KnossosTestCase
{
    /** A scan writes facts, and a scan that changed nothing leaves them as they were. */
    public function testAScanWritesFolderFactsAndARescanWithoutChangesWritesNone(): void
    {
        $root = $this->fixtureRoot();
        try {
            $pdo = $this->freshTestDatabase();
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);

            $first = $service->scan($root);
            $facts = (int) $pdo->query('SELECT COUNT(*) FROM folder_facts')->fetchColumn();
            $second = $service->scan($root);

            self::assertGreaterThan(0, $facts);
            self::assertSame($facts, (int) $pdo->query('SELECT COUNT(*) FROM folder_facts')->fetchColumn());
            self::assertSame(0, (new FolderFactsStore($pdo))->replace($first->projectId, (new FolderFactsBuilder($pdo))->rows($first->projectId)));
            self::assertSame($first->snapshotId, $second->snapshotId, 'the second scan found nothing to change');
            $row = (new FolderFactsStore($pdo))->of($first->projectId, 'src', 'php', 'source');
            self::assertSame(2, $row['files'] ?? null);
            self::assertSame(['one_class' => 2], $row['facts']['kinds'] ?? null);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A facts table that cannot be written costs one log line, not the scan. */
    public function testAFailingFactsRefreshLeavesTheScanResultIntact(): void
    {
        $root = $this->fixtureRoot();
        try {
            $pdo = $this->freshTestDatabase();
            $pdo->exec('DROP TABLE folder_facts');
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
            $result = null;

            $logged = $this->errorLogOf(function () use ($service, $root, &$result): void {
                $result = $service->scan($root);
            });

            self::assertNotNull($result);
            self::assertGreaterThan(0, $this->nodeCount($pdo));
            self::assertStringContainsString('knossos folder facts:', $logged);
            self::assertSame(1, substr_count(trim($logged), 'knossos folder facts:'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    private function nodeCount(PDO $pdo): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'class'")->fetchColumn();
    }

    /** A project with two classes in `src`. */
    private function fixtureRoot(): string
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        file_put_contents($root . '/src/One.php', "<?php\n\nfinal class One {}\n");
        file_put_contents($root . '/src/Two.php', "<?php\n\nfinal class Two {}\n");

        return $root;
    }
}
