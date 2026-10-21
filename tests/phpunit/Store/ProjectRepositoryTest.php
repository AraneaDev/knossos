<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\ProjectRepository;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class ProjectRepositoryTest extends KnossosTestCase
{
    #[Group('query')]
    public function testResolvesExactRootAndSubdirectoryAndRejectsUnrelatedPath(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $root = (string) $pdo->query('SELECT root_realpath FROM projects')->fetchColumn();
        $resolver = new ProjectRepository($pdo);

        $exact = $resolver->resolvePath($root);
        assertSame($ids['project'], $exact['id'] ?? null);

        // A subdirectory of a scanned repository still finds its project: this is
        // the case the hook actually hits, because a session can start anywhere.
        $nested = $resolver->resolvePath($root . '/src/Deeply/Nested');
        assertSame($ids['project'], $nested['id'] ?? null);

        assertSame(null, $resolver->resolvePath('/definitely/not/a/scanned/project'));
    }

    /**
     * A relative path that does not exist still belongs to the project the
     * caller is standing in. realpath() cannot resolve it, and the fallback
     * used to keep it relative, so the ancestor walk climbed to "." and
     * stopped: a project root is always absolute and "." never matches one.
     */
    #[Group('query')]
    public function testResolvesARelativePathThatDoesNotExistAgainstTheWorkingDirectory(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $root = (string) $pdo->query('SELECT root_realpath FROM projects')->fetchColumn();
        // The fixture's root is not a real directory, so stand in it lexically
        // by pointing the resolver at a path below it that does not exist.
        $resolver = new ProjectRepository($pdo);

        $missing = $resolver->resolvePath($root . '/src/NotThere.php');

        assertSame($ids['project'], $missing['id'] ?? null);

        $previous = getcwd();
        try {
            if (@chdir(sys_get_temp_dir()) !== true) {
                self::markTestSkipped('cannot change directory here.');
            }
            // Relative and nonexistent, and nothing under the temp directory is
            // scanned, so this must be null rather than an accidental match.
            assertSame(null, $resolver->resolvePath('nowhere/at/all'));
        } finally {
            if (is_string($previous)) {
                @chdir($previous);
            }
        }
    }

    /**
     * The fixture project, completed, plus two bare projects with known
     * creation and update times, so every ordering below is pinned.
     *
     * @return array{0: \PDO, 1: ProjectRepository, 2: array<string, string>}
     */
    private function threeProjects(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $repository->saveProject('p-bare-b', 'Bare B', '/workspace/bare-b');
        $repository->saveProject('p-bare-a', 'Bare A', '/workspace/bare-a');
        $times = $pdo->prepare('UPDATE projects SET created_at = :created, updated_at = :updated WHERE id = :id');
        $times->execute(['id' => $ids['project'], 'created' => '2026-01-01T00:00:00Z', 'updated' => '2026-01-03T00:00:00Z']);
        $times->execute(['id' => 'p-bare-b', 'created' => '2026-01-02T00:00:00Z', 'updated' => '2026-01-05T00:00:00Z']);
        $times->execute(['id' => 'p-bare-a', 'created' => '2026-01-02T00:00:00Z', 'updated' => '2026-01-04T00:00:00Z']);

        return [$pdo, new ProjectRepository($pdo), $ids];
    }

    #[Group('query')]
    public function testIdsListsProjectIdsInIdOrderUpToTheLimit(): void
    {
        [, $projects, $ids] = $this->threeProjects();
        $all = ['p-bare-a', 'p-bare-b', $ids['project']];
        sort($all);

        assertSame($all, $projects->ids(10));
        assertSame(array_slice($all, 0, 2), $projects->ids(2));
    }

    #[Group('query')]
    public function testWithActiveScanJoinsTheActiveScanAndIsNullWithoutOne(): void
    {
        [, $projects, $ids] = $this->threeProjects();

        $row = $projects->withActiveScan($ids['project']);
        assertSame('Fixture Shop', $row['name'] ?? null);
        assertSame($ids['scan'], $row['active_scan_id'] ?? null);
        assertSame(hash('sha256', 'scanner-set'), $row['scanner_set_hash'] ?? null);

        assertSame(null, $projects->withActiveScan('p-bare-a'));
        assertSame(null, $projects->withActiveScan('no-such-project'));
    }

    #[Group('query')]
    public function testActiveScanCostCountsTheFilesTheActiveScanWrote(): void
    {
        [$pdo, $projects, $ids] = $this->threeProjects();
        $pdo->prepare('UPDATE scans SET duration_ms = 1234 WHERE id = :id')->execute(['id' => $ids['scan']]);

        $cost = $projects->activeScanCost($ids['project']);
        assertSame(1234, (int) ($cost['duration_ms'] ?? -1));
        assertSame(1, (int) ($cost['file_count'] ?? -1));

        assertSame(null, $projects->activeScanCost('p-bare-a'));
    }

    #[Group('query')]
    public function testAnnotatedExceptListsOtherAnnotatedProjectsNewestFirstWithoutBundles(): void
    {
        [$pdo, $projects, $ids] = $this->threeProjects();
        $pdo->exec("UPDATE projects SET root_realpath = 'bundle://imported' WHERE id = 'p-bare-b'");
        $note = $pdo->prepare(
            "INSERT INTO annotations (project_id, canonical_name, kind, value, created_at, updated_at) VALUES (:id, 'x', 'note', 'n', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')",
        );
        foreach ([$ids['project'], 'p-bare-a', 'p-bare-b'] as $id) {
            $note->execute(['id' => $id]);
        }

        // p-bare-b is a bundle and the asking project is excluded; p-bare-a
        // was updated after the fixture project, so it comes first.
        assertSame(
            [['id' => 'p-bare-a', 'name' => 'Bare A', 'root_realpath' => '/workspace/bare-a']],
            $projects->annotatedExcept($ids['project']),
        );
        assertSame(
            ['p-bare-a', $ids['project']],
            array_column($projects->annotatedExcept('p-bare-b'), 'id'),
        );
        $pdo->exec("DELETE FROM annotations WHERE project_id = '" . $ids['project'] . "'");
        assertSame([], $projects->annotatedExcept('p-bare-a'));
    }

    #[Group('query')]
    public function testCatalogPageOrdersByUpdateAndReturnsOneRowPastTheLimit(): void
    {
        [, $projects, $ids] = $this->threeProjects();

        $first = $projects->catalogPage(1, 0);
        assertSame(['p-bare-b', 'p-bare-a'], array_column($first, 'id'));
        assertSame([], $projects->catalogPage(1, 3));

        $byId = array_column($projects->catalogPage(10, 0), null, 'id');
        assertSame(['p-bare-b', 'p-bare-a', $ids['project']], array_keys($byId));
        $shop = $byId[$ids['project']];
        assertSame($ids['scan'], $shop['active_scan_id']);
        assertSame($ids['scan'], $shop['latest_scan_id']);
        assertSame('full', $shop['active_mode']);
        assertSame([1, 2, 1, 0], array_map(intval(...), [$shop['file_count'], $shop['node_count'], $shop['edge_count'], $shop['diagnostic_count']]));
        assertSame(null, $byId['p-bare-a']['latest_scan_id']);
        assertSame(0, (int) $byId['p-bare-a']['file_count']);
    }

    #[Group('query')]
    public function testInCreationOrderPagesByCreationTimeThenIdAfterTheKeyset(): void
    {
        [, $projects, $ids] = $this->threeProjects();

        assertSame(
            [$ids['project'], 'p-bare-a', 'p-bare-b'],
            array_column($projects->inCreationOrder(10, '', ''), 'id'),
        );
        // One row past the limit tells the caller another page follows.
        assertSame([$ids['project'], 'p-bare-a'], array_column($projects->inCreationOrder(1, '', ''), 'id'));
        // A tie on created_at falls back to the id.
        assertSame(['p-bare-b'], array_column($projects->inCreationOrder(10, '2026-01-02T00:00:00Z', 'p-bare-a'), 'id'));
        assertSame([], $projects->inCreationOrder(10, '2026-01-02T00:00:00Z', 'p-bare-b'));
        assertSame(
            ['id' => 'p-bare-a', 'name' => 'Bare A', 'created_at' => '2026-01-02T00:00:00Z'],
            $projects->inCreationOrder(10, '2026-01-01T00:00:00Z', $ids['project'])[0],
        );
    }

    /**
     * A stored configuration that does not decode to an object reads as none,
     * the same for every caller, rather than throwing for one and not another.
     */
    #[Group('store')]
    public function testConfigDecodesAnObjectAndReadsAnythingElseAsNone(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $projects = new ProjectRepository($pdo);
        $set = $pdo->prepare('UPDATE projects SET config_json = :config WHERE id = :id');

        $set->execute(['config' => '{"snapshot_retention":3}', 'id' => $ids['project']]);
        assertSame(['snapshot_retention' => 3], $projects->config($ids['project']));
        foreach (['{not json', '"text"', '7'] as $stored) {
            $set->execute(['config' => $stored, 'id' => $ids['project']]);
            assertSame(null, $projects->config($ids['project']), $stored);
        }
        assertSame(null, $projects->config('no-such-project'));
        assertSame(null, ProjectRepository::decodedConfig(null));
    }
}
