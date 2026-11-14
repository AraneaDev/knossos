<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\FolderFactsStore;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The populations of a directory and of every directory below it, for one extension and role. */
#[Group('store')]
final class FolderFactsStoreUnderTest extends KnossosTestCase
{
    /** A directory gives its own row and the rows below it, in directory order, and nothing beside it, whatever the case. */
    public function testADirectoryGivesItselfAndWhatIsBelowIt(): void
    {
        [$store, $project] = $this->stored();

        self::assertSame([
            ['directory' => 'src/a', 'files' => 2, 'facts' => ['kinds' => ['one_class' => 2]]],
            ['directory' => 'src/a/deep', 'files' => 4, 'facts' => []],
            ['directory' => 'src/a/x', 'files' => 1, 'facts' => []],
        ], $store->under($project, 'src/a', 'php', 'source'));
        self::assertSame([], $store->under($project, 'src/none', 'php', 'source'));
    }

    /** The root holds every directory of the extension and role. */
    public function testTheRootGivesEveryRow(): void
    {
        [$store, $project] = $this->stored();

        self::assertSame(['', 'lib', 'src/A/up', 'src/a', 'src/a%b', 'src/a/deep', 'src/a/x', 'src/a_b', 'src/ab', 'w/a%b', 'w/a/zb/c', 'w/aXb/c', 'w/a_b'], array_column($store->under($project, '', 'php', 'source'), 'directory'));
        self::assertSame(['tests'], array_column($store->under($project, '', 'php', 'test'), 'directory'));
    }

    /** A `%` or `_` in the directory's name is a character, not a wildcard. */
    public function testWildcardsInTheNameAreMatchedLiterally(): void
    {
        [$store, $project] = $this->stored();

        self::assertSame(['w/a%b'], array_column($store->under($project, 'w/a%b', 'php', 'source'), 'directory'));
        self::assertSame(['w/a_b'], array_column($store->under($project, 'w/a_b', 'php', 'source'), 'directory'));
    }

    /** @return array{FolderFactsStore, string} */
    private function stored(): array
    {
        [$pdo, , $ids] = $this->storeFixture();
        $store = new FolderFactsStore($pdo);
        $row = static fn(string $directory, int $files, string $extension = 'php', string $role = 'source', array $facts = []): array => ['directory' => $directory, 'extension' => $extension, 'role' => $role, 'files' => $files, 'facts' => $facts];
        $store->replace($ids['project'], [
            $row('', 1),
            $row('lib', 3),
            $row('src/a', 2, facts: ['kinds' => ['one_class' => 2]]),
            $row('src/a', 2, 'ts'),
            $row('src/a/deep', 4),
            $row('src/a/x', 1),
            $row('src/a%b', 1),
            $row('src/a_b', 1),
            $row('src/ab', 1),
            $row('src/A/up', 1),
            $row('w/a%b', 1),
            $row('w/a_b', 1),
            $row('w/aXb/c', 1),
            $row('w/a/zb/c', 1),
            $row('tests', 5, role: 'test'),
        ]);

        return [$store, $ids['project']];
    }
}
