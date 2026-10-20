<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Discovery\EnricherDefinition;
use Knossos\Discovery\EnrichersFile;
use Knossos\Query\DiagnosticsQueryService;
use Knossos\Reconciliation\GraphReconciler;
use Knossos\Result\ResultEnvelope;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertFileDoesNotExist;
use function PHPUnit\Framework\assertFileExists;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

use PHPUnit\Framework\Attributes\Group;

/**
 * Whole scans of the endpoint-routes fixture with the fixture enricher
 * installed and requested: when it runs, what its facts become, and what is
 * kept when it does not run or fails.
 */
#[Group('scan')]
final class EnrichmentScanTest extends KnossosTestCase
{
    private string $root = '';
    private string $installation = '';
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-enrichment-scan-' . bin2hex(random_bytes(6));
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/endpoint-routes', $this->root);
        $this->requestEnrichers(['fixture']);
        $this->installation = sys_get_temp_dir() . '/knossos-stale-enrichment-install-' . bin2hex(random_bytes(6));
        mkdir($this->installation, 0o777, true);
        $this->install();
        $this->setMode('ok');
        $this->pdo = $this->freshTestDatabase();
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        $this->removeTempTree($this->installation);
        parent::tearDown();
    }

    public function testExplicitScanStoresEnrichedFacts(): void
    {
        $this->scan(true);

        assertSame([
            ['ext:endpoint', '/task/search/', 'enricher', 'fixture'],
            ['ext:rest_call', 'GET /task/search/ from OrderList.vue', 'enricher', 'fixture'],
        ], $this->enrichedNodes());
        assertSame([
            ['calls_endpoint', 'GET /task/search/ from OrderList.vue', '/task/search/'],
            ['calls_endpoint', 'frontend/src/components/OrderList.vue', 'GET /task/search/ from OrderList.vue'],
            ['represents', '/task/search/', 'backend.app.urls.search'],
        ], $this->enrichedEdges());
        assertFileExists($this->marker());
    }

    public function testFactNoLongerEmittedIsPruned(): void
    {
        $this->scan(true);
        $this->setMode('ok-less');

        // Nothing changed on disk: an explicit scan runs the enricher all the same.
        $second = $this->scan(true);

        assertArrayNotHasKey('fast_path', $second->data);
        assertSame([['ext:rest_call', 'GET /task/search/ from OrderList.vue', 'enricher', 'fixture']], $this->enrichedNodes());
        assertSame([['calls_endpoint', 'frontend/src/components/OrderList.vue', 'GET /task/search/ from OrderList.vue']], $this->enrichedEdges());
    }

    public function testChangedFactIsReplaced(): void
    {
        $this->scan(true);
        $this->setMode('ok-changed');

        $this->scan(true);

        $rows = $this->pdo->query("SELECT display_name, confidence FROM nodes WHERE kind = 'ext:endpoint'")->fetchAll(PDO::FETCH_NUM);
        assertSame([['task search (changed)', 'possible']], $rows);
    }

    public function testFailedRunKeepsPreviousFacts(): void
    {
        $this->scan(true);
        $before = [$this->enrichedNodes(), $this->enrichedEdges()];
        $producedAt = $this->producedAt();
        $this->setMode('garbage');
        $this->touchNativeFile();

        $this->scan(true);

        assertSame($before, [$this->enrichedNodes(), $this->enrichedEdges()]);
        assertSame($producedAt, $this->producedAt(), 'Carried-over facts keep the time they were produced.');
        $failed = $this->diagnostic('ENRICHER_FAILED');
        assertSame('warning', $failed['severity']);
        assertStringContainsString('protocol_error', $failed['message']);
        assertStringContainsString($producedAt, $failed['message']);
        assertSame('enricher', $failed['source']);
    }

    public function testFailureNeverAbortsRescan(): void
    {
        $this->scan(true);
        $this->setMode('garbage');
        $this->touchNativeFile();

        $result = $this->scan(true);

        assertSame([], $result->data['degraded_languages']);
        assertSame('complete', $this->pdo->query('SELECT s.status FROM scans s JOIN projects p ON p.active_scan_id = s.id')->fetchColumn());
        assertSame([], array_values(array_filter($this->codes(), static fn(string $code): bool => str_starts_with($code, 'WORKER_'))));
        // No worker diagnostic was recorded, so the next background scan of an unchanged tree takes the fast path.
        assertSame('no_change', $this->scan(false)->data['fast_path'] ?? null);
    }

    public function testBackgroundScanDoesNotStartEnricher(): void
    {
        $this->scan(true);
        $before = [$this->enrichedNodes(), $this->enrichedEdges()];
        unlink($this->marker());
        $this->setMode('exit-1');
        $this->touchNativeFile();

        $result = $this->scan(false);

        assertArrayNotHasKey('fast_path', $result->data);
        assertFileDoesNotExist($this->marker());
        assertSame($before, [$this->enrichedNodes(), $this->enrichedEdges()]);
        $notRun = $this->diagnostic('ENRICHER_NOT_RUN');
        assertSame('info', $notRun['severity']);
        assertStringContainsString($this->producedAt(), $notRun['message']);
    }

    public function testFastPathScanKeepsFacts(): void
    {
        $this->scan(true);
        $before = [$this->enrichedNodes(), $this->enrichedEdges()];

        $result = $this->scan(false);

        assertSame('no_change', $result->data['fast_path'] ?? null);
        assertSame($before, [$this->enrichedNodes(), $this->enrichedEdges()]);
    }

    public function testRenamedNativeTargetDropsRepresentsOnCarryOver(): void
    {
        $this->scan(true);
        $urls = $this->root . '/backend/app/urls.py';
        file_put_contents($urls, str_replace(['def search(', ', search)'], ['def find(', ', find)'], (string) file_get_contents($urls)));

        $this->scan(false);

        assertSame([
            ['ext:endpoint', '/task/search/', 'enricher', 'fixture'],
            ['ext:rest_call', 'GET /task/search/ from OrderList.vue', 'enricher', 'fixture'],
        ], $this->enrichedNodes());
        assertSame([
            ['calls_endpoint', 'GET /task/search/ from OrderList.vue', '/task/search/'],
            ['calls_endpoint', 'frontend/src/components/OrderList.vue', 'GET /task/search/ from OrderList.vue'],
        ], $this->enrichedEdges());
        assertSame('warning', $this->diagnostic('ENRICHER_REPRESENTS_INVALID')['severity']);
    }

    public function testUninstalledEnricherIsSkipped(): void
    {
        $this->scan(true);
        file_put_contents($this->installation . '/enrichers.json', '{"enrichers": []}');

        $this->scan(true);

        assertSame('warning', $this->diagnostic('ENRICHER_NOT_INSTALLED')['severity']);
        assertSame([], $this->enrichedNodes());
        assertSame([], $this->enrichedEdges());
        assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM nodes WHERE canonical_name = 'backend.app.urls.search'")->fetchColumn());
    }

    public function testRemovedFromProjectConfigPrunes(): void
    {
        $this->scan(true);
        $this->requestEnrichers([]);

        $this->scan(true);

        assertSame([], $this->enrichedNodes());
        assertSame([], $this->enrichedEdges());
        assertNotContains('ENRICHER_NOT_INSTALLED', $this->codes());
    }

    public function testBrokenEntriesInTheEnrichersFileAreReported(): void
    {
        $this->install(['{"name": "Not A Name", "command": ["x"]}']);

        $this->scan(true);

        assertStringContainsString('entry 0 skipped', $this->diagnostic('ENRICHER_CONFIG_INVALID')['message']);
        assertSame(2, count($this->enrichedNodes()), 'The valid entry still runs.');
    }

    public function testScannerSetHashChangesWithEnricherCommand(): void
    {
        $definition = new EnricherDefinition('fixture', ['enricher', 'a'], inputNodeKinds: ['function']);

        $hash = GraphReconciler::scannerSetHash([], [$definition]);

        assertSame(GraphReconciler::scannerSetHash([]), GraphReconciler::scannerSetHash([], []), 'No enrichers hash as before.');
        assertNotSame(GraphReconciler::scannerSetHash([]), $hash);
        assertNotSame($hash, GraphReconciler::scannerSetHash([], [new EnricherDefinition('fixture', ['enricher', 'b'], inputNodeKinds: ['function'])]));
        assertNotSame($hash, GraphReconciler::scannerSetHash([], [new EnricherDefinition('other', ['enricher', 'a'], inputNodeKinds: ['function'])]));
        assertNotSame($hash, GraphReconciler::scannerSetHash([], [new EnricherDefinition('fixture', ['enricher', 'a'], inputNodeKinds: ['method'])]));
        assertSame($hash, GraphReconciler::scannerSetHash([], [new EnricherDefinition('fixture', ['enricher', 'a'], 99, ['X' => 'y'], ['function'])]), 'Timeout and env are not part of what the facts depend on.');
    }

    /** Scan the fixture copy with the fixture enricher installed. */
    private function scan(bool $runEnrichers): ResultEnvelope
    {
        $service = new ProjectScanService($this->pdo, self::repositoryRoot(), [$this->root], enrichers: new EnrichersFile($this->installation . '/enrichers.json'));

        return $service->scan($this->root, runEnrichers: $runEnrichers);
    }

    /** @param list<string> $names */
    private function requestEnrichers(array $names): void
    {
        file_put_contents($this->root . '/knossos.json', json_encode(['version' => 1, 'enrichers' => $names]));
    }

    /**
     * Write the installation's enrichers.json: the fixture enricher, after any extra raw entries.
     *
     * @param list<string> $extraEntries
     */
    private function install(array $extraEntries = []): void
    {
        $fixture = json_encode([
            'name' => 'fixture',
            'command' => [PHP_BINARY, self::repositoryRoot() . '/tests/Fixtures/enrichers/fixture-enricher.php', '@' . $this->installation . '/mode', $this->marker()],
            'timeout_seconds' => 10,
            'input_node_kinds' => ['module', 'function'],
        ], JSON_UNESCAPED_SLASHES);
        file_put_contents($this->installation . '/enrichers.json', '{"enrichers": [' . implode(',', [...$extraEntries, $fixture]) . ']}');
    }

    private function setMode(string $mode): void
    {
        file_put_contents($this->installation . '/mode', $mode);
    }

    private function marker(): string
    {
        return $this->installation . '/started';
    }

    /** Change a native file, so the next scan reconciles rather than taking the fast path. */
    private function touchNativeFile(): void
    {
        file_put_contents($this->root . '/backend/app/settings.py', "\n# touched\n", FILE_APPEND);
    }

    /** @return list<array{string, string, string, string}> kind, name, origin, enricher attribute */
    private function enrichedNodes(): array
    {
        $rows = $this->pdo->query("SELECT kind, canonical_name, origin, attributes_json FROM nodes WHERE owner_key = 'enricher:fixture' ORDER BY kind, canonical_name")->fetchAll(PDO::FETCH_NUM);

        return array_map(static fn(array $row): array => [$row[0], $row[1], $row[2], json_decode((string) $row[3], true)['enricher'] ?? ''], $rows);
    }

    /** @return list<array{string, string, string}> kind, source name, target name */
    private function enrichedEdges(): array
    {
        return $this->pdo->query(
            "SELECT e.kind, s.canonical_name, t.canonical_name FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id
             WHERE e.owner_key = 'enricher:fixture' ORDER BY e.kind, s.canonical_name, t.canonical_name",
        )->fetchAll(PDO::FETCH_NUM);
    }

    private function producedAt(): string
    {
        $attributes = json_decode((string) $this->pdo->query("SELECT attributes_json FROM nodes WHERE owner_key = 'enricher:fixture' LIMIT 1")->fetchColumn(), true);

        return (string) $attributes['enricher_produced_at'];
    }

    /** @return list<array<string, mixed>> */
    private function diagnostics(): array
    {
        $projectId = ProjectScanService::projectIdOf((string) realpath($this->root));

        return (new DiagnosticsQueryService($this->pdo))->listDiagnostics($projectId)->data['diagnostics'];
    }

    /** @return list<string> */
    private function codes(): array
    {
        return array_column($this->diagnostics(), 'code');
    }

    /** @return array<string, mixed> the one diagnostic with this code */
    private function diagnostic(string $code): array
    {
        assertContains($code, $this->codes());
        $matching = array_values(array_filter($this->diagnostics(), static fn(array $row): bool => $row['code'] === $code));
        assertSame(1, count($matching), $code);

        return $matching[0];
    }
}
