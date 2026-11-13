<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\FolderFactsStore;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The test-path patterns a project's source folders show, read across folders. */
#[Group('store')]
final class FolderFactsStoreTestPathsTest extends KnossosTestCase
{
    /** Only a pattern that four in five of a folder's files follow counts; the result is distinct and sorted. */
    public function testOnlyWellSupportedPatternsAreReturnedSortedAndDistinct(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $store = new FolderFactsStore($pdo);
        $row = static fn(string $directory, string $extension, string $role, int $files, array $facts): array => ['directory' => $directory, 'extension' => $extension, 'role' => $role, 'files' => $files, 'facts' => $facts];
        $store->replace($ids['project'], [
            $row('src/a', 'php', 'source', 5, ['test_path' => ['pattern' => 'tests/a/<name>Test.php', 'files' => 4]]),
            $row('src/b', 'php', 'source', 5, ['test_path' => ['pattern' => '<dir>/<name>Spec.php', 'files' => 3]]),
            $row('src/c', 'php', 'source', 5, ['test_path' => ['pattern' => 'tests/a/<name>Test.php', 'files' => 5]]),
            $row('src/d', 'php', 'source', 5, ['test_path' => ['pattern' => '<dir>/<name>.test.php', 'files' => 5]]),
            $row('src/e', 'ts', 'source', 5, ['test_path' => ['pattern' => '<dir>/<name>.spec.ts', 'files' => 5]]),
            $row('tests/a', 'php', 'test', 5, []),
        ]);

        self::assertSame(['<dir>/<name>.test.php', 'tests/a/<name>Test.php'], $store->testPathPatterns($ids['project'], 'php'));
        self::assertSame([], $store->testPathPatterns($ids['project'], 'rs'));
    }
}
