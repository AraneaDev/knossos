<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan\Conventions;

use Knossos\Scan\Conventions\FolderFactsBuilder;
use Knossos\Scan\Conventions\FolderFactsRefresher;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\FolderFactsStore;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/** Facts that could not be rebuilt are removed, not left as current, and the next scan builds them again. */
#[Group('scan')]
final class FolderFactsRetryTest extends KnossosTestCase
{
    public function testAFailedRebuildLeavesNoFactsAndTheNextUnchangedScanWritesThem(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        file_put_contents($root . '/src/One.php', "<?php\n\nfinal class One {}\n");
        try {
            $pdo = $this->freshTestDatabase();
            $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
            $store = new FolderFactsStore($pdo);
            self::assertTrue($store->has($projectId), 'the scan wrote facts that are now the old ones');

            $failing = new FolderFactsRefresher($pdo, null, static function (): array {
                throw new RuntimeException('rebuild failed');
            });
            $logged = $this->errorLogOf(static fn() => $failing->refresh($projectId, $root, null));

            self::assertStringContainsString('knossos folder facts: rebuild failed', $logged);
            self::assertFalse($store->has($projectId), 'old facts are not kept as if they were current');

            $working = new FolderFactsRefresher($pdo, null, static fn(string $id): array => (new FolderFactsBuilder($pdo))->rows($id));
            $working->refreshUnchanged($projectId, $root, null);

            self::assertSame(1, $store->of($projectId, 'src', 'php', 'source')['files'] ?? null);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testClearRemovesOnlyTheProjectsRows(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $store = new FolderFactsStore($pdo);
        $row = [['directory' => 'src', 'extension' => 'php', 'role' => 'source', 'files' => 5, 'facts' => []]];
        $store->replace($ids['project'], $row);
        $store->replace('other-project', $row);

        $store->clear($ids['project']);

        self::assertFalse($store->has($ids['project']));
        self::assertTrue($store->has('other-project'));
    }
}
