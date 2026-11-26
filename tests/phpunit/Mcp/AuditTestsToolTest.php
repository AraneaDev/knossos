<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Mcp\ToolService;
use Knossos\TestFidelity\TestRuleCatalog;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\Group;

/**
 * The `audit_tests` and `list_test_rules` tools: listed with their schemas,
 * and answering over a project the real TypeScript worker scanned.
 *
 * `ts-audit` holds three stored findings (a ghost at `tests/ghost.test.ts:6`,
 * two tautologies in `tests/planted.test.ts`) and one on-request finding:
 * `tests/ghost.test.ts` doubles the one module it imports, and only one of
 * its assertions reads production code.
 */
#[Group('mcp')]
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class AuditTestsToolTest extends KnossosTestCase
{
    private const OVER_MOCKED = '1/1 dependencies mocked, 1 production assertions: over-mocked';

    public function testTierScanIsUnchanged(): void
    {
        $this->withAudit(function (ToolService $tools, string $projectId): void {
            $definitions = array_column($tools->definitions(), null, 'name');
            assertArrayHasKey('audit_tests', $definitions);
            $tool = $definitions['audit_tests'];
            assertSame('Audit tests', $tool['title']);
            assertSame('List test doubles that no longer match the code they replace and assertions that cannot fail. Use before trusting a green suite, or after changing a signature.', $tool['description']);
            assertSame(['project_id'], $tool['inputSchema']['required']);
            assertFalse($tool['inputSchema']['additionalProperties']);
            $properties = $tool['inputSchema']['properties'];
            foreach (['project_id', 'path_prefix', 'confidence', 'category', 'limit', 'offset', 'tier', 'scope', 'base_ref', 'timeout_ms', 'verbosity', 'max_chars', 'refresh_if_stale'] as $name) {
                assertArrayHasKey($name, $properties);
            }
            assertSame(['definite', 'warning'], $properties['confidence']['enum']);
            assertSame(['GHOST_MEMBER', 'ARITY_MISMATCH', 'RETURN_DRIFT', 'VISIBILITY_BREACH', 'TAUTOLOGY', 'MOCKS_SUBJECT', 'OVER_MOCKING', 'STALE_SINCE', 'FIXTURE_DRIFT'], $properties['category']['enum']);
            assertSame(TestRuleCatalog::categories(), $properties['category']['enum']);
            assertSame([1, 200, 50], [$properties['limit']['minimum'], $properties['limit']['maximum'], $properties['limit']['default']]);
            // No schema default: the tier left out is scan, or all in scope git-diff, which the description says.
            assertSame([['scan', 'on_request', 'all'], false], [$properties['tier']['enum'], array_key_exists('default', $properties['tier'])]);
            assertStringContainsString('scan (the default; all in scope git-diff)', $properties['tier']['description']);
            assertSame([['all', 'git-diff'], 'all'], [$properties['scope']['enum'], $properties['scope']['default']]);
            assertSame([1, 10000, 2000], [$properties['timeout_ms']['minimum'], $properties['timeout_ms']['maximum'], $properties['timeout_ms']['default']]);
            assertSame(['string', 1], [$properties['base_ref']['type'], $properties['base_ref']['minLength']]);

            $envelope = $tools->call('audit_tests', ['project_id' => $projectId]);

            assertSame($projectId, $envelope->projectId);
            assertSame(3, $envelope->data['total']);
            assertSame([['tests/ghost.test.ts', 6, 'GHOST_MEMBER'], ['tests/planted.test.ts', 4, 'TAUTOLOGY'], ['tests/planted.test.ts', 10, 'TAUTOLOGY']], self::positions($envelope->data['items']));
            assertSame(['scan'], array_values(array_unique(array_column($envelope->data['items'], 'tier'))));
            assertSame(['id', 'key', 'category', 'confidence', 'rule_id', 'sub_rule', 'message', 'file', 'line', 'double', 'target', 'tier'], array_keys($envelope->data['items'][0]));
            assertSame(false, $envelope->data['partial']);
            assertSame(['definite' => 2, 'warning' => 1], $envelope->data['summary']['by_language']['typescript']);
            assertArrayNotHasKey('notes', $envelope->data['summary']['by_language']['php']);
            assertCount(1, $tools->call('audit_tests', ['project_id' => $projectId, 'category' => 'GHOST_MEMBER', 'limit' => 5])->data['items']);
            assertSame($envelope->data, $tools->call('audit_tests', ['project_id' => $projectId, 'tier' => 'scan'])->data);
        });
    }

    public function testTierOnRequestListsOverMocking(): void
    {
        $this->withAudit(function (ToolService $tools, string $projectId): void {
            $envelope = $tools->call('audit_tests', ['project_id' => $projectId, 'tier' => 'on_request']);

            assertSame(1, $envelope->data['total']);
            $item = $envelope->data['items'][0];
            assertSame(['tests/ghost.test.ts', 1, 'OVER_MOCKING', 'warning', 'saturation', 'on_request', self::OVER_MOCKED], [$item['file'], $item['line'], $item['category'], $item['confidence'], $item['sub_rule'], $item['tier'], $item['message']]);
            assertSame([null, null], [$item['double'], $item['target']]);
            assertSame('1 test-fidelity finding.', $envelope->summary);
            // by_language counts what the asked tier holds: the one on-request warning.
            assertSame(['definite' => 0, 'warning' => 1], $envelope->data['summary']['by_language']['typescript']);
            assertSame(false, $envelope->data['partial']);
        });
    }

    public function testCategoryOverMockingImpliesItsTier(): void
    {
        $this->withAudit(function (ToolService $tools, string $projectId): void {
            $items = $tools->call('audit_tests', ['project_id' => $projectId, 'category' => 'OVER_MOCKING'])->data['items'];
            assertSame([['tests/ghost.test.ts', 1, 'OVER_MOCKING']], self::positions($items));

            // A scan category under tier on_request is the scan tier's, as asked.
            $ghosts = $tools->call('audit_tests', ['project_id' => $projectId, 'category' => 'GHOST_MEMBER', 'tier' => 'on_request'])->data['items'];
            assertSame([['tests/ghost.test.ts', 6, 'GHOST_MEMBER']], self::positions($ghosts));
            // The filters narrow on-request items as they narrow stored ones.
            assertSame([], $tools->call('audit_tests', ['project_id' => $projectId, 'tier' => 'on_request', 'confidence' => 'definite'])->data['items']);
            assertSame([], $tools->call('audit_tests', ['project_id' => $projectId, 'tier' => 'on_request', 'path_prefix' => 'tests/planted'])->data['items']);
            assertCount(1, $tools->call('audit_tests', ['project_id' => $projectId, 'tier' => 'on_request', 'path_prefix' => 'tests/ghost', 'confidence' => 'warning'])->data['items']);
        });
    }

    public function testTierAllMergesInFileOrderAndPaginates(): void
    {
        $this->withAudit(function (ToolService $tools, string $projectId): void {
            $all = $tools->call('audit_tests', ['project_id' => $projectId, 'tier' => 'all']);
            assertSame(4, $all->data['total']);
            $expected = [['tests/ghost.test.ts', 1, 'OVER_MOCKING'], ['tests/ghost.test.ts', 6, 'GHOST_MEMBER'], ['tests/planted.test.ts', 4, 'TAUTOLOGY'], ['tests/planted.test.ts', 10, 'TAUTOLOGY']];
            assertSame($expected, self::positions($all->data['items']));
            assertSame(['on_request', 'scan', 'scan', 'scan'], array_column($all->data['items'], 'tier'));
            assertSame(['definite' => 2, 'warning' => 2], $all->data['summary']['by_language']['typescript']);

            $first = $tools->call('audit_tests', ['project_id' => $projectId, 'tier' => 'all', 'limit' => 2]);
            assertSame(array_slice($expected, 0, 2), self::positions($first->data['items']));
            assertSame(['offset' => 0, 'next_offset' => 2], $first->data['pagination']);
            assertSame([4, true], [$first->data['total'], $first->truncated]);
            $second = $tools->call('audit_tests', ['project_id' => $projectId, 'tier' => 'all', 'limit' => 2, 'offset' => 2]);
            assertSame(array_slice($expected, 2), self::positions($second->data['items']));
            assertSame(['offset' => 2, 'next_offset' => null], $second->data['pagination']);
            $middle = $tools->call('audit_tests', ['project_id' => $projectId, 'tier' => 'all', 'limit' => 1, 'offset' => 1]);
            assertSame([$expected[1]], self::positions($middle->data['items']));
            assertSame([], $tools->call('audit_tests', ['project_id' => $projectId, 'tier' => 'all', 'offset' => 4])->data['items']);
        });
    }

    public function testSuppressionAppliesToOnRequest(): void
    {
        $this->withAudit(function (ToolService $tools, string $projectId, string $root): void {
            foreach (['OVER_MOCKING', 'OVER_MOCKING/saturation', 'test.fidelity.over_mocking.v1'] as $rule) {
                file_put_contents($root . '/knossos.json', (string) json_encode(['version' => 1, 'test_finding_suppressions' => [['rule' => $rule, 'path' => 'tests/ghost']]]));
                $envelope = $tools->call('audit_tests', ['project_id' => $projectId, 'tier' => 'all']);
                assertSame(['GHOST_MEMBER', 'TAUTOLOGY', 'TAUTOLOGY'], array_column($envelope->data['items'], 'category'), $rule);
                assertSame(3, $envelope->data['total'], $rule);
                assertSame(['definite' => 2, 'warning' => 1], $envelope->data['summary']['by_language']['typescript'], $rule);
            }
            // A path the file is not under suppresses nothing.
            file_put_contents($root . '/knossos.json', (string) json_encode(['version' => 1, 'test_finding_suppressions' => [['rule' => 'OVER_MOCKING', 'path' => 'tests/planted']]]));
            assertCount(1, $tools->call('audit_tests', ['project_id' => $projectId, 'tier' => 'on_request'])->data['items']);
        });
    }

    public function testDeterministicAndNonEmpty(): void
    {
        $this->withAudit(function (ToolService $tools, string $projectId): void {
            $first = $tools->call('audit_tests', ['project_id' => $projectId, 'tier' => 'all'])->data;
            $second = $tools->call('audit_tests', ['project_id' => $projectId, 'tier' => 'all'])->data;

            assertGreaterThanOrEqual(1, count(array_filter($first['items'], static fn(array $item): bool => $item['tier'] === 'on_request')));
            assertSame(json_encode($first), json_encode($second));
            $ids = array_column($first['items'], 'id');
            assertSame(count($ids), count(array_unique($ids)));
            assertTrue(str_starts_with($first['items'][0]['id'], 'on_request:'));
            assertSame('on_request:' . $first['items'][0]['key'], $first['items'][0]['id']);
            assertNotSame($first['items'][0]['key'], $first['items'][1]['key']);
        });
    }

    public function testListTestRules(): void
    {
        $this->withAudit(function (ToolService $tools): void {
            $definitions = array_column($tools->definitions(), null, 'name');
            assertArrayHasKey('list_test_rules', $definitions);
            $tool = $definitions['list_test_rules'];
            assertSame('List test rules', $tool['title']);
            assertArrayNotHasKey('required', $tool['inputSchema']);
            assertFalse($tool['inputSchema']['additionalProperties']);
            assertArrayNotHasKey('project_id', $tool['inputSchema']['properties']);
            assertSame(['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false], $tool['annotations']);

            $envelope = $tools->call('list_test_rules', []);

            assertSame(TestRuleCatalog::all(), $envelope->data['rules']);
            assertSame('knossos.json test_finding_suppressions', $envelope->data['suppression']);
            assertSame('9 test-fidelity rules.', $envelope->summary);
        });
    }

    /**
     * Run `$test` against a scanned copy of `ts-audit`, removed afterwards.
     *
     * @param \Closure(ToolService, string, string): void $test
     */
    private function withAudit(\Closure $test): void
    {
        [$tools, $projectId, $root] = $this->buildToolServiceWithScan('test-fidelity/ts-audit');
        try {
            $test($tools, $projectId, $root);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Each item's file, line and category.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array{mixed, mixed, mixed}>
     */
    private static function positions(array $items): array
    {
        return array_map(static fn(array $item): array => [$item['file'], $item['line'], $item['category']], $items);
    }
}
