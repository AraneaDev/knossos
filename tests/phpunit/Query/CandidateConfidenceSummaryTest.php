<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A count of candidates read as that much dead code, whatever each one's
 * confidence said. The summary and the bounds split the whole list by
 * confidence, so a reader sees how much is probably dead and how much only
 * possibly, before opening a single candidate.
 */
#[Group('query')]
final class CandidateConfidenceSummaryTest extends KnossosTestCase
{
    /** How many of the whole list nothing references, tests included. */
    private function unreferencedCount(\PDO $pdo, string $projectId): int
    {
        $all = ArchitectureQueryService::forDatabase($pdo)->services()->healthQueries->architectureHealth($projectId, limit: 100)->data['dead_code_candidates'];

        return count(array_filter($all, static fn(array $c): bool => $c['reachability'] === 'unreferenced'));
    }

    public function testTheSummaryAndBoundsSplitTheCandidatesByConfidence(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-confidence-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        file_put_contents($root . '/src/Mode.php', "<?php\nfinal class Mode\n{\n    public function label(): string { return ''; }\n    public function unused(): string { return ''; }\n    public function retired(): string { return ''; }\n}\n");
        file_put_contents($root . '/src/Loop.php', "<?php\nfinal class Loop\n{\n    public function run(\$mode): string { return \$mode->label(); }\n}\n");

        try {
            $pdo = $this->freshTestDatabase();
            $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
            $result = ArchitectureQueryService::forDatabase($pdo)->services()->healthQueries->architectureHealth($projectId, limit: 1);
        } finally {
            $this->removeTempTree($root);
        }

        $bounds = $result->data['bounds'];
        $confidences = array_count_values(array_column(
            ArchitectureQueryService::forDatabase($pdo)->services()->healthQueries->architectureHealth($projectId, limit: 100)->data['dead_code_candidates'],
            'confidence',
        ));
        // Counted over the whole list, not the page of one.
        self::assertSame($confidences['probable'] ?? 0, $bounds['candidates_probable']);
        self::assertSame($confidences['possible'] ?? 0, $bounds['candidates_possible']);
        // The test-only share too, over the whole list, so a reader can tell it from what nothing uses.
        self::assertArrayHasKey('candidates_test_only', $bounds);
        self::assertSame($result->data['bounds']['candidates_total'] - $bounds['candidates_test_only'], $this->unreferencedCount($pdo, $projectId));
        self::assertGreaterThanOrEqual(2, $bounds['candidates_probable'], 'unused and retired');
        self::assertGreaterThanOrEqual(1, $bounds['candidates_possible'], 'label, named by an untyped call');
        self::assertStringContainsString(
            sprintf('By confidence: %d probable, %d possible.', $bounds['candidates_probable'], $bounds['candidates_possible']),
            $result->summary,
        );
    }
}
