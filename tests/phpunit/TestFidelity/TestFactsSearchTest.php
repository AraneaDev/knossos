<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * Search and the summary describe the architecture: the test doubles and
 * test cases a scan derives from test code are found only when a kind filter
 * asks for them, and the lists of names a class or module declares (its
 * inherited members included) match no search term.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class TestFactsSearchTest extends KnossosTestCase
{
    private const FILES = [
        'tsconfig.json' => '{"compilerOptions":{"strict":true,"module":"esnext","target":"es2022","moduleResolution":"bundler"}}',
        'src/base.ts' => "export class Base {\n    purgeEverything(): void {}\n}\n",
        'src/child.ts' => "import { Base } from './base';\nexport class Child extends Base {}\n",
        'src/zoo.ts' => "export function zebraCount(): number { return 1; }\n",
        'src/order.ts' => "export class Order {\n    quantityOrdered!: number;\n}\nexport class BigOrder extends Order {}\nexport enum Lane { Fast = 'fastLaneValue' }\n",
        'tests/base.test.ts' => "import { it, vi } from 'vitest';\nimport { Base } from '../src/base';\n"
            . "it('purges', () => {\n    vi.spyOn(new Base(), 'purgeEverything');\n});\n",
    ];

    private PDO $pdo;

    private string $projectId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $root = self::scratchPath('ts-test-facts-search');
        foreach (self::FILES as $path => $contents) {
            if (!is_dir(dirname($root . '/' . $path))) {
                mkdir(dirname($root . '/' . $path), 0o777, true);
            }
            file_put_contents($root . '/' . $path, $contents);
        }
        $this->pdo = $this->freshTestDatabase();
        $this->projectId = (new ProjectScanService($this->pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
        assertGreaterThan(0, (int) $this->pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'test_double'")->fetchColumn());
    }

    public function testANameListMatchesNoSearchTerm(): void
    {
        // Base lists purgeEverything among its member names, Child inherits
        // it and the base module exports Base: only the method itself matches.
        assertSame([['method', 'src/base.ts#Base::purgeEverything']], $this->found('purgeEverything'));
    }

    public function testAFieldOrCaseListMatchesNoSearchTerm(): void
    {
        // Order and BigOrder list quantityOrdered among their fields, and Lane
        // lists its case's value: only the property itself matches.
        assertSame([['property', 'src/order.ts#Order::quantityOrdered']], $this->found('quantityOrdered'));
        assertSame([], $this->found('fastLaneValue'));
    }

    public function testAModulesExportsMatchNoSearchTerm(): void
    {
        $found = $this->found('zebraCount');

        assertNotContains('module', array_column($found, 0));
        assertSame(1, count($found));
    }

    public function testATestFactIsFoundOnlyThroughAKindFilter(): void
    {
        assertNotContains('test_double', array_column($this->found('spyOn'), 0));
        assertNotContains('test_case', array_column($this->found('purges'), 0));

        assertSame(['test_double'], array_values(array_unique(array_column($this->found('spyOn', ['test_double']), 0))));
        assertSame(['test_case'], array_column($this->found('purges', ['test_case']), 0));
    }

    public function testTheSummaryCountsNoTestFact(): void
    {
        $summary = ArchitectureQueryService::forDatabase($this->pdo)->services()->summaryQueries->architectureSummary($this->projectId)->data;
        $kinds = array_column($summary['node_kinds'], 'kind');

        assertNotContains('test_double', $kinds);
        assertNotContains('test_case', $kinds);
        assertContains('class', $kinds);
        assertNotContains('doubles', array_column($summary['edge_kinds'], 'kind'));
    }

    /**
     * Each result as [kind, canonical name], in result order.
     *
     * @param list<string> $kinds
     * @return list<array{0: string, 1: string}>
     */
    private function found(string $query, array $kinds = []): array
    {
        $results = ArchitectureQueryService::forDatabase($this->pdo)->services()->componentQueries->searchArchitecture($this->projectId, $query, $kinds)->data['results'];

        return array_map(static fn(array $result): array => [(string) $result['kind'], (string) $result['canonical_name']], $results);
    }
}
