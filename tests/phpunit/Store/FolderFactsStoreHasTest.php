<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\FolderFactsStore;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Whether a project has any folder facts, which tells a scan that changed nothing whether to build them. */
#[Group('store')]
final class FolderFactsStoreHasTest extends KnossosTestCase
{
    /** No rows, no facts; one row of this project, facts; a row of another project does not count. */
    public function testHasSeesOnlyThisProjectsRows(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $store = new FolderFactsStore($pdo);

        self::assertFalse($store->has($ids['project']));

        $store->replace($ids['project'], [['directory' => 'src', 'extension' => 'php', 'role' => 'source', 'files' => 1, 'facts' => []]]);

        self::assertTrue($store->has($ids['project']));
        self::assertFalse($store->has('another-project'));
    }
}
