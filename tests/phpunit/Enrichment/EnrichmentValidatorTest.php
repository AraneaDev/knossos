<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Enrichment;

use Knossos\Enrichment\EnrichmentValidator;
use Knossos\Enrichment\NativeNodeIndex;
use Knossos\Enrichment\ValidatedEnrichment;
use Knossos\Reconciliation\FullScanRequest;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\Fixtures;
use PDO;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('enrichment')]
final class EnrichmentValidatorTest extends KnossosTestCase
{
    use Fixtures;

    private const PROJECT = 'proj';
    private const PATH = 'src/A.php';
    private const SCAN = 'scan-1';
    private const AT = '2026-10-20T10:00:00Z';

    private function ev(string $path = self::PATH): Evidence
    {
        return new Evidence($path, 3, 5);
    }

    private function nativeNode(string $local, string $kind, string $canonical): NodeFact
    {
        return new NodeFact($local, $kind, $canonical, $canonical, Origin::Ast, Confidence::Certain, $this->ev());
    }

    private function stable(string $kind, string $canonical): string
    {
        return StableId::symbol(self::PROJECT, 'php', $kind, $canonical);
    }

    /** Native graph: methods run and other (run calls other), class A, a file node. */
    private function validator(): EnrichmentValidator
    {
        $native = new ScanContribution('php:src/A.php', [
            $this->nativeNode('php:run', 'method', 'A::run'),
            $this->nativeNode('php:other', 'method', 'A::other'),
            $this->nativeNode('php:cls', 'class', 'A'),
            $this->nativeNode('php:route', 'route', 'GET /a'),
        ], [
            new EdgeFact('calls', 'php:run', 'php:other', Origin::Ast, Confidence::Certain, $this->ev()),
        ]);

        return new EnrichmentValidator(NativeNodeIndex::build(self::PROJECT, [$native]), [self::PATH => true]);
    }

    private function extNode(string $local, string $kind = 'ext:rest_call', string $canonical = 'GET /x', Confidence $confidence = Confidence::Probable, Origin $origin = Origin::Ast, string $path = self::PATH): NodeFact
    {
        return new NodeFact($local, $kind, $canonical, $canonical, $origin, $confidence, $this->ev($path), ['custom' => 'kept']);
    }

    private function extEdge(string $kind, string $source, string $target, Confidence $confidence = Confidence::Probable, string $path = self::PATH): EdgeFact
    {
        return new EdgeFact($kind, $source, $target, Origin::Ast, $confidence, $this->ev($path));
    }

    /**
     * @param list<NodeFact> $nodes
     * @param list<EdgeFact> $edges
     */
    private function validate(array $nodes, array $edges = [], string $enricher = 'acme', ?EnrichmentValidator $validator = null): ValidatedEnrichment
    {
        return ($validator ?? $this->validator())->validate($enricher, self::SCAN, self::AT, new ScanContribution('whatever', $nodes, $edges));
    }

    private function assertDropped(ValidatedEnrichment $result, string $code, string $mentions): void
    {
        assertCount(1, $result->dropped);
        $diagnostic = $result->dropped[0];
        assertSame($code, $diagnostic->code);
        assertSame('warning', $diagnostic->severity);
        assertStringContainsString('acme', $diagnostic->message);
        assertStringContainsString($mentions, $diagnostic->message);
    }

    public function testNativeNodeKindIsRefused(): void
    {
        $result = $this->validate([$this->extNode('x', 'method', 'A::x'), $this->extNode('y')]);

        $this->assertDropped($result, 'ENRICHER_NATIVE_KIND', 'x');
        assertCount(1, $result->contribution->nodes);
        assertSame('ext:acme/y', $result->contribution->nodes[0]->localId);
    }

