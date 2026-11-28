<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * `snapshot_diff` and `architecture_trends` count the test findings each
 * snapshot was archived with, by key, with the project's suppressions applied.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class TestFindingsCountedTest extends KnossosTestCase
{
    private const FIXTURE = '/tests/Fixtures/test-fidelity/ts-audit';
    private const RENAMED = "export class UserService {\n  getProfileV2(id: string): string {\n    return id;\n  }\n}\n";

    public function testARenameThatStrandsAMockIsOneAddedFinding(): void
    {
        [$pdo, $service, $root] = $this->healthy();
        $baseline = $service->scan($root);
        $original = (string) file_get_contents($root . '/src/UserService.ts');
        file_put_contents($root . '/src/UserService.ts', self::RENAMED);
        $ghosted = $service->scan($root)->snapshotId;

        $section = $this->diff($pdo, $baseline->projectId, (string) $baseline->snapshotId)->data['test_findings'];

        assertSame(['added' => 1, 'removed' => 0], $section['counts']);
        assertSame('GHOST_MEMBER', $section['added'][0]['category']);
        assertSame(['key', 'category', 'sub_rule', 'confidence', 'file', 'message'], array_keys($section['added'][0]));
        assertSame([], $section['removed']);
        assertSame(false, $section['truncated']);

        // Renaming back removes it: the scan before is the one with the ghost.
        file_put_contents($root . '/src/UserService.ts', $original);
        $service->scan($root);
        $back = $this->diff($pdo, $baseline->projectId, (string) $ghosted)->data['test_findings'];
        assertSame(['added' => 0, 'removed' => 1], $back['counts']);
    }

    public function testASnapshotWithNoRecordIsNotRecorded(): void
    {
        [$pdo, $service, $root] = $this->healthy();
        $baseline = $service->scan($root);
        file_put_contents($root . '/src/Extra.ts', "export const extra = 1;\n");
        $service->scan($root);
        $pdo->exec('UPDATE scan_snapshots SET test_findings_recorded = 0');
        $pdo->exec('DELETE FROM snapshot_test_findings');

        $section = $this->diff($pdo, $baseline->projectId, (string) $baseline->snapshotId)->data['test_findings'];

        assertSame(['status' => 'not_recorded', 'side' => 'from'], $section);
    }

    public function testASuppressedFindingIsOnNeitherSide(): void
    {
        [$pdo, $service, $root] = $this->healthy();
        $baseline = $service->scan($root);
        file_put_contents($root . '/src/UserService.ts', self::RENAMED);
        $service->scan($root);
        file_put_contents($root . '/knossos.json', json_encode(['version' => 1, 'test_finding_suppressions' => [['rule' => 'GHOST_MEMBER']]]));

        $section = $this->diff($pdo, $baseline->projectId, (string) $baseline->snapshotId)->data['test_findings'];

        assertSame(['added' => 0, 'removed' => 0], $section['counts']);
    }

    /** The archived side: a ghost the baseline recorded and the active scan no longer has is removed, until it is suppressed. */
    public function testASuppressedFindingOfTheArchivedSideIsNotRemoved(): void
    {
        [$pdo, $service, $root] = $this->unhealthy();
        $baseline = $service->scan($root);
        $this->heal($root);
        $service->scan($root);
        $removed = static fn(array $section): array => array_column($section['removed'], 'category');

        $before = $this->diff($pdo, $baseline->projectId, (string) $baseline->snapshotId)->data['test_findings'];
        assertSame(true, in_array('GHOST_MEMBER', $removed($before), true));

        file_put_contents($root . '/knossos.json', json_encode(['version' => 1, 'test_finding_suppressions' => [['rule' => 'GHOST_MEMBER']]]));
        $after = $this->diff($pdo, $baseline->projectId, (string) $baseline->snapshotId)->data['test_findings'];

        assertSame(false, in_array('GHOST_MEMBER', $removed($after), true));
        assertSame($before['counts']['removed'] - count(array_keys($removed($before), 'GHOST_MEMBER', true)), $after['counts']['removed']);
    }

    /** What the graph's changes leave of `max_changes` is all the section may report, and only the section's overflow truncates. */
    public function testMaxChangesBoundsTheSectionWhatTheGraphLeaves(): void
    {
        [$pdo, $service, $root] = $this->healthy();
        $baseline = $service->scan($root);
        $this->unheal($root);
        $service->scan($root);
        $diffs = ArchitectureQueryService::forDatabase($pdo)->services()->diffQueries;
        $project = $baseline->projectId;
        $from = (string) $baseline->snapshotId;
        $full = $diffs->snapshotDiff($project, $from, 'active', 1000);
        $found = $full->data['test_findings']['counts']['added'];
        assertSame(true, $found >= 2, 'the fixture adds at least two findings');
        assertSame(false, $full->truncated);
        $graph = $full->data['bounds']['total_changes'] + $full->data['changes']['components']['counts']['rename_candidates'];

        $tight = $diffs->snapshotDiff($project, $from, 'active', $graph + 1);

        $section = $tight->data['test_findings'];
        assertSame($found, $section['counts']['added']);
        assertCount(1, $section['added']);
        assertCount(0, $section['removed']);
        assertSame(true, $section['truncated']);
        assertSame(true, $tight->truncated);
        assertSame(false, $tight->data['bounds']['changes_truncated'], 'the graph fit; only the findings overflowed');
        $roomy = $diffs->snapshotDiff($project, $from, 'active', $graph + $found);
        assertCount($found, $roomy->data['test_findings']['added']);
        assertSame(false, $roomy->data['test_findings']['truncated']);
        assertSame(false, $roomy->truncated);
    }

    public function testTrendsCountEachSnapshotAndAreNullWithoutARecord(): void
    {
        [$pdo, $service, $root] = $this->healthy();
        $first = $service->scan($root);
        file_put_contents($root . '/src/UserService.ts', self::RENAMED);
        $service->scan($root);
        $pdo->prepare('UPDATE scan_snapshots SET test_findings_recorded = 0 WHERE scan_id = ?')->execute([$first->snapshotId]);

        $series = ArchitectureQueryService::forDatabase($pdo)->services()->gateQueries->architectureTrends($first->projectId)->data['series'];

        assertCount(2, $series);
        assertSame([null, null], [$series[0]['definite_test_findings'], $series[0]['stale_test_findings']]);
        assertSame(1, $series[1]['definite_test_findings']);
        assertSame(1, $series[1]['stale_test_findings']);
    }

    /** The graph's own overflow is the change truncation, whatever the findings do. */
    public function testChangesTruncatedSaysTheGraphOverflowed(): void
    {
        [$pdo, $service, $root] = $this->healthy();
        $baseline = $service->scan($root);
        $this->unheal($root);
        $service->scan($root);
        $diffs = ArchitectureQueryService::forDatabase($pdo)->services()->diffQueries;

        $cut = $diffs->snapshotDiff($baseline->projectId, (string) $baseline->snapshotId, 'active', 1);
        $whole = $diffs->snapshotDiff($baseline->projectId, (string) $baseline->snapshotId, 'active', 1000);

        assertSame([true, true], [$cut->data['bounds']['changes_truncated'], $cut->truncated]);
        assertSame([false, false], [$whole->data['bounds']['changes_truncated'], $whole->truncated]);
    }

    /** A definite finding that is not a stale mock counts as definite and not as stale, in the series. */
    public function testTrendsSplitStaleFromDefinite(): void
    {
        [$pdo, $service, $root] = $this->healthy();
        $first = $service->scan($root);
        $this->unheal($root);
        $service->scan($root);

        $series = ArchitectureQueryService::forDatabase($pdo)->services()->gateQueries->architectureTrends($first->projectId)->data['series'];

        assertSame([0, 0], [$series[0]['definite_test_findings'], $series[0]['stale_test_findings']]);
        assertSame(true, $series[1]['stale_test_findings'] >= 1);
        assertSame(true, $series[1]['stale_test_findings'] < $series[1]['definite_test_findings'], 'the vacuous double is definite, not stale');
    }

    /** The release note says its detail was cut only when the change list was, not when only the findings overflowed. */
    #[Group('slow')]
    public function testTheReleaseNoteSaysTruncatedOnlyForTheChangeList(): void
    {
        [$pdo, $service, $root] = $this->healthy();
        $first = $service->scan($root);
        $this->unheal($root);
        $service->scan($root);
        $trends = ArchitectureQueryService::forDatabase($pdo)->services()->gateQueries;

        $quiet = $trends->architectureTrends($first->projectId, 10, (string) $first->snapshotId)->data['release_notes'];
        assertSame(false, str_contains($quiet['markdown'], 'truncated'));
        assertSame(false, $quiet['truncated']);

        for ($at = 0; $at < 101; $at++) {
            file_put_contents($root . "/src/Bulk{$at}.ts", "export const bulk{$at} = {$at};\n");
        }
        $service->scan($root);
        $loud = $trends->architectureTrends($first->projectId, 10, (string) $first->snapshotId)->data['release_notes'];
        assertSame(true, str_contains($loud['markdown'], 'Detail output was truncated by the 100-change release-note bound.'));
        assertSame(true, $loud['truncated']);
    }

    /** The diff and the trends say when the project's suppressions could not be read, as the other surfaces do. */
    public function testDiffAndTrendsCarryTheInvalidSuppressionsMessage(): void
    {
        [$pdo, $service, $root] = $this->healthy();
        $baseline = $service->scan($root);
        file_put_contents($root . '/src/Extra.ts', "export const extra = 1;\n");
        $service->scan($root);
        $queries = ArchitectureQueryService::forDatabase($pdo)->services();
        $diff = fn() => $queries->diffQueries->snapshotDiff($baseline->projectId, (string) $baseline->snapshotId);
        $trends = fn() => $queries->gateQueries->architectureTrends($baseline->projectId);
        assertSame([null, null], [$diff()->data['suppressions_invalid'], $trends()->data['suppressions_invalid']]);

        file_put_contents($root . '/knossos.json', '{"version": 1, "test_finding_suppressions": [{"nope": 1}]}');

        $message = $diff()->data['suppressions_invalid'];
        assertSame(true, is_string($message) && $message !== '');
        assertSame($message, $trends()->data['suppressions_invalid']);
    }

    public function testTrendsCountZeroForARecordedSnapshotWithNoFindings(): void
    {
        [$pdo, $service, $root] = $this->healthy();
        $first = $service->scan($root);
        file_put_contents($root . '/src/Extra.ts', "export const extra = 1;\n");
        $service->scan($root);

        $series = ArchitectureQueryService::forDatabase($pdo)->services()->gateQueries->architectureTrends($first->projectId)->data['series'];

        assertSame([0, 0], [$series[0]['definite_test_findings'], $series[0]['stale_test_findings']]);
        assertSame([0, 0], [$series[1]['definite_test_findings'], $series[1]['stale_test_findings']]);
    }

    /** @return array{0: PDO, 1: ProjectScanService, 2: string} a project whose one test double matches its code */
    private function healthy(): array
    {
        $root = self::scratchPath('ts-counted-test-findings');
        $this->copyTree(self::repositoryRoot() . self::FIXTURE, $root);
        unlink($root . '/tests/planted.test.ts');
        file_put_contents($root . '/tests/ghost.test.ts', str_replace("'getProfil'", "'getProfile'", (string) file_get_contents($root . '/tests/ghost.test.ts')));
        $pdo = $this->freshTestDatabase();

        return [$pdo, new ProjectScanService($pdo, self::repositoryRoot(), [$root]), $root];
    }

    /** The fixture's stale and vacuous doubles, as shipped. */
    private function unheal(string $root): void
    {
        copy(self::repositoryRoot() . self::FIXTURE . '/tests/ghost.test.ts', $root . '/tests/ghost.test.ts');
        copy(self::repositoryRoot() . self::FIXTURE . '/tests/planted.test.ts', $root . '/tests/planted.test.ts');
    }

    /** The double matches its code again, and the planted one is gone. */
    private function heal(string $root): void
    {
        @unlink($root . '/tests/planted.test.ts');
        file_put_contents($root . '/tests/ghost.test.ts', str_replace("'getProfil'", "'getProfile'", (string) file_get_contents($root . '/tests/ghost.test.ts')));
    }

    /** @return array{0: PDO, 1: ProjectScanService, 2: string} the fixture as it is */
    private function unhealthy(): array
    {
        $root = self::scratchPath('ts-counted-test-findings-unhealthy');
        $this->copyTree(self::repositoryRoot() . self::FIXTURE, $root);
        $pdo = $this->freshTestDatabase();

        return [$pdo, new ProjectScanService($pdo, self::repositoryRoot(), [$root]), $root];
    }

    private function diff(PDO $pdo, string $projectId, string $from): \Knossos\Result\ResultEnvelope
    {
        return ArchitectureQueryService::forDatabase($pdo)->services()->diffQueries->snapshotDiff($projectId, $from);
    }
}
