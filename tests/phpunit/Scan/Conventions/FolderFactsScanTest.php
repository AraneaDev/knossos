<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan\Conventions;

use Knossos\Git\DirtyPathResolver;
use Knossos\Git\GitHeadResolver;
use Knossos\Scan\Conventions\FolderCompanionsBuilder;
use Knossos\Scan\Conventions\FolderFactsBuilder;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\FolderFactsStore;
use Knossos\Tests\Phpunit\Git\RecordingGitRunner;
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
    private const HEAD = '3f1a9c2b4d5e6f708192a3b4c5d6e7f8091a2b3c';

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

    /** The scan hands its root and recorded commit to the companions builder, once, and not again on a scan that changed nothing. */
    public function testAScanRefreshesCompanionsForItsCommitOnce(): void
    {
        $root = $this->fixtureRoot();
        try {
            $pdo = $this->freshTestDatabase();
            $runner = new RecordingGitRunner();
            $service = new ProjectScanService(
                $pdo,
                self::repositoryRoot(),
                [$root],
                new GitHeadResolver(new RecordingGitRunner(static fn(): string => self::HEAD . "\n")),
                new DirtyPathResolver(new RecordingGitRunner()),
                companions: new FolderCompanionsBuilder(new FolderFactsStore($pdo), $runner),
            );

            $first = $service->scan($root);
            $service->scan($root);

            self::assertCount(1, $runner->commands);
            self::assertContains(realpath($root), $runner->commands[0]);
            self::assertSame(self::HEAD, (new FolderFactsStore($pdo))->companionsHead($first->projectId));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A companions step that fails leaves the scan result and the facts alone. */
    public function testAFailingCompanionsRefreshLeavesTheScanResultIntact(): void
    {
        $root = $this->fixtureRoot();
        try {
            $pdo = $this->freshTestDatabase();
            $pdo->exec('DROP TABLE folder_companions');
            $service = new ProjectScanService(
                $pdo,
                self::repositoryRoot(),
                [$root],
                new GitHeadResolver(new RecordingGitRunner(static fn(): string => self::HEAD . "\n")),
                new DirtyPathResolver(new RecordingGitRunner()),
                companions: new FolderCompanionsBuilder(new FolderFactsStore($pdo), new RecordingGitRunner()),
            );
            $result = null;

            $logged = $this->errorLogOf(function () use ($service, $root, &$result): void {
                $result = $service->scan($root);
            });

            self::assertNotNull($result);
            self::assertGreaterThan(0, (int) $pdo->query('SELECT COUNT(*) FROM folder_facts')->fetchColumn());
            self::assertStringContainsString('knossos folder facts:', $logged);
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