    public function testLocalIdsAreNamespaced(): void
    {
        $result = $this->validate(
            [$this->extNode('x'), $this->extNode('y', canonical: 'GET /y')],
            [$this->extEdge('calls', 'x', 'y')],
        );

        assertSame([], $result->dropped);
        assertSame('enricher:acme', $result->contribution->ownerKey);
        assertSame(['ext:acme/x', 'ext:acme/y'], array_map(static fn(NodeFact $n): string => $n->localId, $result->contribution->nodes));
        assertSame('ext:acme/x', $result->contribution->edges[0]->sourceReference);
        assertSame('ext:acme/y', $result->contribution->edges[0]->targetReference);
    }

    public function testTwoEnrichersSameLocalIdDoNotCollide(): void
    {
        [$pdo, $reconciler, $request] = $this->reconciliationFixture();
        $index = NativeNodeIndex::build(StableId::project($request->projectIdentity), $request->contributions);
        $validator = new EnrichmentValidator($index, ['src/CheckoutService.php' => true]);
        $node = static fn(string $canonical): NodeFact => new NodeFact('same', 'ext:call', $canonical, $canonical, Origin::Ast, Confidence::Probable, new Evidence('src/CheckoutService.php', 7, 9));
        $a = $validator->validate('a', self::SCAN, self::AT, new ScanContribution('a', [$node('GET /a')]));
        $b = $validator->validate('b', self::SCAN, self::AT, new ScanContribution('b', [$node('GET /b')]));

        $reconciler->reconcile(new FullScanRequest(
            $request->projectIdentity,
            $request->projectName,
            $request->discovery,
            [...$request->scanners, new ScannerManifest('enricher', '1.0.0', '1.0', '1.0', ['ext'], ['ext'], ['enrichment'])],
            [...$request->contributions, $a->contribution, $b->contribution],
        ));

        $names = $pdo->query("SELECT canonical_name FROM nodes WHERE kind = 'ext:call' ORDER BY canonical_name")->fetchAll(PDO::FETCH_COLUMN);
        assertSame(['GET /a', 'GET /b'], $names);
        $owners = $pdo->query("SELECT owner_key FROM nodes WHERE kind = 'ext:call' ORDER BY canonical_name")->fetchAll(PDO::FETCH_COLUMN);
        assertSame(['enricher:a', 'enricher:b'], $owners);
    }

    public function testTwoEnrichersSameKindAndCanonicalNameDoNotCollide(): void
    {
        [$pdo, $reconciler, $request] = $this->reconciliationFixture();
        $index = NativeNodeIndex::build(StableId::project($request->projectIdentity), $request->contributions);
        $validator = new EnrichmentValidator($index, ['src/CheckoutService.php' => true]);
        $node = static fn(): NodeFact => new NodeFact('x', 'ext:call', 'GET /same', 'same', Origin::Ast, Confidence::Probable, new Evidence('src/CheckoutService.php', 7, 9));
        $a = $validator->validate('a', self::SCAN, self::AT, new ScanContribution('a', [$node()]));
        $b = $validator->validate('b', self::SCAN, self::AT, new ScanContribution('b', [$node()]));

        $reconciler->reconcile(new FullScanRequest(
            $request->projectIdentity,
            $request->projectName,
            $request->discovery,
            [...$request->scanners, new ScannerManifest('enricher', '1.0.0', '1.0', '1.0', ['ext'], ['ext'], ['enrichment'])],
            [...$request->contributions, $a->contribution, $b->contribution],
        ));

        $rows = $pdo->query("SELECT owner_key, attributes_json FROM nodes WHERE kind = 'ext:call' ORDER BY owner_key")->fetchAll(PDO::FETCH_ASSOC);
        assertSame(['enricher:a', 'enricher:b'], array_column($rows, 'owner_key'));
        assertSame(['a', 'b'], array_map(static fn(array $r): string => json_decode($r['attributes_json'], true)['enricher'], $rows));
    }

