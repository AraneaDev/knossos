<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\DashboardService;
use Knossos\Query\FileFanInQuery;
use Knossos\Query\ProjectFindings;
use Knossos\Query\SnapshotGraphReader;
use Knossos\Result\ResultEnvelope;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\EnricherFlowScans;
use PDO;

use function PHPUnit\Framework\assertContains;
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

    public function testInheritanceClaimDoesNotExcuseDeadCode(): void
    {
        // The enricher claims Orphan extends Base, which would make Orphan::handle an override.
        $this->setEnricherFlowMode($this->project['installation'], 'ok-extends');
        $this->scanBoth();
        assertSame(1, (int) $this->enriched->query("SELECT COUNT(*) FROM edges WHERE kind = 'extends' AND origin = 'enricher'")->fetchColumn());

        $plain = $this->query($this->plain)->architectureHealth($this->projectId)->data['dead_code_candidates'];
        $enriched = $this->query($this->enriched)->architectureHealth($this->projectId)->data['dead_code_candidates'];

        assertContains('backend.app.handlers.Orphan::handle', array_column(array_column($plain, 'component'), 'canonical_name'));
        assertSame($plain, $enriched);
    }

    public function testInheritanceClaimAddsNoSupertypeStep(): void
    {
        // Were the claim read, Base::handle would reach Orphan::handle as an unlabelled implemented_by hop.
        $this->setEnricherFlowMode($this->project['installation'], 'ok-extends');
        $this->scanBoth();
        assertSame(1, (int) $this->enriched->query("SELECT COUNT(*) FROM edges WHERE kind = 'extends' AND origin = 'enricher'")->fetchColumn());

        $answers = fn(PDO $pdo): array => [
            $this->strip($this->query($pdo)->impactAnalysis($this->projectId, 'backend.app.handlers.Orphan::handle')->data),
            $this->strip($this->query($pdo)->explainFlow($this->projectId, 'backend.app.handlers.Base::handle', 'backend.app.handlers.Orphan::handle')->data),
            $this->strip($this->query($pdo)->impactAnalysis($this->projectId, 'backend.app.handlers.Base::handle')->data),
        ];

        assertSame($answers($this->plain), $answers($this->enriched));
    }

    public function testBriefFindingsAndFanInUnchanged(): void
    {
        // Each database is read right after its own scan, so both see a tree that matches it.
        $this->projectId = $this->scanEnricherFlow($this->plain, $this->project)->projectId;
        $plain = $this->metricAnswers($this->plain);
        $this->request(true);
        $this->scanEnricherFlow($this->enriched, $this->project);
        assertGreaterThan(0, $this->enrichedRows($this->enriched));
        $enriched = $this->metricAnswers($this->enriched);

        // The enricher's rest call joins OrderList.vue to urls.py, which only an enriched edge would count.
        assertSame([], $plain['dependents']['backend/app/urls.py'] ?? []);
        assertSame($plain, $enriched);
    }

    public function testEnricherDiagnosticsLeaveGateAndSummaryCounts(): void
    {
        $budgets = ['error_diagnostics' => 0, 'warning_diagnostics' => 0];
        $results = [];
        foreach (['plain' => $this->plain, 'enriched' => $this->enriched] as $name => $pdo) {
            $this->request(false);
            $baseline = $this->scanEnricherFlow($pdo, $this->project);
            $this->request($name === 'enriched');
            $this->setEnricherFlowMode($this->project['installation'], 'ok-reporting');
            // A native change, so the second scan is a snapshot of its own.
            $settings = $this->project['root'] . '/backend/app/settings.py';
            $original = (string) file_get_contents($settings);
            file_put_contents($settings, "\n# touched\n", FILE_APPEND);
            $this->scanEnricherFlow($pdo, $this->project);
            file_put_contents($settings, $original);
            $query = $this->query($pdo);
            $results[$name] = [
                'gate' => $this->comparable($query->qualityGate($baseline->projectId, $this->scanId($baseline), $budgets, sarif: true)),
                'summary' => $this->comparable($query->architectureSummary($baseline->projectId)),
                'trends' => $this->comparable($query->architectureTrends($baseline->projectId)),
            ];
            $this->projectId = $baseline->projectId;
        }
        $codes = array_column($this->query($this->enriched)->listDiagnostics($this->projectId)->data['diagnostics'], 'code');

        assertContains('ENRICHER_REPORTED:FIXTURE_NOISE', $codes, 'list_diagnostics still lists them.');
        assertContains('ENRICHER_REPORTED:FIXTURE_FAULT', $codes);
        assertSame($results['plain'], $results['enriched']);
        assertSame(true, $results['enriched']['gate']['passed']);
    }

    /**
     * The brief, the dashboard findings and the file fan-in of the project in `$pdo`.
     *
     * @return array<string, mixed>
     */
    private function metricAnswers(PDO $pdo): array
    {
        $paths = array_map('strval', $pdo->query('SELECT relative_path FROM files ORDER BY relative_path')->fetchAll(PDO::FETCH_COLUMN));
        $findings = new ProjectFindings($pdo);
        $fanIn = new FileFanInQuery($pdo);

        return [
            'brief' => $this->comparable($this->query($pdo)->exportAgentBrief($this->projectId)),
            'kinds' => $findings->summary($this->projectId),
            'hotspots' => $findings->complexityHotspots($this->projectId),
            'fan_in' => $fanIn->forPaths($this->projectId, $paths, 5),
            'dependents' => $fanIn->dependentsOf($this->projectId, $paths),
            'top_dependents' => $fanIn->topDependents($this->projectId, $paths, 5),
            'dashboard' => $this->strip((new DashboardService($pdo))->dashboard($this->project['root'], 0)),
        ];
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
        return $this->strip($envelope->data);
    }

    /**
     * A value without its scan ids, scanner-set hashes and times, which differ
     * between two databases for reasons other than the native graph.
     *
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function strip(array $value): array
    {
        $strip = static function (mixed $value) use (&$strip): mixed {
            if (is_string($value)) {
                // A time written into prose, such as the brief's opening line.
                return preg_replace('/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ/', '<time>', $value);
            }
            if (!is_array($value)) {
                return $value;
            }
            $kept = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/(^|_)(scan_id|snapshot_id|snapshot|set_hash|at|time|age_seconds|elapsed_ms|duration_ms)$/', $key) === 1) {
                    continue;
                }
                $kept[$key] = $strip($item);
            }

            return $kept;
        };

        return $strip($value);
    }

    private function query(PDO $pdo): ArchitectureQueryService
    {
        return ArchitectureQueryService::forDatabase($pdo);
    }
}
