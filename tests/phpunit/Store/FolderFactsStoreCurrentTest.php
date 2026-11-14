<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\FolderFactsStore;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Whether a project's folder facts are there and of the version the builder derives, which tells a scan that changed nothing whether to rebuild them. */
#[Group('store')]
final class FolderFactsStoreCurrentTest extends KnossosTestCase
{
    /** No rows, not current; rows all of the version, current; a row of another project does not count. */
    public function testOnlyThisProjectsRowsOfTheVersionAreCurrent(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $store = new FolderFactsStore($pdo);

        self::assertFalse($store->current($ids['project'], 2));

        $store->replace($ids['project'], [self::row('src', ['v' => 2]), self::row('lib', ['v' => 2, 'kinds' => ['one_class' => 1]])]);

        self::assertTrue($store->current($ids['project'], 2));
        self::assertFalse($store->current($ids['project'], 3), 'facts of an older version');
        self::assertFalse($store->current('another-project', 2));
    }

    /** One row without a version, or with another one, makes the project's facts out of date. */
    public function testOneRowOfAnotherVersionIsEnough(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $store = new FolderFactsStore($pdo);

        $store->replace($ids['project'], [self::row('src', ['v' => 2]), self::row('lib', [])]);
        self::assertFalse($store->current($ids['project'], 2), 'a row with no version');

        $store->replace($ids['project'], [self::row('src', ['v' => 2]), self::row('lib', ['v' => 1])]);
        self::assertFalse($store->current($ids['project'], 2), 'a row of version 1');
    }

    /**
     * @param array<string, mixed> $facts
     * @return array{directory: string, extension: string, role: string, files: int, facts: array<string, mixed>}
     */
    private static function row(string $directory, array $facts): array
    {
        return ['directory' => $directory, 'extension' => 'php', 'role' => 'source', 'files' => 1, 'facts' => $facts];
    }
}