    public function testEnricherDiagnosticsAreCappedAtWarningAndPrefixed(): void
    {
        $raw = new ScanContribution('whatever', [], [], [
            new \Knossos\Scanner\Protocol\Diagnostic('error', 'FOO', 'it broke'),
            new \Knossos\Scanner\Protocol\Diagnostic('info', 'BAR', 'fyi', $this->ev('vendor/x.py')),
        ]);

        $diagnostics = $this->validator()->validate('acme', self::SCAN, self::AT, $raw)->contribution->diagnostics;

        assertSame(['warning', 'info'], array_map(static fn($d): string => $d->severity, $diagnostics));
        assertSame(['ENRICHER_REPORTED:FOO', 'ENRICHER_REPORTED:BAR'], array_map(static fn($d): string => $d->code, $diagnostics));
        assertSame(null, $diagnostics[1]->evidence);
    }

    public function testEdgeToUnknownIdIsDropped(): void
    {
        $result = $this->validate(
            [$this->extNode('x'), $this->extNode('y', canonical: 'GET /y')],
            [$this->extEdge('calls', 'x', 'ghost'), $this->extEdge('calls', 'x', 'y')],
        );

        $this->assertDropped($result, 'ENRICHER_UNKNOWN_ENDPOINT', 'ghost');
        assertCount(1, $result->contribution->edges);
        assertSame('ext:acme/y', $result->contribution->edges[0]->targetReference);
    }

    public function testEdgeToNativeStableIdIsRewrittenToNativeLocalId(): void
    {
        $result = $this->validate(
            [$this->extNode('x')],
            [$this->extEdge('calls', 'x', $this->stable('method', 'A::other'))],
        );

        assertSame([], $result->dropped);
        assertSame('ext:acme/x', $result->contribution->edges[0]->sourceReference);
        assertSame('php:other', $result->contribution->edges[0]->targetReference);
    }

    public function testContainsEdgeIsRefused(): void
    {
        $result = $this->validate(
            [$this->extNode('x')],
            [$this->extEdge('contains', $this->stable('class', 'A'), 'x'), $this->extEdge('calls', 'x', $this->stable('method', 'A::run'))],
        );

        $this->assertDropped($result, 'ENRICHER_FORBIDDEN_KIND', 'contains');
        assertCount(1, $result->contribution->edges);
    }

    public function testDuplicateOfNativeEdgeIsDropped(): void
    {
        $run = $this->stable('method', 'A::run');
        $other = $this->stable('method', 'A::other');

        $result = $this->validate(
            [$this->extNode('x')],
            [$this->extEdge('calls', $run, $other), $this->extEdge('imports', $run, $other)],
        );

        $this->assertDropped($result, 'ENRICHER_DUPLICATES_NATIVE', 'calls');
        assertCount(1, $result->contribution->edges);
        assertSame('imports', $result->contribution->edges[0]->kind);
    }

    public function testUndiscoveredEvidencePathIsDropped(): void
    {
        $result = $this->validate(
            [$this->extNode('x'), $this->extNode('v', canonical: 'GET /v', path: 'vendor/x.py'), $this->extNode('y', canonical: 'GET /y')],
            [$this->extEdge('calls', 'x', 'y', path: 'vendor/x.py'), $this->extEdge('calls', 'x', 'y')],
        );

        assertCount(2, $result->dropped);
        assertSame(['ENRICHER_UNDISCOVERED_FILE', 'ENRICHER_UNDISCOVERED_FILE'], array_map(static fn($d): string => $d->code, $result->dropped));
        assertCount(2, $result->contribution->nodes);
        assertCount(1, $result->contribution->edges);
    }

    public function testCertainIsLoweredToProbable(): void
    {
        $result = $this->validate(
            [$this->extNode('x', confidence: Confidence::Certain), $this->extNode('y', canonical: 'GET /y', confidence: Confidence::Possible)],
            [$this->extEdge('calls', 'x', 'y', Confidence::Certain)],
        );

        assertSame(Confidence::Probable, $result->contribution->nodes[0]->confidence);
        assertSame(Confidence::Possible, $result->contribution->nodes[1]->confidence);
        assertSame(Confidence::Probable, $result->contribution->edges[0]->confidence);
    }

