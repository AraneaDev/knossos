<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\FolderFactsStore;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Per-folder facts and companions: derived data that sits beside the graph and
 * is rewritten only where it changed, so a scan that touches nothing writes nothing.
 */
#[Group('store')]
final class FolderFactsStoreTest extends KnossosTestCase
{
    /** An unchanged row is not rewritten, and a changed or dropped row counts once. */
    public function testReplaceWritesOnlyRowsThatChanged(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $store = new FolderFactsStore($pdo);
        $rows = [
            self::row('src/Query', 'php', 'source', 12, ['kinds' => ['one_class' => 12]]),
            self::row('src/Store', 'php', 'source', 9, ['kinds' => ['one_class' => 9]]),
        ];

        self::assertSame(2, $store->replace($ids['project'], $rows));
        self::assertSame(0, $store->replace($ids['project'], $rows));

        $rows[1] = self::row('src/Store', 'php', 'source', 9, ['kinds' => ['one_class' => 8, 'mixed' => 1]]);
        self::assertSame(1, $store->replace($ids['project'], $rows));

        self::assertSame(1, $store->replace($ids['project'], [$rows[1]]));
        self::assertNull($store->of($ids['project'], 'src/Query', 'php', 'source'));
        self::assertSame(
            ['files' => 9, 'facts' => ['kinds' => ['mixed' => 1, 'one_class' => 8]]],
            $store->of($ids['project'], 'src/Store', 'php', 'source'),
        );
    }

    /** Facts equal as data are equal as bytes, whatever order their keys arrived in. */
    public function testKeyOrderDoesNotCountAsAChange(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $store = new FolderFactsStore($pdo);
        $store->replace($ids['project'], [self::row('', 'php', 'test', 5, ['a' => 1, 'b' => 2])]);

        self::assertSame(0, $store->replace($ids['project'], [self::row('', 'php', 'test', 5, ['b' => 2, 'a' => 1])]));
    }

    /** Replacing one project's rows never deletes or rewrites another's. */
    public function testRowsOfAnotherProjectAreUntouched(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $pdo->prepare("INSERT INTO projects(id, name, root_realpath, created_at, updated_at) VALUES ('other', 'Other', '/workspace/other', 'now', 'now')")->execute();
        $store = new FolderFactsStore($pdo);
        $store->replace('other', [self::row('src', 'php', 'source', 7, ['x' => 1])]);

        $store->replace($ids['project'], []);

        self::assertSame(['files' => 7, 'facts' => ['x' => 1]], $store->of('other', 'src', 'php', 'source'));
    }

    /** Companions are saved whole for a project and read back one directory at a time. */
    public function testCompanionsRoundTripPerDirectory(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $store = new FolderFactsStore($pdo);

        self::assertNull($store->companionsHead($ids['project']));
        self::assertSame([], $store->companionsOf($ids['project'], 'src/Query'));

        $store->saveCompanions($ids['project'], 'abc123', 'ok', [
            'src/Query' => [['path' => 'tests/QueryTest.php', 'commits' => 4, 'of' => 5]],
            'src/Store' => [['path' => 'migrations/x.sql', 'commits' => 3, 'of' => 3]],
        ]);

        self::assertSame('abc123', $store->companionsHead($ids['project']));
        self::assertEquals(
            [['path' => 'tests/QueryTest.php', 'commits' => 4, 'of' => 5]],
            $store->companionsOf($ids['project'], 'src/Query'),
        );
        self::assertSame([], $store->companionsOf($ids['project'], 'src/Other'));

        $store->saveCompanions($ids['project'], 'def456', 'unavailable', []);
        self::assertSame('def456', $store->companionsHead($ids['project']));
        self::assertSame([], $store->companionsOf($ids['project'], 'src/Query'));
    }

    /** A row that cannot be stored must not leave the earlier deletes and upserts of the same call behind. */
    public function testAFailingReplaceLeavesTheTableAsItWas(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $store = new FolderFactsStore($pdo);
        $store->replace($ids['project'], [self::row('src/Old', 'php', 'source', 5, [])]);

        $failed = false;
        try {
            $store->replace($ids['project'], [
                self::row('src/New', 'php', 'source', 6, []),
                self::row('src/Bad', 'php', 'source', 7, ['x' => NAN]),
            ]);
        } catch (\Throwable) {
            $failed = true;
        }

        self::assertTrue($failed);
        self::assertFalse($pdo->inTransaction());
        self::assertSame(1, self::rowCount($pdo, 'folder_facts'));
        self::assertNotNull($store->of($ids['project'], 'src/Old', 'php', 'source'));
    }

    /** A row that violates the role CHECK is rolled back the same way. */
    public function testARoleOutsideTheCheckRollsTheWholeReplaceBack(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $store = new FolderFactsStore($pdo);

        $failed = false;
        try {
            $store->replace($ids['project'], [
                self::row('src/A', 'php', 'source', 5, []),
                self::row('src/B', 'php', 'nonsense', 5, []),
            ]);
        } catch (\Throwable) {
            $failed = true;
        }

        self::assertTrue($failed);
        self::assertSame(0, self::rowCount($pdo, 'folder_facts'));
    }

    /** A caller's open transaction is joined, not committed behind its back. */
    public function testReplaceJoinsAnOpenTransaction(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $store = new FolderFactsStore($pdo);

        $pdo->beginTransaction();
        $store->replace($ids['project'], [self::row('src/A', 'php', 'source', 5, [])]);
        self::assertTrue($pdo->inTransaction());
        $pdo->rollBack();

        self::assertSame(0, self::rowCount($pdo, 'folder_facts'));
    }

    /** Both tables belong to the project: removing it removes them. */
    public function testRemovingTheProjectRemovesItsFacts(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $store = new FolderFactsStore($pdo);
        $store->replace($ids['project'], [self::row('src', 'php', 'source', 5, [])]);
        $store->saveCompanions($ids['project'], 'abc', 'ok', []);

        $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$ids['project']]);

        self::assertSame(0, self::rowCount($pdo, 'folder_facts'));
        self::assertSame(0, self::rowCount($pdo, 'folder_companions'));
    }

    /**
     * @param array<string, mixed> $facts
     * @return array{directory: string, extension: string, role: string, files: int, facts: array<string, mixed>}
     */
    private static function row(string $directory, string $extension, string $role, int $files, array $facts): array
    {
        return ['directory' => $directory, 'extension' => $extension, 'role' => $role, 'files' => $files, 'facts' => $facts];
    }

    private static function rowCount(PDO $pdo, string $table): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
