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

    /** The smallest history that can claim a companion: three additions, the path in all three. */
    public function testExactlyThreeAdditionsWithThePathInAllThreeGiveACompanion(): void
    {
        $commits = array_map(static fn(int $i): array => [['A', 'src/Q/X' . $i . '.php'], ['M', 'tools/list.txt']], range(1, 3));

        $this->refreshFrom($commits);

        self::assertEquals([['path' => 'tools/list.txt', 'commits' => 3, 'of' => 3]], $this->store->companionsOf($this->project, 'src/Q'));
    }

    /** Three of five is 60%, in; two of five is 40%, out. */
    public function testTheSixtyPercentShareIsInclusive(): void
    {
        $commits = [];
        foreach (range(1, 5) as $i) {
            $changes = [['A', 'src/Q/X' . $i . '.php']];
            if ($i <= 3) {
                $changes[] = ['M', 'in.txt'];
            }
            if ($i >= 4) {
                $changes[] = ['M', 'out.txt'];
            }
            $commits[] = $changes;
        }

        $this->refreshFrom($commits);

        self::assertEquals([['path' => 'in.txt', 'commits' => 3, 'of' => 5]], $this->store->companionsOf($this->project, 'src/Q'));
    }

    /** More than five qualifying paths keep five, the most common first and the path breaking ties. */
    public function testOnlyTheFiveBestCompanionsAreKeptInOrder(): void
    {
        $commits = [];
        foreach (range(1, 4) as $i) {
            $changes = [['A', 'src/Q/X' . $i . '.php']];
            foreach (['g', 'f', 'e', 'd', 'c', 'b', 'a'] as $letter) {
                // `a` in 4 commits, `b` to `g` in 3 each (all of them but the fourth for letters after `a`).
                if ($letter === 'a' || $i <= 3) {
                    $changes[] = ['M', 'docs/' . $letter . '.md'];
                }
            }
            $commits[] = $changes;
        }

        $this->refreshFrom($commits);

        self::assertSame(
            ['docs/a.md', 'docs/b.md', 'docs/c.md', 'docs/d.md', 'docs/e.md'],
            array_column($this->store->companionsOf($this->project, 'src/Q'), 'path'),
        );
    }

    /** Directories named in digits keep their names and their order; "10" is not read as a number. */
    public function testNumericDirectoriesAreKeptAsStrings(): void
    {
        $commits = array_map(static fn(int $i): array => [['A', '2024/X' . $i . '.php'], ['M', 'CHANGELOG.md']], range(1, 3));

        $this->refreshFrom($commits);

        self::assertEquals([['path' => 'CHANGELOG.md', 'commits' => 3, 'of' => 3]], $this->store->companionsOf($this->project, '2024'));
    }

    /** Parsing is checked against what git really writes for `--name-status -z`, not a hand-built string. */
    public function testCompanionsAreFoundInRealGitOutput(): void
    {
        if (self::locateGit() === null) {
            self::markTestSkipped('git is not available.');
        }
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/src/Q', 0o777, true);
        try {
            $this->runFixtureCommand(['git', 'init', '--quiet', $root]);
            foreach (['A', 'B', 'C'] as $name) {
                file_put_contents($root . '/src/Q/' . $name . '.php', "<?php\n");
                file_put_contents($root . '/registry.txt', $name . "\n", FILE_APPEND);
                $this->runFixtureCommand(['git', '-C', $root, 'add', '.']);
                $this->runFixtureCommand(['git', '-C', $root, '-c', 'user.name=Knossos Test', '-c', 'user.email=test@example.test', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'add ' . $name]);
            }

            (new FolderCompanionsBuilder($this->store))->refresh($this->project, $root, 'head1');

            self::assertSame('ok', $this->storedStatus());
            self::assertEquals(
                [['path' => 'registry.txt', 'commits' => 3, 'of' => 3]],
                $this->store->companionsOf($this->project, 'src/Q'),
            );
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Run the builder over commits of `[status, path]` entries.
     *
     * @param list<list<array{string, string}>> $commits
     */
    private function refreshFrom(array $commits): void
    {
        (new FolderCompanionsBuilder($this->store, new RecordingGitRunner(static fn(): string => self::log($commits))))->refresh($this->project, '/repo', 'head1');
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
