<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Scan\Conventions\SupportThreshold;
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

        self::assertSame(['<dir>/<name>.test.php', 'tests/a/<name>Test.php'], self::patterns($store, $ids['project'], 'php'));
        self::assertSame([], self::patterns($store, $ids['project'], 'rs'));
    }

    /** A folder below the file floor does not count, however completely its one file follows the pattern. */
    public function testAFolderBelowTheFileFloorDoesNotCount(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $store = new FolderFactsStore($pdo);
        $store->replace($ids['project'], [
            ['directory' => 'src/one', 'extension' => 'php', 'role' => 'source', 'files' => 1, 'facts' => ['test_path' => ['pattern' => '<dir>/<name>Spec.php', 'files' => 1]]],
            ['directory' => 'src/four', 'extension' => 'php', 'role' => 'source', 'files' => 4, 'facts' => ['test_path' => ['pattern' => '<dir>/<name>Check.php', 'files' => 4]]],
        ]);

        self::assertSame([], self::patterns($store, $ids['project'], 'php'));
        self::assertSame(['<dir>/<name>Check.php', '<dir>/<name>Spec.php'], $store->testPathPatterns($ids['project'], 'php', 1, 4, 5), 'the floor is the caller\'s, not the store\'s');
    }

    /** @return list<string> the patterns at the threshold a brief applies */
    private static function patterns(FolderFactsStore $store, string $project, string $extension): array
    {
        return $store->testPathPatterns($project, $extension, SupportThreshold::MIN_FILES, SupportThreshold::SHARE_NUMERATOR, SupportThreshold::SHARE_DENOMINATOR);
    }
}
