<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/** The `audit_tests` tool: listed with its schema, and answering over a project the real TypeScript worker scanned. */
#[Group('mcp')]
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class AuditTestsToolTest extends KnossosTestCase
{
    public function testToolIsListedAndCallable(): void
    {
        [$tools, $projectId, $root] = $this->buildToolServiceWithScan('test-fidelity/ts-audit');
        try {
            $definitions = array_column($tools->definitions(), null, 'name');
            assertArrayHasKey('audit_tests', $definitions);
            $tool = $definitions['audit_tests'];
            assertSame('Audit tests', $tool['title']);
            assertSame('List test doubles that no longer match the code they replace and assertions that cannot fail. Use before trusting a green suite, or after changing a signature.', $tool['description']);
            assertSame(['project_id'], $tool['inputSchema']['required']);
            assertFalse($tool['inputSchema']['additionalProperties']);
            $properties = $tool['inputSchema']['properties'];
            foreach (['project_id', 'path_prefix', 'confidence', 'category', 'limit', 'offset', 'verbosity', 'max_chars', 'refresh_if_stale'] as $name) {
                assertArrayHasKey($name, $properties);
            }
            assertSame(['definite', 'warning'], $properties['confidence']['enum']);
            assertSame(['GHOST_MEMBER', 'ARITY_MISMATCH', 'RETURN_DRIFT', 'VISIBILITY_BREACH', 'TAUTOLOGY', 'MOCKS_SUBJECT'], $properties['category']['enum']);
            assertSame([1, 200, 50], [$properties['limit']['minimum'], $properties['limit']['maximum'], $properties['limit']['default']]);

            $envelope = $tools->call('audit_tests', ['project_id' => $projectId]);

            assertSame($projectId, $envelope->projectId);
            assertGreaterThanOrEqual(1, count($envelope->data['items']));
            assertSame('GHOST_MEMBER', $envelope->data['items'][0]['category']);
            assertSame('tests/ghost.test.ts', $envelope->data['items'][0]['file']);
            assertCount(1, $tools->call('audit_tests', ['project_id' => $projectId, 'category' => 'GHOST_MEMBER', 'limit' => 5])->data['items']);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
