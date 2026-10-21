<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Enrichment;

use Knossos\Configuration\EnrichersFile;
use Knossos\Enrichment\EnricherCarryOver;
use Knossos\Enrichment\NativeNodeIndex;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanOptions;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * Rebuilding an enricher's stored facts for a scan that does not run it:
 * the same facts with the time they were produced, minus what no longer
 * holds against the current native nodes.
 */
#[Group('enrichment')]
final class EnricherCarryOverTest extends KnossosTestCase
{
    private string $root = '';
    private string $installation = '';
    private PDO $pdo;
    private string $projectId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-carry-over-' . bin2hex(random_bytes(6));
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/endpoint-routes', $this->root);
        file_put_contents($this->root . '/knossos.json', '{"version": 1, "enrichers": ["fixture"]}');
        $this->installation = sys_get_temp_dir() . '/knossos-stale-carry-over-install-' . bin2hex(random_bytes(6));
        mkdir($this->installation, 0o777, true);
        file_put_contents($this->installation . '/enrichers.json', json_encode(['enrichers' => [[
            'name' => 'fixture',
            'command' => [PHP_BINARY, self::repositoryRoot() . '/tests/Fixtures/enrichers/fixture-enricher.php', 'ok'],
            'input_node_kinds' => ['module', 'function'],
        ]]], JSON_UNESCAPED_SLASHES));
        $this->pdo = $this->freshTestDatabase();
        $service = new ProjectScanService($this->pdo, self::repositoryRoot(), [$this->root], enrichers: new EnrichersFile($this->installation . '/enrichers.json'));
        $this->projectId = $service->scan($this->root, new ScanOptions(runEnrichers: true))->projectId;
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        $this->removeTempTree($this->installation);
        parent::tearDown();
    }

    public function testRebuildsThePreviousFactsWithTheirOriginalStamp(): void
    {
        $stored = $this->storedStamp();

        $carried = $this->carryOver()->previous($this->projectId, 'fixture', $this->native());

        assertSame([], $carried->dropped);
        assertSame('enricher:fixture', $carried->contribution->ownerKey);
        assertSame(
            [['ext:fixture/call-search', 'ext:rest_call'], ['ext:fixture/endpoint-search', 'ext:endpoint']],
            self::nodes($carried->contribution),
        );
        assertSame(['calls_endpoint', 'calls_endpoint', 'represents'], self::edgeKinds($carried->contribution));
        foreach ([...$carried->contribution->nodes, ...$carried->contribution->edges] as $fact) {
            assertSame(Origin::Enricher, $fact->origin);
            assertSame($stored, [$fact->attributes['enricher_scan_id'], $fact->attributes['enricher_produced_at']]);
        }
    }

    public function testDropsAnEdgeWhoseNativeEndpointIsGone(): void
    {
        $carried = $this->carryOver()->previous($this->projectId, 'fixture', $this->native(without: 'module'));

        assertSame(['calls_endpoint', 'represents'], self::edgeKinds($carried->contribution));
        assertSame(['ENRICHER_UNKNOWN_ENDPOINT'], self::codes($carried->dropped));
    }

    public function testRechecksRepresentsAgainstTheCurrentNativeNodes(): void
    {
        $carried = $this->carryOver()->previous($this->projectId, 'fixture', $this->native(without: 'function'));

        assertSame(['calls_endpoint', 'calls_endpoint'], self::edgeKinds($carried->contribution));
        assertSame(['ENRICHER_REPRESENTS_INVALID'], self::codes($carried->dropped));
        assertSame(2, count($carried->contribution->nodes));
    }

    public function testAnEnricherWithNoStoredFactsCarriesNothing(): void
    {
        $carried = $this->carryOver()->previous($this->projectId, 'other', $this->native());

        assertSame('enricher:other', $carried->contribution->ownerKey);
        assertSame([[], [], []], [$carried->contribution->nodes, $carried->contribution->edges, $carried->dropped]);
    }

    private function carryOver(): EnricherCarryOver
    {
        $statement = $this->pdo->prepare('SELECT relative_path FROM files WHERE project_id = ?');
        $statement->execute([$this->projectId]);

        return new EnricherCarryOver($this->pdo, array_fill_keys($statement->fetchAll(PDO::FETCH_COLUMN), true));
    }

    /** The native nodes of the stored graph, where the workers declared them, leaving out one kind. */
    private function native(?string $without = null): NativeNodeIndex
    {
        $facts = [];
        $rows = $this->pdo->query(
            "SELECT n.kind, n.canonical_name, n.display_name, n.attributes_json, f.relative_path, n.start_line, n.end_line
             FROM nodes n JOIN files f ON f.id = n.file_id WHERE n.origin <> 'enricher' AND n.start_line IS NOT NULL",
            PDO::FETCH_ASSOC,
        );
        foreach ($rows as $row) {
            $local = json_decode((string) $row['attributes_json'], true)['scanner_local_id'] ?? null;
            if (is_string($local) && $row['kind'] !== $without) {
                $evidence = new Evidence($row['relative_path'], (int) $row['start_line'], (int) $row['end_line']);
                $facts[] = new NodeFact($local, $row['kind'], $row['canonical_name'], $row['display_name'], Origin::Ast, Confidence::Certain, $evidence);
            }
        }

        return NativeNodeIndex::build($this->projectId, [new ScanContribution('native', $facts)]);
    }

    /** @return array{string, string} */
    private function storedStamp(): array
    {
        $attributes = json_decode((string) $this->pdo->query("SELECT attributes_json FROM nodes WHERE owner_key = 'enricher:fixture' LIMIT 1")->fetchColumn(), true);

        return [$attributes['enricher_scan_id'], $attributes['enricher_produced_at']];
    }

    /** @return list<array{string, string}> */
    private static function nodes(ScanContribution $contribution): array
    {
        $nodes = array_map(static fn(NodeFact $node): array => [$node->localId, $node->kind], $contribution->nodes);
        sort($nodes);

        return $nodes;
    }

    /** @return list<string> */
    private static function edgeKinds(ScanContribution $contribution): array
    {
        $kinds = array_map(static fn(EdgeFact $edge): string => $edge->kind, $contribution->edges);
        sort($kinds);

        return $kinds;
    }

    /**
     * @param list<Diagnostic> $diagnostics
     * @return list<string>
     */
    private static function codes(array $diagnostics): array
    {
        return array_map(static fn(Diagnostic $diagnostic): string => $diagnostic->code, $diagnostics);
    }
}
