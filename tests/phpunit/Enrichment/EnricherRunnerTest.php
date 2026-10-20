<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Enrichment;

use Knossos\Discovery\EnricherDefinition;
use Knossos\Enrichment\EnricherRunner;
use Knossos\Enrichment\NativeNodeIndex;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * One run of an external enricher over the worker protocol: what it is sent,
 * what comes back, and that every way it can go wrong is an outcome rather
 * than an exception.
 */
#[Group('enrichment')]
final class EnricherRunnerTest extends KnossosTestCase
{
    private const PROJECT = 'project_enricher_runner';

    public function testSuccessfulRunReturnsDecodedContribution(): void
    {
        $native = $this->native();
        $outcome = (new EnricherRunner())->run($this->definition('ok', ['module', 'function']), '/tmp', $native);

        assertTrue($outcome->ok);
        assertSame('fixture', $outcome->name);
        assertNull($outcome->reason);
        assertNotNull($outcome->raw);
        $kinds = array_map(static fn(NodeFact $node): string => $node->kind, $outcome->raw->nodes);
        assertSame(['ext:rest_call', 'ext:endpoint'], $kinds);
        $component = $native->nodesOfKinds(['module'])[0]['id'];
        $view = $native->nodesOfKinds(['function'])[0]['id'];
        $edges = array_map(static fn($edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference], $outcome->raw->edges);
        assertSame([
            ['calls_endpoint', $component, 'call-search'],
            ['calls_endpoint', 'call-search', 'endpoint-search'],
            ['represents', 'endpoint-search', $view],
        ], $edges);
    }

    public function testReceivesOnlyDeclaredNodeKinds(): void
    {
        $outcome = (new EnricherRunner())->run($this->definition('echo-input', ['function']), '/tmp', $this->native());

        assertTrue($outcome->ok);
        assertNotNull($outcome->raw);
        assertCount(1, $outcome->raw->nodes);
        $received = $outcome->raw->nodes[0]->attributes['received'];
        ksort($received);
        assertSame(['file', 'id', 'kind', 'line', 'qualified_name'], array_keys($received));
        assertSame('function', $received['kind']);
        assertSame('backend.app.urls.search', $received['qualified_name']);
        assertSame('backend/app/urls.py', $received['file']);
        assertSame(4, $received['line']);
    }

    public function testMissingConfidenceDefaultsToProbable(): void
    {
        $outcome = (new EnricherRunner())->run($this->definition('no-confidence'), '/tmp', $this->native());

        assertTrue($outcome->ok);
        assertNotNull($outcome->raw);
        assertSame(Confidence::Probable, $outcome->raw->nodes[0]->confidence);
    }

    public function testGarbageOutputIsAFailure(): void
    {
        foreach (['garbage', 'stderr-then-garbage'] as $mode) {
            $this->assertFailure('protocol_error', $this->definition($mode), $mode);
        }
    }

    public function testTimeoutIsAFailure(): void
    {
        $started = hrtime(true);
        $this->assertFailure('timeout', $this->definition('hang', timeoutSeconds: 1));
        assertTrue(hrtime(true) - $started < 10_000_000_000, 'A hanging enricher is stopped at its timeout.');
    }

    public function testMissingCapabilityIsAFailure(): void
    {
        $this->assertFailure('no_enrichment_capability', $this->definition('no-capability'));
    }

    public function testMissingBinaryIsAFailure(): void
    {
        $this->assertFailure('missing_command', new EnricherDefinition('fixture', ['/nonexistent/enricher']));
    }

    public function testNonZeroExitIsAFailure(): void
    {
        $this->assertFailure('exit_nonzero', $this->definition('exit-1'));
    }

    public function testEnvironmentIsTheAllowListPlusTheDefinitions(): void
    {
        putenv('KNOSSOS_ENRICHER_TEST_SECRET=leaked');
        try {
            $definition = new EnricherDefinition('fixture', [PHP_BINARY, self::script(), 'env'], environment: ['FIXTURE_FLAG' => 'on']);
            $outcome = (new EnricherRunner())->run($definition, '/tmp', $this->native());
        } finally {
            putenv('KNOSSOS_ENRICHER_TEST_SECRET');
        }

        assertTrue($outcome->ok);
        assertNotNull($outcome->raw);
        assertSame(['flag' => 'on', 'secret' => false, 'path_set' => true], $outcome->raw->nodes[0]->attributes);
    }

    private function assertFailure(string $reason, EnricherDefinition $definition, string $message = ''): void
    {
        $outcome = (new EnricherRunner())->run($definition, '/tmp', $this->native());

        assertFalse($outcome->ok, $message);
        assertSame($reason, $outcome->reason, $message);
        assertNull($outcome->raw, $message);
        assertSame('fixture', $outcome->name);
    }

    /** @param list<string> $kinds */
    private function definition(string $mode, array $kinds = [], int $timeoutSeconds = 10): EnricherDefinition
    {
        return new EnricherDefinition('fixture', [PHP_BINARY, self::script(), $mode], $timeoutSeconds, [], $kinds);
    }

    private static function script(): string
    {
        return self::repositoryRoot() . '/tests/Fixtures/enrichers/fixture-enricher.php';
    }

    /** A Vue module, a Django view and a class, as the native workers would report them. */
    private function native(): NativeNodeIndex
    {
        return NativeNodeIndex::build(self::PROJECT, [
            new ScanContribution('typescript:file:frontend/src/components/OrderList.vue', [
                new NodeFact('ts:module:OrderList', 'module', 'frontend/src/components/OrderList.vue', 'OrderList.vue', Origin::Ast, Confidence::Certain, new Evidence('frontend/src/components/OrderList.vue', 1, 12)),
            ]),
            new ScanContribution('python:file:backend/app/urls.py', [
                new NodeFact('py:function:search', 'function', 'backend.app.urls.search', 'search', Origin::Ast, Confidence::Certain, new Evidence('backend/app/urls.py', 4, 5)),
                new NodeFact('py:class:Other', 'class', 'backend.app.urls.Other', 'Other', Origin::Ast, Confidence::Certain, new Evidence('backend/app/urls.py', 10, 11)),
            ]),
        ]);
    }
}
