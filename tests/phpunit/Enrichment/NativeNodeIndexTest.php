<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Enrichment;

use Knossos\Enrichment\NativeNodeIndex;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\Fixtures;
use PDO;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\Group;

#[Group('enrichment')]
final class NativeNodeIndexTest extends KnossosTestCase
{
    use Fixtures;

    /**
     * Rebuild, from the stored graph, the facts a worker reported: only the
     * nodes that came from a worker carry `scanner_local_id`.
     */
    public function testStableIdsMatchReconciledIds(): void
    {
        [$pdo, $projectId] = $this->scanTempFixture('endpoint-routes');
        $facts = [];
        $expected = [];
        foreach ($pdo->query('SELECT id, kind, canonical_name, display_name, attributes_json FROM nodes', PDO::FETCH_ASSOC) as $row) {
            $local = json_decode((string) $row['attributes_json'], true)['scanner_local_id'] ?? null;
            if (!is_string($local)) {
                continue;
            }
            $expected[$row['id']] = $local;
            $facts[] = new NodeFact($local, $row['kind'], $row['canonical_name'], $row['display_name'], Origin::Ast, Confidence::Certain, new Evidence('a.py', 1, 1));
        }
        assertGreaterThan(5, count($expected));

        $index = NativeNodeIndex::build($projectId, [new ScanContribution('python:a.py', $facts)]);

        foreach ($expected as $stableId => $local) {
            assertSame($local, $index->localIdFor($stableId), 'stable id ' . $stableId);
        }
    }

    public function testKindsAndInputShape(): void
    {
        $index = NativeNodeIndex::build('proj', [new ScanContribution('php:a', [
            $this->node('php:m', 'method', 'A::m', 'src/A.php', 7, 9),
            $this->node('php:c', 'class', 'A', 'src/A.php', 1),
        ])]);

        $methods = $index->nodesOfKinds(['method']);

        assertCount(1, $methods);
        assertSame('method', $methods[0]['kind']);
        assertSame('A::m', $methods[0]['qualified_name']);
        assertSame('src/A.php', $methods[0]['file']);
        assertSame(7, $methods[0]['line']);
        assertSame(9, $methods[0]['end_line']);
        assertSame(['file' => 'src/A.php', 'start_line' => 7, 'end_line' => 9], $index->declarationOf($methods[0]['id']));
        assertNull($index->declarationOf('unknown'));
        assertSame('php:m', $index->localIdFor($methods[0]['id']));
        assertSame('method', $index->kindOf($methods[0]['id']));
        assertSame([], $index->nodesOfKinds([]));
        assertNull($index->localIdFor('unknown'));
        assertNull($index->kindOf('unknown'));
    }

    public function testHasNativeEdgeMatchesSourceTargetAndKind(): void
    {
        $edge = new EdgeFact('calls', 'php:a', 'php:b', Origin::Ast, Confidence::Certain, new Evidence('a.php', 1, 1));
        $index = NativeNodeIndex::build('proj', [new ScanContribution('php:a', [], [$edge])]);

        assertTrue($index->hasNativeEdge('php:a', 'php:b', 'calls'));
        assertSame(false, $index->hasNativeEdge('php:a', 'php:b', 'imports'));
        assertSame(false, $index->hasNativeEdge('php:b', 'php:a', 'calls'));
    }

    private function node(string $local, string $kind, string $canonical, string $path, int $line, ?int $endLine = null): NodeFact
    {
        return new NodeFact($local, $kind, $canonical, $canonical, Origin::Ast, Confidence::Certain, new Evidence($path, $line, $endLine ?? $line));
    }
}
