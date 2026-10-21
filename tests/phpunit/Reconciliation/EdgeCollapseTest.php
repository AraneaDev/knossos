<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use Knossos\Discovery\DiscoveredFile;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Reconciliation\EdgeCollapse;
use Knossos\Reconciliation\FullScanRequest;
use Knossos\Reconciliation\GraphAssembler;
use Knossos\Reconciliation\ReconciliationException;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The one rule repeated edges collapse by: one edge per kind, source, target
 * and owner (and prefix for `mounts`), the surest occurrence winning, the
 * attributes unioned and the winner's evidence kept, the earliest breaking a
 * tie.
 */
#[Group('reconciliation')]
final class EdgeCollapseTest extends KnossosTestCase
{
    private const OWNER = 'knossos.php:file:src/A.php';

    public function testTwoCallsAtTwoSitesAreOneEdgeWithTheEarliestEvidence(): void
    {
        $records = $this->collapse([
            self::edge('calls', 9),
            self::edge('calls', 4),
        ]);

        assertCount(1, $records);
        $record = array_values($records)[0];
        assertSame(4, $record['start_line']);
        assertSame(StableId::edge('p', 'calls', 'S', 'T', 'src/A.php:4:4:' . self::OWNER), $record['id']);
        assertSame('file-a', $record['file_id']);
    }

    public function testTheSurerOccurrenceDecidesConfidenceOriginLineAndConflictingAttributes(): void
    {
        $records = $this->collapse([
            self::edge('calls', 2, Confidence::Possible, ['via' => 'guess', 'first' => true], Origin::Derived),
            self::edge('calls', 7, Confidence::Certain, ['via' => 'ast', 'second' => 1]),
        ]);

        $record = array_values($records)[0];
        assertSame('certain', $record['confidence']);
        assertSame('ast', $record['origin']);
        assertSame(['via' => 'ast', 'second' => 1, 'first' => true], $record['attributes']);
        // The line is the site that earned the confidence, not the earlier guess.
        assertSame(7, $record['start_line']);
        assertSame(7, $record['end_line']);
        assertSame(StableId::edge('p', 'calls', 'S', 'T', 'src/A.php:7:7:' . self::OWNER), $record['id']);
    }

    public function testANonSpeculativeOccurrenceOutranksASpeculativeOneOfEqualConfidence(): void
    {
        $records = $this->collapse([
            self::edge('calls', 1, Confidence::Probable, ['speculative' => true]),
            self::edge('calls', 5, Confidence::Probable, ['site' => 'b']),
        ]);

        assertSame(['site' => 'b'], array_values($records)[0]['attributes']);
        assertSame(5, array_values($records)[0]['start_line']);
    }

    public function testTypeOnlyVariantsAreKeptForEveryErasedTypeKind(): void
    {
        foreach (['imports', 're_exports'] as $kind) {
            $records = $this->collapse([
                self::edge($kind, 1, attributes: ['type_only' => true]),
                self::edge($kind, 2),
            ]);
            assertSame(['type_only' => true, 'type_only_variants' => [false, true]], array_values($records)[0]['attributes'], $kind);
        }
        $single = $this->collapse([self::edge('imports', 1, attributes: ['type_only' => true])]);
        assertSame(['type_only' => true], array_values($single)[0]['attributes']);
        $calls = $this->collapse([self::edge('calls', 1, attributes: ['type_only' => true]), self::edge('calls', 2)]);
        assertSame(['type_only' => true], array_values($calls)[0]['attributes']);
    }

    public function testMountsCollapsePerPrefixAndOtherEdgesPerOwner(): void
    {
        $records = $this->collapse([
            self::edge('mounts', 1, attributes: ['prefix' => '/a']),
            self::edge('mounts', 2, attributes: ['prefix' => '/b']),
            self::edge('mounts', 3, attributes: ['prefix' => '/a']),
        ]);
        assertCount(2, $records);

        $collapse = new EdgeCollapse('p', ['src/A.php' => 'file-a']);
        $collapse->add(self::edge('calls', 1), 'S', 'T', self::OWNER);
        $collapse->add(self::edge('calls', 1), 'S', 'T', 'other:owner');
        assertCount(2, $collapse->records());
    }

    public function testTheOutcomeDoesNotDependOnTheOrderOccurrencesArrive(): void
    {
        $edges = [
            self::edge('calls', 8, Confidence::Probable, ['a' => 1]),
            self::edge('calls', 3, Confidence::Probable, ['a' => 2]),
            self::edge('calls', 5, Confidence::Possible, ['b' => 3]),
        ];

        $forward = $this->collapse($edges);
        $backward = $this->collapse(array_reverse($edges));

        assertSame($forward, $backward);
        assertSame(['a' => 2, 'b' => 3], array_values($forward)[0]['attributes']);
    }

    public function testAnOccurrenceFromAnUndiscoveredFileIsRefused(): void
    {
        $collapse = new EdgeCollapse('p', []);

        $this->expectException(ReconciliationException::class);
        $this->expectExceptionMessage('Edge evidence file was not discovered: src/A.php');
        $collapse->add(self::edge('calls', 1), 'S', 'T', self::OWNER);
    }

    public function testTheAssemblerCollapsesRepeatedCallsFromOneWorker(): void
    {
        $evidence = new Evidence('src/A.php', 1, 20);
        $contribution = new ScanContribution(
            self::OWNER,
            [
                new NodeFact('php:method:A::run', 'method', 'A::run', 'run', Origin::Ast, Confidence::Certain, $evidence),
                new NodeFact('php:method:A::helper', 'method', 'A::helper', 'helper', Origin::Ast, Confidence::Certain, $evidence),
            ],
            [
                new EdgeFact('calls', 'php:method:A::run', 'php:method:A::helper', Origin::Ast, Confidence::Certain, new Evidence('src/A.php', 6, 6)),
                new EdgeFact('calls', 'php:method:A::run', 'php:method:A::helper', Origin::Ast, Confidence::Certain, new Evidence('src/A.php', 5, 5)),
            ],
            [],
        );
        $discovery = new DiscoveryResult(
            '/workspace/collapse',
            [new DiscoveredFile('src/A.php', '/workspace/collapse/src/A.php', 'php', 40, 1_000, hash('sha256', 'a'))],
            [],
            [],
            hash('sha256', 'inputs'),
            hash('sha256', 'config'),
        );
        $request = new FullScanRequest('collapse', 'Collapse', $discovery, [new ScannerManifest('knossos.php', '0.1.0', '1.0', '1.0', ['php'], ['php'], [])], [$contribution]);

        $edges = (new GraphAssembler())->assemble($request)->edges;

        assertCount(1, $edges);
        assertSame(5, array_values($edges)[0]['start_line']);
    }

    /**
     * Collapse the edges as one owner's, all from S to T.
     *
     * @param list<EdgeFact> $edges
     * @return array<string, array<string, mixed>>
     */
    private function collapse(array $edges): array
    {
        $collapse = new EdgeCollapse('p', ['src/A.php' => 'file-a']);
        foreach ($edges as $edge) {
            $collapse->add($edge, 'S', 'T', self::OWNER);
        }

        return $collapse->records();
    }

    /**
     * One occurrence of an S to T edge on a line of src/A.php.
     *
     * @param array<string, mixed> $attributes
     */
    private static function edge(string $kind, int $line, Confidence $confidence = Confidence::Certain, array $attributes = [], Origin $origin = Origin::Ast): EdgeFact
    {
        return new EdgeFact($kind, 'S', 'T', $origin, $confidence, new Evidence('src/A.php', $line, $line), $attributes);
    }
}
