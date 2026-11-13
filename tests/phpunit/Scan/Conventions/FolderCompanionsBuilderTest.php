<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan\Conventions;

use Knossos\Scan\Conventions\FolderCompanionsBuilder;
use Knossos\Store\FolderFactsStore;
use Knossos\Tests\Phpunit\Git\RecordingGitRunner;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Companions come from one `git log` per commit: the paths that change along
 * with a file added to a folder.
 */
#[Group('scan')]
final class FolderCompanionsBuilderTest extends KnossosTestCase
{
    private PDO $pdo;

    private FolderFactsStore $store;

    private string $project;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->pdo, , $ids] = $this->storeFixture();
        $this->store = new FolderFactsStore($this->pdo);
        $this->project = $ids['project'];
    }

    /** A path that changed in four of five additions is a companion; one in two of five is not. */
    public function testAPathChangedInMostAdditionsIsACompanion(): void
    {
        $commits = [];
        foreach (range(1, 5) as $i) {
            $changes = [['A', 'src/Q/X' . $i . '.php']];
            if ($i <= 4) {
                $changes[] = ['M', 'tools/lint-files.txt'];
            }
            if ($i <= 2) {
                $changes[] = ['M', 'docs/api.md'];
            }
            $commits[] = $changes;
        }
        $runner = new RecordingGitRunner(static fn(): string => self::log($commits));

        (new FolderCompanionsBuilder($this->store, $runner))->refresh($this->project, '/repo', 'head1');

        self::assertEquals(
            [['path' => 'tools/lint-files.txt', 'commits' => 4, 'of' => 5]],
            $this->store->companionsOf($this->project, 'src/Q'),
        );
        self::assertSame('ok', $this->storedStatus());
        self::assertCount(1, $runner->commands);
        self::assertContains('--name-status', $runner->commands[0]);
        self::assertContains('--no-merges', $runner->commands[0]);
        self::assertSame([3000], $runner->timeouts);
    }

    /** Two additions are no pattern, however consistent. */
    public function testFewerThanThreeAdditionsGiveNoCompanions(): void
    {
        $commits = [
            [['A', 'src/Q/A.php'], ['M', 'tools/lint-files.txt']],
            [['A', 'src/Q/B.php'], ['M', 'tools/lint-files.txt']],
        ];

        (new FolderCompanionsBuilder($this->store, new RecordingGitRunner(static fn(): string => self::log($commits))))->refresh($this->project, '/repo', 'head1');

        self::assertSame([], $this->store->companionsOf($this->project, 'src/Q'));
        self::assertSame('head1', $this->store->companionsHead($this->project));
    }

    /** Other files added in the same folder by the same commit are not each other's companions. */
    public function testFilesAddedTogetherAreNotCompanionsOfTheirFolder(): void
    {
        $commits = array_map(
            static fn(int $i): array => [['A', 'src/Q/A' . $i . '.php'], ['A', 'src/Q/B' . $i . '.php']],
            range(1, 3),
        );

        (new FolderCompanionsBuilder($this->store, new RecordingGitRunner(static fn(): string => self::log($commits))))->refresh($this->project, '/repo', 'head1');

        self::assertSame([], $this->store->companionsOf($this->project, 'src/Q'));
    }

    /** A commit that is already stored is not asked for again. */
    public function testTheSameHeadIsNotAskedTwice(): void
    {
        $runner = new RecordingGitRunner(static fn(): string => '');
        $builder = new FolderCompanionsBuilder($this->store, $runner);

        $builder->refresh($this->project, '/repo', 'head1');
        $builder->refresh($this->project, '/repo', 'head1');
        self::assertCount(1, $runner->commands);

        $builder->refresh($this->project, '/repo', 'head2');
        self::assertCount(2, $runner->commands);
    }

    /** A git that fails is remembered as unavailable for that commit and does not surface. */
    public function testAGitThatFailsRecordsUnavailableAndDoesNotThrow(): void
    {
        $runner = new RecordingGitRunner(static function (): string {
            throw new RuntimeException('git timed out');
        });
        $builder = new FolderCompanionsBuilder($this->store, $runner);

        $lines = $this->errorLogOf(fn() => $builder->refresh($this->project, '/repo', 'head1'));
        $builder->refresh($this->project, '/repo', 'head1');

        self::assertSame('unavailable', $this->storedStatus());
        self::assertSame('head1', $this->store->companionsHead($this->project));
        self::assertCount(1, $runner->commands, 'the failure is not retried for the same commit');
        self::assertStringContainsString('knossos folder facts:', $lines);
        self::assertSame(1, substr_count(trim($lines), "\n") + 1);
    }

    /** No commit, nothing to ask and nothing to store. */
    public function testAProjectWithoutACommitIsLeftAlone(): void
    {
        $runner = new RecordingGitRunner();

        (new FolderCompanionsBuilder($this->store, $runner))->refresh($this->project, '/repo', null);

        self::assertSame([], $runner->commands);
        self::assertNull($this->store->companionsHead($this->project));
    }

    /** A sweeping commit does not count as an addition. */
    public function testASweepingCommitIsIgnored(): void
    {
        $sweep = [['A', 'src/Q/New.php']];
        foreach (range(1, 250) as $i) {
            $sweep[] = ['M', 'docs/page' . $i . '.md'];
        }
        $commits = [$sweep, $sweep, $sweep];

        (new FolderCompanionsBuilder($this->store, new RecordingGitRunner(static fn(): string => self::log($commits))))->refresh($this->project, '/repo', 'head1');

        self::assertSame([], $this->store->companionsOf($this->project, 'src/Q'));
    }

    /**
     * `git log -z --name-status` output for commits of `[status, path]` entries.
     *
     * @param list<list<array{string, string}>> $commits
     */
    private static function log(array $commits): string
    {
        $out = '';
        foreach ($commits as $i => $changes) {
            $out .= "\0KNOSSOS_COMPANIONS\x1fcommit" . $i . "\0\n";
            foreach ($changes as [$status, $path]) {
                $out .= $status . "\0" . $path . "\0";
            }
        }

        return $out;
    }

    private function storedStatus(): string
    {
        $statement = $this->pdo->prepare('SELECT status FROM folder_companions WHERE project_id = ?');
        $statement->execute([$this->project]);

        return (string) $statement->fetchColumn();
    }
}
