<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\SqliteGraphPruner;
use Knossos\Store\SqliteStatementCache;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * An incremental rescan deletes only what it no longer produced and restamps
 * the rest. Getting the difference wrong either leaves stale rows behind or
 * deletes live ones, and both look like a successful scan.
 */
final class SqliteGraphPrunerTest extends KnossosTestCase
{
    #[Group('store')]
    public function testExistingIdsAreReadPerTableAndMembershipsByPair(): void
    {
        [$pdo, , $ids] = $this->storeFixture();

        $existing = (new SqliteGraphPruner(new SqliteStatementCache($pdo)))->existingGraphIds($ids['project']);

        assertSame(true, isset($existing['nodes'][$ids['checkout']]));
        assertSame(true, isset($existing['nodes'][$ids['invoice']]));
        assertSame(true, isset($existing['files'][$ids['file']]));
        assertSame([], $existing['boundary_memberships']);
    }

    #[Group('store')]
    public function testPruneDeletesOnlyWhatTheScanNoLongerProduced(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $pruner = new SqliteGraphPruner(new SqliteStatementCache($pdo));
        $existing = $pruner->existingGraphIds($ids['project']);
        $desired = $existing;
        unset($desired['edges']);

        $pruner->pruneGraph($ids['project'], $existing, $desired);

        assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn());
        assertSame('2', (string) $pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn());
    }

    /** The project still guards the delete: an id that belongs to another project is left alone. */
    #[Group('store')]
    public function testPruneLeavesARowOfAnotherProjectAlone(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $pruner = new SqliteGraphPruner(new SqliteStatementCache($pdo));
        $existing = $pruner->existingGraphIds($ids['project']);

        $pruner->pruneGraph('another-project', $existing, []);

        assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn());
        assertSame('2', (string) $pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn());
        assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    /**
     * Obsolete rows of the large tables are found by their primary key. Found
     * through a project index, each chunk of ids read every row of the
     * project, so pruning cost the size of the project per chunk.
     */
    #[Group('store')]
    public function testTheLargeTablesArePrunedByPrimaryKey(): void
    {
        [$pdo] = $this->storeFixture();

        foreach (['edges', 'nodes', 'classifications'] as $table) {
            $plan = $pdo->prepare('EXPLAIN QUERY PLAN ' . SqliteGraphPruner::pruneSql($table, array_fill(0, 400, 'id')));
            $plan->execute([...array_fill(0, 400, 'id'), 'project']);
            $detail = implode(' | ', array_column($plan->fetchAll(), 'detail'));

            assertSame(1, preg_match('/\\(id=\\?\\)/', $detail), $table . ': ' . $detail);
            assertSame(false, str_contains($detail, 'project_id'), $table . ': ' . $detail);
        }
        assertSame('DELETE FROM files WHERE id IN (?,?) AND project_id = ?', SqliteGraphPruner::pruneSql('files', ['a', 'b']));
        assertSame('DELETE FROM edges WHERE id IN (?,?) AND +project_id = ?', SqliteGraphPruner::pruneSql('edges', ['a', 'b']));
    }

    #[Group('store')]
    public function testPruningNothingChangedDeletesNothing(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $pruner = new SqliteGraphPruner(new SqliteStatementCache($pdo));
        $existing = $pruner->existingGraphIds($ids['project']);

        $pruner->pruneGraph($ids['project'], $existing, $existing);

        assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn());
    }

    #[Group('store')]
    public function testStampingMovesEveryRowOntoTheConfirmingScan(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->createScan('scan_new', $ids['project'], 'full', 'h');

        (new SqliteGraphPruner(new SqliteStatementCache($pdo)))->stampGraphScan($ids['project'], 'scan_new');

        foreach (['files', 'nodes', 'edges'] as $table) {
            assertSame('0', (string) $pdo->query(sprintf("SELECT COUNT(*) FROM %s WHERE last_scan_id <> 'scan_new'", $table))->fetchColumn());
        }
    }
}