    public function testOriginIsForcedToEnricher(): void
    {
        $result = $this->validate([$this->extNode('x', origin: Origin::Ast), $this->extNode('y', canonical: 'GET /y')], [$this->extEdge('calls', 'x', 'y')]);

        assertSame(Origin::Enricher, $result->contribution->nodes[0]->origin);
        assertSame(Origin::Enricher, $result->contribution->edges[0]->origin);
    }

    public function testAttributesCarryEnricherNameScanAndTime(): void
    {
        $result = $this->validate([$this->extNode('x'), $this->extNode('y', canonical: 'GET /y')], [$this->extEdge('calls', 'x', 'y')]);

        $expected = ['enricher' => 'acme', 'enricher_scan_id' => self::SCAN, 'enricher_produced_at' => self::AT];
        foreach ([$result->contribution->nodes[0]->attributes, $result->contribution->edges[0]->attributes] as $attributes) {
            foreach ($expected as $key => $value) {
                assertSame($value, $attributes[$key]);
            }
        }
        assertSame('kept', $result->contribution->nodes[0]->attributes['custom']);
    }

    public function testAnEnricherCannotForgeReconcilerAttributes(): void
    {
        $node = new NodeFact('x', 'ext:call', 'GET /x', 'x', Origin::Ast, Confidence::Probable, $this->ev(), ['scanner' => 'php', 'scanner_local_id' => 'php:run', 'enricher' => 'other']);

        $attributes = $this->validate([$node])->contribution->nodes[0]->attributes;

        assertSame('acme', $attributes['enricher']);
        $this->assertArrayNotHasKey('scanner', $attributes);
        $this->assertArrayNotHasKey('scanner_local_id', $attributes);
    }

    public function testRepresentsLinksAnEnrichedRecordToANativeMethod(): void
    {
        $result = $this->validate([$this->extNode('x')], [$this->extEdge('represents', 'x', $this->stable('method', 'A::run'))]);

        assertSame([], $result->dropped);
        assertSame('represents', $result->contribution->edges[0]->kind);
        assertSame('php:run', $result->contribution->edges[0]->targetReference);
    }

    public function testRepresentsCitingAnotherFileThanTheTargetsIsDropped(): void
    {
        $result = $this->validate([$this->extNode('x')], [$this->representsAt('src/B.php', 3)], validator: $this->validatorWithSecondFile());

        $this->assertDropped($result, 'ENRICHER_REPRESENTS_INVALID', 'src/B.php');
        assertSame([], $result->contribution->edges);
    }

    public function testRepresentsCitingALineOutsideTheTargetsDeclarationIsDropped(): void
    {
        foreach ([2, 6] as $line) {
            $result = $this->validate([$this->extNode('x')], [$this->representsAt(self::PATH, $line)]);

            $this->assertDropped($result, 'ENRICHER_REPRESENTS_INVALID', 'lines 3-5');
            assertSame([], $result->contribution->edges, 'line ' . $line);
        }
    }

    public function testRepresentsCitingALineInsideTheTargetsDeclarationIsKept(): void
    {
        foreach ([3, 5] as $line) {
            $result = $this->validate([$this->extNode('x')], [$this->representsAt(self::PATH, $line)]);

            assertSame([], $result->dropped, 'line ' . $line);
            assertCount(1, $result->contribution->edges);
        }
    }

    /** A `represents` from x to A::run whose evidence is one line of $path. */
    private function representsAt(string $path, int $line): EdgeFact
    {
        return new EdgeFact('represents', 'x', $this->stable('method', 'A::run'), Origin::Ast, Confidence::Probable, new Evidence($path, $line, $line));
    }

    /** The usual native graph, with src/B.php discovered as well. */
    private function validatorWithSecondFile(): EnrichmentValidator
    {
        $native = new ScanContribution('php:src/A.php', [$this->nativeNode('php:run', 'method', 'A::run')]);

        return new EnrichmentValidator(NativeNodeIndex::build(self::PROJECT, [$native]), [self::PATH => true, 'src/B.php' => true]);
    }

