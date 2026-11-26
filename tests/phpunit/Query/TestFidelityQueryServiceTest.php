<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\TestFidelity\TestFindingKey;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/** `audit_tests` over a project the real TypeScript worker scanned: filters, order, paging and the per-language summary. */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class TestFidelityQueryServiceTest extends KnossosTestCase
{
    public function testFiltersByConfidenceAndPath(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('test-fidelity/ts-audit');
        try {
            $service = ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries;

            $all = $service->auditTests($projectId);
            $items = $all->data['items'];
            assertSame(['tests/ghost.test.ts', 'tests/planted.test.ts'], array_values(array_unique(array_column($items, 'file'))));
            assertSame(['GHOST_MEMBER'], array_values(array_unique(array_column(array_filter($items, static fn(array $i): bool => $i['file'] === 'tests/ghost.test.ts'), 'category'))));
            assertSame(count($items), $all->data['total']);
            foreach (['category', 'confidence', 'rule_id', 'sub_rule', 'message', 'file', 'line', 'double', 'target'] as $field) {
                self::assertArrayHasKey($field, $items[0]);
            }
            $sorted = $items;
            usort($sorted, static fn(array $a, array $b): int => [$a['file'], $a['line'], $a['category'], $a['rule_id']] <=> [$b['file'], $b['line'], $b['category'], $b['rule_id']]);
            assertSame($sorted, $items);

            $definite = $service->auditTests($projectId, confidence: 'definite')->data['items'];
            $warnings = $service->auditTests($projectId, confidence: 'warning')->data['items'];
            self::assertNotSame([], $definite);
            self::assertNotSame([], $warnings);
            assertSame(['definite'], array_values(array_unique(array_column($definite, 'confidence'))));
            assertSame(['warning'], array_values(array_unique(array_column($warnings, 'confidence'))));
            assertSame(count($items), count($definite) + count($warnings));

            $planted = $service->auditTests($projectId, pathPrefix: 'tests/planted')->data['items'];
            assertSame(['tests/planted.test.ts'], array_values(array_unique(array_column($planted, 'file'))));
            assertCount(0, $service->auditTests($projectId, pathPrefix: 'src/')->data['items']);
            assertSame(['TAUTOLOGY'], array_values(array_unique(array_column($service->auditTests($projectId, category: 'TAUTOLOGY')->data['items'], 'category'))));

            $page = $service->auditTests($projectId, limit: 1, offset: 1);
            assertSame([$items[1]], $page->data['items']);
            assertSame($items[1]['id'] ?? null, $page->data['items'][0]['id'] ?? null);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testASuppressionHidesAFindingWithoutARescan(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('test-fidelity/ts-audit');
        try {
            $service = ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries;
            $before = $service->auditTests($projectId);
            $ghosts = count(array_filter($before->data['items'], static fn(array $i): bool => $i['category'] === 'GHOST_MEMBER'));
            self::assertGreaterThan(0, $ghosts);
            $countBefore = array_sum($before->data['summary']['by_language']['typescript']);

            file_put_contents($root . '/knossos.json', json_encode(['version' => 1, 'test_finding_suppressions' => [['rule' => 'GHOST_MEMBER']]]));
            $after = $service->auditTests($projectId);

            assertSame([], array_filter($after->data['items'], static fn(array $i): bool => $i['category'] === 'GHOST_MEMBER'));
            assertSame($before->data['total'] - $ghosts, $after->data['total']);
            assertSame($countBefore - $ghosts, array_sum($after->data['summary']['by_language']['typescript']));
            // The filter runs before the page is cut, so the first page is the first unsuppressed finding.
            assertSame($after->data['items'][0], $service->auditTests($projectId, limit: 1)->data['items'][0]);

            file_put_contents($root . '/knossos.json', json_encode(['version' => 1, 'test_finding_suppressions' => [['rule' => 'GHOST_MEMBER', 'path' => 'tests/planted']]]));
            assertSame($before->data['total'], $service->auditTests($projectId)->data['total']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testAProjectWhoseFolderIsGoneStillListsItsStoredFindings(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('test-fidelity/ts-audit');
        $service = ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries;
        $before = $service->auditTests($projectId);
        $this->removeTempTree($root);

        $after = $service->auditTests($projectId);

        self::assertGreaterThan(0, $before->data['total']);
        assertSame($before->data['total'], $after->data['total']);
        assertSame(null, $after->data['suppressions_invalid']);
    }

    public function testAnInvalidConfigIsSaidNotSwallowed(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('test-fidelity/ts-audit');
        try {
            $service = ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries;
            $before = $service->auditTests($projectId);
            assertSame(null, $before->data['suppressions_invalid']);

            file_put_contents($root . '/knossos.json', json_encode(['version' => 1, 'test_finding_suppressions' => [['rule' => '']]]));
            $after = $service->auditTests($projectId);

            assertSame('PROJECT_CONFIG_INVALID: test_finding_suppressions entries need a rule.', $after->data['suppressions_invalid']);
            assertSame($before->data['total'], $after->data['total']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testItemsCarryTheirKey(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('test-fidelity/ts-audit');
        try {
            $service = ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries;
            $items = $service->auditTests($projectId)->data['items'];

            $canonical = $pdo->prepare('SELECT t.canonical_name FROM test_findings tf LEFT JOIN nodes t ON t.id = tf.target_node_id WHERE tf.id = ?');
            foreach ($items as $item) {
                $canonical->execute([$item['id']]);
                $target = $canonical->fetchColumn();
                assertSame(
                    TestFindingKey::of($item['rule_id'], $item['sub_rule'], (string) $item['file'], $target === false || $target === null ? null : (string) $target, $item['message']),
                    $item['key'],
                );
            }
            assertSame(count($items), count(array_unique(array_column($items, 'key'))));
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testAnImportedRustLabelIsCountedAsRust(): void
    {
        // An imported bundle labels Rust nodes `rs` (PortableGraphImporter); they must not open a language of their own.
        [$pdo, $projectId, $root] = $this->scanTempFixture('test-fidelity/ts-audit');
        try {
            $service = ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries;
            $typescript = $service->auditTests($projectId)->data['summary']['by_language']['typescript'];
            $pdo->exec("UPDATE nodes SET language = 'rs'");

            $byLanguage = $service->auditTests($projectId)->data['summary']['by_language'];

            assertSame(['php', 'python', 'rust', 'typescript'], array_keys($byLanguage));
            self::assertGreaterThan(0, $typescript['definite']);
            assertSame($typescript, $byLanguage['rust']);
            assertSame(['definite' => 0, 'warning' => 0], $byLanguage['typescript']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testCountsZerosForLanguagesWithoutFindings(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('test-fidelity/ts-audit');
        try {
            $service = ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries;
            $byLanguage = $service->auditTests($projectId)->data['summary']['by_language'];

            assertSame(['definite' => 0, 'warning' => 0], $byLanguage['php'], 'The PHP worker emits test facts; this project has no PHP findings.');
            assertSame(['definite' => 0, 'warning' => 0], $byLanguage['python'], 'The Python worker emits test facts; this project has no Python findings.');
            assertSame(['definite' => 0, 'warning' => 0], $byLanguage['rust'], 'The Rust worker emits test facts; this project has no Rust findings.');
            assertSame(['definite', 'warning'], array_keys($byLanguage['typescript']));
            self::assertGreaterThan(0, $byLanguage['typescript']['definite']);
            self::assertGreaterThan(0, $byLanguage['typescript']['warning']);
            // A filter narrows the items, never the project's counts.
            assertSame($byLanguage, $service->auditTests($projectId, confidence: 'definite')->data['summary']['by_language']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testATypeScriptProjectWithNoFindingsStillCountsZeros(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('test-fidelity/ts-audit');
        try {
            $service = ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries;
            $result = $service->auditTests($projectId, pathPrefix: 'nowhere/');

            assertSame([], $result->data['items']);
            assertSame(0, $result->data['total']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testRefusesAnUnknownConfidenceCategoryOrLimit(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('test-fidelity/ts-audit');
        try {
            $service = ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries;
            foreach ([
                ['confidence', 'maybe', 'confidence must be one of: definite, warning.'],
                ['category', 'NOPE', 'category must be one of: GHOST_MEMBER, ARITY_MISMATCH, RETURN_DRIFT, VISIBILITY_BREACH, TAUTOLOGY, MOCKS_SUBJECT.'],
                ['limit', 201, 'limit must be between 1 and 200.'],
                ['offset', -1, 'offset must be between 0 and 100000.'],
            ] as [$name, $value, $message]) {
                try {
                    $service->auditTests($projectId, ...[$name => $value]);
                    self::fail("{$name} should be refused");
                } catch (InvalidArgumentException $e) {
                    assertSame($message, $e->getMessage());
                }
            }
        } finally {
            $this->removeTempTree($root);
        }
    }
}
