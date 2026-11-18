<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\SnapshotGraphReader;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * Test doubles and test cases are facts derived per scan, like findings:
 * their identities carry a line, so a snapshot holding them would report a
 * comment added to a test file as architecture drift. Snapshots, snapshot
 * diffs and the trends built from them leave them out.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class TestFactsSnapshotTest extends KnossosTestCase
{
    private const FIXTURES = '/tests/Fixtures/test-fidelity/typescript';

    public function testACommentAtTheTopOfATestFileIsNoSnapshotChange(): void
    {
        [$pdo, $service, $root] = $this->scanned();
        $first = $service->scan($root);
        $testFile = $root . '/tests/UserService.spec.ts';
        file_put_contents($testFile, "// a comment\n" . (string) file_get_contents($testFile));
        $service->scan($root);

        $diff = ArchitectureQueryService::forDatabase($pdo)->services()->diffQueries->snapshotDiff($first->projectId, (string) $first->snapshotId, maxChanges: 1000);

        // What a line shift moves in any file stays as it was: the module's
        // lines, the calls written lower down, a diagnostic's line. Nothing is
        // added or removed, and no change is a test fact.
        $changes = $diff->data['changes'];
        foreach (['components', 'relationships', 'roles', 'boundary_memberships'] as $section) {
            assertSame([0, 0], [$changes[$section]['counts']['added'], $changes[$section]['counts']['removed']], $section);
        }
        assertSame([], self::testFactChanges($changes));
        assertSame(4, $changes['relationships']['counts']['moved']);
    }

    public function testASnapshotHoldsNoTestFacts(): void
    {
        [$pdo, $service, $root] = $this->scanned();
        $first = $service->scan($root);
        // The next scan archives the first.
        file_put_contents($root . '/tests/UserService.spec.ts', "// a comment\n" . (string) file_get_contents($root . '/tests/UserService.spec.ts'));
        $service->scan($root);
        assertGreaterThan(0, (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind IN ('test_double', 'test_case')")->fetchColumn());

        $kinds = [];
        foreach (['nodes', 'edges'] as $table) {
            (new SnapshotGraphReader($pdo))->eachArchivedRow((string) $first->snapshotId, $table, static function (array $row) use (&$kinds): void {
                $kinds[$row['kind']] = true;
            });
        }

        assertSame([], array_values(array_intersect(array_keys($kinds), ['test_double', 'test_case', 'doubles'])));
        assertSame(true, isset($kinds['module'], $kinds['contains']));
    }

    /**
     * Every reported change whose row is a test fact or an edge of one.
     *
     * @param array<string, array<string, mixed>> $changes
     * @return list<string>
     */
    private static function testFactChanges(array $changes): array
    {
        $found = [];
        array_walk_recursive($changes, static function (mixed $value, string|int $key) use (&$found): void {
            if (($key === 'kind' && in_array($value, ['test_double', 'test_case', 'doubles'], true))
                || ($key === 'canonical_name' && is_string($value) && preg_match('/#(double|test):\d/', $value) === 1)) {
                $found[] = (string) $value;
            }
        });

        return $found;
    }

    public function testAProductionChangeStillShows(): void
    {
        [$pdo, $service, $root] = $this->scanned();
        $first = $service->scan($root);
        $production = $root . '/src/UserService.ts';
        file_put_contents($production, str_replace('updateProfile(', 'patchProfile(', (string) file_get_contents($production)));
        $service->scan($root);

        $diff = ArchitectureQueryService::forDatabase($pdo)->services()->diffQueries->snapshotDiff($first->projectId, (string) $first->snapshotId, maxChanges: 1000);

        assertGreaterThan(0, $diff->data['changes']['components']['counts']['added']);
        assertGreaterThan(0, $diff->data['changes']['components']['counts']['removed']);
    }

    /** @return array{0: \PDO, 1: ProjectScanService, 2: string} */
    private function scanned(): array
    {
        $root = self::scratchPath('ts-test-facts-snapshot');
        foreach (['nemesis/src' => 'src', 'nemesis/tests' => 'tests'] as $from => $to) {
            $this->copyTree(self::repositoryRoot() . self::FIXTURES . '/' . $from, $root . '/' . $to);
        }
        $pdo = $this->freshTestDatabase();

        return [$pdo, new ProjectScanService($pdo, self::repositoryRoot(), [$root]), $root];
    }
}