    public function testRepresentsToUnknownNativeIsDropped(): void
    {
        $result = $this->validate([$this->extNode('x')], [$this->extEdge('represents', 'x', $this->stable('method', 'A::missing'))]);

        $this->assertDropped($result, 'ENRICHER_REPRESENTS_INVALID', 'x');
        assertSame([], $result->contribution->edges);
        assertCount(1, $result->contribution->nodes);
    }

    public function testRepresentsToNonRepresentableKindIsDropped(): void
    {
        $result = $this->validate([$this->extNode('x')], [$this->extEdge('represents', 'x', $this->stable('class', 'A'))]);

        $this->assertDropped($result, 'ENRICHER_REPRESENTS_INVALID', 'x');
        assertSame([], $result->contribution->edges);
    }

    public function testRepresentsFromNativeSourceIsDropped(): void
    {
        $result = $this->validate([$this->extNode('x')], [$this->extEdge('represents', $this->stable('method', 'A::run'), $this->stable('method', 'A::other'))]);

        $this->assertDropped($result, 'ENRICHER_REPRESENTS_INVALID', 'php:run');
        assertSame([], $result->contribution->edges);
    }

    public function testSecondRepresentsFromSameSourceIsDropped(): void
    {
        $result = $this->validate([$this->extNode('x')], [
            $this->extEdge('represents', 'x', $this->stable('method', 'A::run')),
            $this->extEdge('represents', 'x', $this->stable('route', 'GET /a')),
        ]);

        $this->assertDropped($result, 'ENRICHER_REPRESENTS_INVALID', 'x');
        assertCount(1, $result->contribution->edges);
        assertSame('php:run', $result->contribution->edges[0]->targetReference);
    }

    public function testASecondNodeReusingALocalIdWithDifferentIdentityIsDropped(): void
    {
        $result = $this->validate([$this->extNode('x'), $this->extNode('x', canonical: 'GET /other')]);

        $this->assertDropped($result, 'ENRICHER_CONFLICTING_ID', 'x');
        assertCount(1, $result->contribution->nodes);
    }

    public function testValidProfileStringIsKept(): void
    {
        $result = $this->validate([$this->extNode('x'), $this->extNode('y', canonical: 'GET /y')], [$this->profiledEdge('brand_a')]);

        assertSame([], $result->dropped);
        assertSame('brand_a', $result->contribution->edges[0]->attributes['profile']);
    }

    public function testValidProfileListIsKeptDeduplicated(): void
    {
        $result = $this->validate([$this->extNode('x'), $this->extNode('y', canonical: 'GET /y')], [$this->profiledEdge(['brand_b', 'brand-a.v2', 'brand_b'])]);

        assertSame([], $result->dropped);
        assertSame(['brand_b', 'brand-a.v2'], $result->contribution->edges[0]->attributes['profile']);
    }

    /** An invalid profile costs the edge its profile, never the edge itself. */
    #[DataProvider('invalidProfiles')]
    public function testInvalidProfileIsRemovedAndWarned(mixed $profile): void
    {
        $result = $this->validate([$this->extNode('x'), $this->extNode('y', canonical: 'GET /y')], [$this->profiledEdge($profile)]);

        $this->assertDropped($result, 'ENRICHER_PROFILE_INVALID', 'x');
        assertStringContainsString('y', $result->dropped[0]->message);
        assertCount(1, $result->contribution->edges);
        assertArrayNotHasKey('profile', $result->contribution->edges[0]->attributes);
        assertSame('acme', $result->contribution->edges[0]->attributes['enricher']);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidProfiles(): array
    {
        return [
            'empty list' => [[]],
            'number' => [5],
            'empty string' => [''],
            'list with a bad name' => [['ok', 'bad value']],
            '65 characters' => [str_repeat('a', 65)],
            'map' => [['a' => 'brand_a']],
            'null' => [null],
            'trailing newline' => ["brand_a\n"],
            'nested list' => [[['brand_a']]],
        ];
    }

    private function profiledEdge(mixed $profile): EdgeFact
    {
        return new EdgeFact('calls', 'x', 'y', Origin::Ast, Confidence::Probable, $this->ev(), ['profile' => $profile]);
    }
}
