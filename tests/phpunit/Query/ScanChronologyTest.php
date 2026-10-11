<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ProjectCatalogQueryService;
use Knossos\Query\StalenessProbe;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

/**
 * Which scan came last follows the order the scans were made in, not their
 * ids. A scan's start is stored to the second and its id is a hash, so two
 * attempts in the same second used to be ordered by chance.
 */
final class ScanChronologyTest extends KnossosTestCase
{
    /**
     * A failed attempt, then a successful scan, both in the same second as the
     * first: the failed one's id sorts after the successful one's, so ordering
     * by id took the failed attempt for the latest.
     *
     * @return array{0: PDO, 1: string}
     */
    private function failedThenFixedInOneSecond(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $second = '2026-10-11T09:00:00Z';
        $pdo->prepare('UPDATE scans SET started_at = :at WHERE id = :id')->execute(['at' => $second, 'id' => $ids['scan']]);
        $insert = $pdo->prepare("INSERT INTO scans (id, project_id, mode, status, scanner_set_hash, started_at, finished_at) VALUES (:id, :project, 'full', :status, 'set', :at, :at)");
        $insert->execute(['id' => 'scan_ffff', 'project' => $ids['project'], 'status' => 'failed', 'at' => $second]);
        $insert->execute(['id' => 'scan_0000', 'project' => $ids['project'], 'status' => 'complete', 'at' => $second]);
        $pdo->prepare('UPDATE projects SET active_scan_id = :scan WHERE id = :project')->execute(['scan' => 'scan_0000', 'project' => $ids['project']]);

        return [$pdo, $ids['project']];
    }

    #[Group('query')]
    public function testAFailedAttemptBeforeTheActiveScanInTheSameSecondDoesNotMakeItStale(): void
    {
        [$pdo, $project] = $this->failedThenFixedInOneSecond();

        $staleness = (new StalenessProbe($pdo))->probe($project);

        // The fixture's root does not exist, so the graph cannot be verified, but nothing newer failed.
        assertNotSame('stale', $staleness['state'] ?? null);
        assertSame('unverified', $staleness['state'] ?? null);
    }

    #[Group('query')]
    public function testTheCatalogTakesTheLastScanMadeForTheLatest(): void
    {
        [$pdo, $project] = $this->failedThenFixedInOneSecond();

        $listed = (new ProjectCatalogQueryService($pdo))->listProjects(10, 0, false)->data['projects'];
        $entry = array_values(array_filter($listed, static fn(array $p): bool => $p['id'] === $project))[0];

        assertSame('root_unavailable', $entry['freshness']);
    }
}
