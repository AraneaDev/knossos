<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\SnapshotGraphReader;
use Knossos\Result\ResultEnvelope;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\EnricherFlowScans;
use PDO;

use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * Enriched facts are labelled guesses: metrics, health, dead code, snapshots
 * and everything compared against a snapshot describe native code only. One
 * copy of the enricher-flow fixture is scanned into two databases, once with
 * the fixture enricher requested and once without, and each answer must come
 * out the same.
 */
#[Group('scan')]
final class EnrichedFactsExclusionTest extends KnossosTestCase
{
    use EnricherFlowScans;

    /** A policy that judges anything unassigned, which an enriched node always is. */
    private const POLICIES = [['id' => 'frontend-closed', 'from_boundary' => 'Frontend', 'deny_targets' => ['@unassigned']]];

    /** @var array{root: string, installation: string} */
    private array $project;
    private PDO $plain;
    private PDO $enriched;
    private string $projectId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = $this->enricherFlowProject(requested: false);
        $this->plain = $this->freshTestDatabase();
        $this->enriched = $this->freshTestDatabase();
    }

    protected function tearDown(): void
    {
        $this->removeEnricherFlowTrees();
        parent::tearDown();
    }

    public function testSummaryCountsUnchanged(): void
    {
        $this->scanBoth();

        assertSame(
            $this->comparable($this->query($this->plain)->architectureSummary($this->projectId)),
            $this->comparable($this->query($this->enriched)->architectureSummary($this->projectId)),
        );
    }

    public function testHealthUnchanged(): void
    {
        $this->scanBoth();

        assertSame(
            $this->comparable($this->query($this->plain)->architectureHealth($this->projectId, includeExternal: true)),
            $this->comparable($this->query($this->enriched)->architectureHealth($this->projectId, includeExternal: true)),
        );
        assertSame(
            $this->query($this->plain)->architectureHealth($this->projectId)->data,
            $this->query($this->enriched)->architectureHealth($this->projectId)->data,
        );
    }

    public function testDeadCodeUnchanged(): void
    {
        $this->scanBoth();

        $plain = $this->query($this->plain)->architectureHealth($this->projectId)->data['dead_code_candidates'];
        $enriched = $this->query($this->enriched)->architectureHealth($this->projectId)->data['dead_code_candidates'];
        assertSame($plain, $enriched);
    }

    public function testSnapshotPayloadExcludesEnrichedRows(): void
    {
        $this->request(true);
        $first = $this->scanEnricherFlow($this->enriched, $this->project);
        // The next scan archives the first one as a snapshot.
        $this->scanEnricherFlow($this->enriched, $this->project);
        assertGreaterThan(0, $this->enrichedRows($this->enriched), 'The scanned graph holds enriched rows.');

        $origins = [];
        foreach (['nodes', 'edges'] as $table) {
            (new SnapshotGraphReader($this->enriched))->eachArchivedRow($this->scanId($first), $table, static function (array $row) use (&$origins, $table): void {
                $origins[$table][$row['origin']] = true;
            });
        }
        assertSame(false, isset($origins['nodes']['enricher']));
        assertSame(false, isset($origins['edges']['enricher']));
        assertGreaterThan(0, count($origins['nodes']));
    }

    public function testSnapshotDiffIgnoresEnricherOutputChange(): void
    {
        $this->request(true);
        $first = $this->scanEnricherFlow($this->enriched, $this->project);
        $this->setEnricherFlowMode($this->project['installation'], 'ok-changed');
        $this->scanEnricherFlow($this->enriched, $this->project);
        $changed = $this->enriched->query("SELECT display_name FROM nodes WHERE kind = 'ext:endpoint'")->fetchColumn();
        assertSame('task search (changed)', $changed, 'Only the enricher output changed.');

        $diff = $this->query($this->enriched)->snapshotDiff($first->projectId, $this->scanId($first));

        assertSame(0, $diff->data['bounds']['total_changes']);
        assertSame(['raised' => 0, 'lowered' => 0], $diff->data['confidence_changes']);
    }

    public function testQualityGateUnaffected(): void
    {
        $budgets = ['new_cycles' => 0, 'boundary_violations' => 0, 'hub_degree_growth' => 0, 'unreferenced_candidates' => 0, 'error_diagnostics' => 0];
        $this->withBoundaries();
        $gates = [];
        foreach (['plain' => $this->plain, 'enriched' => $this->enriched] as $name => $pdo) {
            $this->request(false);
            $baseline = $this->scanEnricherFlow($pdo, $this->project);
            $this->request($name === 'enriched');
            $this->scanEnricherFlow($pdo, $this->project);
            $gates[$name] = $this->comparable($this->query($pdo)->qualityGate($baseline->projectId, $this->scanId($baseline), $budgets, self::POLICIES));
        }
        assertGreaterThan(0, $this->enrichedRows($this->enriched));

        assertSame($gates['plain'], $gates['enriched']);
    }

    public function testEnrichedNodeDoesNotCountAsDeadCode(): void
    {
        // One ext:echo node per native node it was sent, none with an edge into it.
        $this->setEnricherFlowMode($this->project['installation'], 'echo-input');
        $this->request(true);
        $result = $this->scanEnricherFlow($this->enriched, $this->project);
        $unreached = (int) $this->enriched->query("SELECT COUNT(*) FROM nodes n WHERE n.kind = 'ext:echo' AND NOT EXISTS (SELECT 1 FROM edges e WHERE e.target_id = n.id)")->fetchColumn();
        assertGreaterThan(0, $unreached);

        $candidates = $this->query($this->enriched)->architectureHealth($result->projectId)->data['dead_code_candidates'];

        $kinds = array_column(array_column($candidates, 'component'), 'kind');
        assertNotSame([], $kinds, 'Native candidates are still reported.');
        assertSame([], array_values(array_filter($kinds, static fn(string $kind): bool => str_starts_with($kind, 'ext:'))));
    }

    /** Scan the same copy into both databases: without the enricher, then with it. */
    private function scanBoth(): void
    {
        $this->projectId = $this->scanEnricherFlow($this->plain, $this->project)->projectId;
        $this->request(true);
        $this->scanEnricherFlow($this->enriched, $this->project);
        assertGreaterThan(0, $this->enrichedRows($this->enriched), 'The enriched scan holds enriched rows.');
        assertSame(0, $this->enrichedRows($this->plain));
    }

    /** Request the fixture enricher from the project config, or not. */
    private function request(bool $requested): void
    {
        $config = json_decode((string) file_get_contents($this->project['root'] . '/knossos.json'), true);
        $config['enrichers'] = $requested ? ['fixture'] : [];
        file_put_contents($this->project['root'] . '/knossos.json', json_encode($config));
    }

    /** Give the project a Frontend and a Backend boundary, so a policy has something to judge. */
    private function withBoundaries(): void
    {
        $config = json_decode((string) file_get_contents($this->project['root'] . '/knossos.json'), true);
        $config['boundaries'] = [['name' => 'Frontend', 'path_prefix' => 'frontend/'], ['name' => 'Backend', 'path_prefix' => 'backend/']];
        file_put_contents($this->project['root'] . '/knossos.json', json_encode($config));
    }

    private function enrichedRows(PDO $pdo): int
    {
        return (int) $pdo->query("SELECT (SELECT COUNT(*) FROM nodes WHERE origin = 'enricher') + (SELECT COUNT(*) FROM edges WHERE origin = 'enricher')")->fetchColumn();
    }

    private function scanId(ResultEnvelope $scan): string
    {
        assertNotSame(null, $scan->snapshotId);

        return (string) $scan->snapshotId;
    }

    /**
     * An envelope's data without what differs between two databases for reasons
     * other than the graph: scan ids and times.
     *
     * @return array<string, mixed>
     */
    private function comparable(ResultEnvelope $envelope): array
    {
        $strip = static function (mixed $value) use (&$strip): mixed {
            if (!is_array($value)) {
                return $value;
            }
            $kept = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/(^|_)(scan_id|snapshot|at|time|elapsed_ms|duration_ms)$/', $key) === 1) {
                    continue;
                }
                $kept[$key] = $strip($item);
            }

            return $kept;
        };

        return $strip($envelope->data);
    }

    private function query(PDO $pdo): ArchitectureQueryService
    {
        return ArchitectureQueryService::forDatabase($pdo);
    }
}
