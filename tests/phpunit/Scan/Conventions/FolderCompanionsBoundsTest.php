<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan\Conventions;

use Knossos\Scan\Conventions\FolderCompanionsBuilder;
use Knossos\Store\FolderFactsStore;
use Knossos\Tests\Phpunit\Git\RecordingGitRunner;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The history a scan may read is bounded in what it counts, and what it stores is always encodable. */
#[Group('scan')]
final class FolderCompanionsBoundsTest extends KnossosTestCase
{
    private FolderFactsStore $store;

    private string $project;

    protected function setUp(): void
    {
        parent::setUp();
        [$pdo, , $ids] = $this->storeFixture();
        $this->store = new FolderFactsStore($pdo);
        $this->project = $ids['project'];
    }

    /** A commit that adds a file in 21 folders is a sweep and contributes nothing; 20 folders still count. */
    public function testACommitAddingIntoTooManyFoldersIsSkipped(): void
    {
        foreach ([20 => true, 21 => false] as $folders => $counts) {
            $commits = array_map(static function (int $i) use ($folders): array {
                $changes = [['M', 'tools/list.txt']];
                foreach (range(1, $folders) as $d) {
                    $changes[] = ['A', 'dir' . $d . '/X' . $i . '.php'];
                }

                return $changes;
            }, range(1, 3));

            $this->refresh(new FolderCompanionsBuilder($this->store, new RecordingGitRunner(fn(): string => self::log($commits))), 'head' . $folders);

            self::assertSame($counts, $this->store->companionsOf($this->project, 'dir1') !== [], $folders . ' folders');
        }
    }

    /** Past the cap a new (folder, path) key is ignored, while keys already counted keep counting. */
    public function testNewCountersPastTheCapAreIgnoredButExistingOnesKeepCounting(): void
    {
        $commits = array_map(static fn(int $i): array => [['A', 'src/Q/X' . $i . '.php'], ['M', 'first.txt'], ['M', 'second.txt']], range(1, 3));

        $this->refresh(new FolderCompanionsBuilder($this->store, new RecordingGitRunner(fn(): string => self::log($commits)), 1), 'head1');

        self::assertEquals([['path' => 'first.txt', 'commits' => 3, 'of' => 3]], $this->store->companionsOf($this->project, 'src/Q'));
    }

    /** A path that is not UTF-8 would make JSON encoding throw and discard everything, so it is left out. */
    public function testAPathThatIsNotUtf8IsSkippedAndTheOthersAreStored(): void
    {
        $commits = array_map(static fn(int $i): array => [['A', 'src/Q/X' . $i . '.php'], ['M', "bad\xFF.txt"], ['M', 'good.txt']], range(1, 3));

        $this->refresh(new FolderCompanionsBuilder($this->store, new RecordingGitRunner(fn(): string => self::log($commits))), 'head1');

        self::assertEquals([['path' => 'good.txt', 'commits' => 3, 'of' => 3]], $this->store->companionsOf($this->project, 'src/Q'));
    }

    private function refresh(FolderCompanionsBuilder $builder, string $head): void
    {
        $builder->refresh($this->project, '/repo', $head);
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
}
