<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan\Conventions;

use Knossos\Git\DirtyPathResolver;
use Knossos\Git\GitHeadResolver;
use Knossos\Scan\Conventions\FolderCompanionsBuilder;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\FolderFactsStore;
use Knossos\Tests\Phpunit\Git\RecordingGitRunner;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A scan that finds nothing changed still keeps the derived facts and the
 * companions current: an upgraded database has no facts until it is asked to,
 * and a commit that changed no file moves the head the companions follow.
 */
#[Group('scan')]
final class FolderFactsUnchangedScanTest extends KnossosTestCase
{
    private const FIRST_HEAD = '1111111111111111111111111111111111111111';

    private const SECOND_HEAD = '2222222222222222222222222222222222222222';

    /** A project with no folder facts at all, as after an upgrade, gets them from a scan that changed nothing. */
    public function testAnUnchangedTreeWithoutFactsWritesThem(): void
    {
        $root = $this->fixtureRoot();
        try {
            $pdo = $this->freshTestDatabase();
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
            $first = $service->scan($root);
            $pdo->exec('DELETE FROM folder_facts');
            $pdo->exec('DELETE FROM folder_companions');

            $second = $service->scan($root);

            self::assertSame($first->snapshotId, $second->snapshotId, 'the second scan took the no-change path');
            self::assertSame(2, (new FolderFactsStore($pdo))->of($first->projectId, 'src', 'php', 'source')['files'] ?? null);
            self::assertArrayHasKey('folder_facts', $second->data['metrics']['stages_ms']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** With facts present, a scan that changed nothing does not rebuild them. */
    public function testAnUnchangedTreeWithFactsLeavesThemAlone(): void
    {
        $root = $this->fixtureRoot();
        try {
            $pdo = $this->freshTestDatabase();
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
            $service->scan($root);
            $pdo->exec("UPDATE folder_facts SET files = 99 WHERE directory = 'src'");

            $second = $service->scan($root);

            self::assertSame(99, (int) $pdo->query("SELECT files FROM folder_facts WHERE directory = 'src'")->fetchColumn());
            self::assertArrayNotHasKey('folder_facts', $second->data['metrics']['stages_ms']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Edit, scan, commit, scan: the commit changed no file, and the companions still follow it. */
    public function testACommitWithoutFileChangesMovesTheCompanionsHead(): void
    {
        $root = $this->fixtureRoot();
        try {
            $pdo = $this->freshTestDatabase();
            $head = self::FIRST_HEAD;
            $runner = new RecordingGitRunner();
            $service = new ProjectScanService(
                $pdo,
                self::repositoryRoot(),
                [$root],
                new GitHeadResolver(new RecordingGitRunner(static function () use (&$head): string {
                    return $head . "\n";
                })),
                new DirtyPathResolver(new RecordingGitRunner()),
                companions: new FolderCompanionsBuilder(new FolderFactsStore($pdo), $runner),
            );
            $service->scan($root);

            file_put_contents($root . '/src/Two.php', "<?php\n\nfinal class Two\n{\n}\n");
            $edited = $service->scan($root);
            $head = self::SECOND_HEAD;
            $committed = $service->scan($root);

            self::assertSame($edited->snapshotId, $committed->snapshotId, 'the commit changed no file, so the scan took the no-change path');
            self::assertSame(self::SECOND_HEAD, (new FolderFactsStore($pdo))->companionsHead($edited->projectId));
            self::assertCount(2, $runner->commandsWith('log'));
            self::assertArrayHasKey('folder_companions', $committed->data['metrics']['stages_ms']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A scan that changed the graph reports what the facts and the companions cost, each as its own stage. */
    public function testAChangingScanTimesFactsAndCompanionsAsTheirOwnStages(): void
    {
        $root = $this->fixtureRoot();
        try {
            $result = (new ProjectScanService($this->freshTestDatabase(), self::repositoryRoot(), [$root]))->scan($root);

            $stages = $result->data['metrics']['stages_ms'];
            self::assertArrayHasKey('folder_facts', $stages);
            self::assertArrayHasKey('folder_companions', $stages);
            self::assertIsFloat($stages['folder_facts']);
        } finally {
            $this->removeTempTree($root);
        }
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
