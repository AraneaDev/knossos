<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\ReportableComponent;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class ListUsagesTest extends KnossosTestCase
{
    #[Group('query')]
    public function testListsEveryUsageOccurrenceWithEvidence(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        // Second call site from Checkout to InvoiceService at a different line:
        // occurrence-level edges mean this is a distinct row, not a merge.
        $second = StableId::edge($ids['project'], 'calls', $ids['checkout'], $ids['invoice'], 'src/Checkout.php:15');
        $repository->saveEdge($second, $ids['project'], 'calls', $ids['checkout'], $ids['invoice'], $ids['file'], 15, 15, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        // A containment edge that must NOT appear as a usage.
        $contains = StableId::edge($ids['project'], 'contains', $ids['checkout'], $ids['invoice'], 'c:1');
        $repository->saveEdge($contains, $ids['project'], 'contains', $ids['checkout'], $ids['invoice'], $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);

        $result = $queries->listUsages($ids['project'], 'App\\InvoiceService');
        assertSame('App\\InvoiceService', $result->data['target']['canonical_name']);
        assertSame(2, count($result->data['usages']));
        assertSame([12, 15], array_column($result->data['usages'], 'start_line'));
        assertSame('src/Checkout.php', $result->data['usages'][0]['path']);
        assertSame('App\\Checkout', $result->data['usages'][0]['source']['canonical_name']);
        assertSame('calls', $result->data['usages'][0]['kind']);
        // Deterministic: identical on repeat.
        assertSame($result->jsonSerialize(), $queries->listUsages($ids['project'], 'App\\InvoiceService')->jsonSerialize());

        $limited = $queries->listUsages($ids['project'], 'App\\InvoiceService', limit: 1);
        assertSame(1, count($limited->data['usages']));
        assertSame(true, $limited->truncated);
        assertSame(['result_limit'], $limited->data['bounds']['truncation_reasons']);
        // The count reports how many usages exist, not how many fitted: a
        // summary reading "Found 1 usage site" for a symbol used 98 times is
        // the opposite of what the caller asked.
        assertContains('Found 2 usage sites', $limited->summary);
        assertSame(2, $limited->data['bounds']['total_matched']);
        assertSame(1, $limited->data['bounds']['total_shown']);

        assertThrows(fn() => $queries->listUsages($ids['project'], 'App\\InvoiceService', limit: 501), InvalidArgumentException::class);
        assertThrows(fn() => $queries->listUsages($ids['project'], 'App\\InvoiceService', edgeKinds: ['contains']), InvalidArgumentException::class);
    }

    #[Group('query')]
    public function testUsageCarriesTheQualificationsStoredOnItsEdge(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        // A usage that only holds under one profile: dropping the attribute
        // turns a conditional relationship into an unconditional one.
        $qualified = StableId::edge($ids['project'], 'calls', $ids['checkout'], $ids['invoice'], 'src/Checkout.php:15');
        $repository->saveEdge($qualified, $ids['project'], 'calls', $ids['checkout'], $ids['invoice'], $ids['file'], 15, 15, 'ast', 'certain', ['profile' => 'TC'], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $usages = ArchitectureQueryService::forDatabase($pdo)->listUsages($ids['project'], 'App\\InvoiceService')->data['usages'];

        assertSame([12, 15], array_column($usages, 'start_line'));
        assertSame([], $usages[0]['attributes']);
        assertSame(['profile' => 'TC'], $usages[1]['attributes']);
    }

    #[Group('query')]
    public function testAmbiguousAndMissingSymbolsReportCandidates(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);

        $missing = $queries->listUsages($ids['project'], 'App\\Nothing');
        assertSame(false, array_key_exists('target', $missing->data));
        assertSame([], $missing->data['candidates']);

        // Both fixture classes share the 'App\' prefix; a bare prefix is ambiguous.
        $ambiguous = $queries->listUsages($ids['project'], 'App\\');
        assertSame(true, $ambiguous->data['ambiguous']);
        assertSame(2, count($ambiguous->data['candidates']));
    }

    #[Group('query')]
    public function testANameWithoutItsNamespaceResolvesByItsLastSegments(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $node = function (string $language, string $kind, string $canonical, string $display) use ($repository, $ids): string {
            $id = StableId::symbol($ids['project'], $language, $kind, $canonical);
            $repository->saveNode($id, $ids['project'], $language, $kind, $canonical, $display, null, $ids['file'], 1, 2, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
            return $id;
        };
        $total = $node('php', 'method', 'App\\Billing\\TaxService::total', 'total');
        $node('python', 'method', 'documents.views.BulkEditView.post', 'post');
        $node('php', 'class', 'Legacy\\Checkout', 'Checkout');
        $call = StableId::edge($ids['project'], 'calls', $ids['checkout'], $total, 'src/Checkout.php:14');
        $repository->saveEdge($call, $ids['project'], 'calls', $ids['checkout'], $total, $ids['file'], 14, 14, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);

        // The way an agent writes a method: its class, without the namespace.
        $method = $queries->listUsages($ids['project'], 'TaxService::total');
        assertSame('App\\Billing\\TaxService::total', $method->data['target']['canonical_name']);
        assertSame([14], array_column($method->data['usages'], 'start_line'));
        assertSame('App\\Billing\\TaxService::total', $queries->listUsages($ids['project'], 'Billing\\TaxService::total')->data['target']['canonical_name']);
        assertSame('documents.views.BulkEditView.post', $queries->listUsages($ids['project'], 'BulkEditView.post')->data['target']['canonical_name']);
        // A member written with the other language's separator still finds it.
        assertSame('documents.views.BulkEditView.post', $queries->listUsages($ids['project'], 'BulkEditView::post')->data['target']['canonical_name']);
        assertSame('App\\Billing\\TaxService::total', $queries->listUsages($ids['project'], 'TaxService.total')->data['target']['canonical_name']);

        // Only whole segments match: a name that merely ends the same way is not the component.
        $partial = $queries->listUsages($ids['project'], 'axService::total');
        assertSame(false, array_key_exists('target', $partial->data));
        assertSame([], $partial->data['candidates']);

        // Two components the short name fits are reported, not guessed between.
        $ambiguous = $queries->listUsages($ids['project'], 'App\\Checkout');
        assertSame('App\\Checkout', $ambiguous->data['target']['canonical_name']);
        $both = $queries->listUsages($ids['project'], '\\Checkout');
        assertSame(true, $both->data['ambiguous']);
        assertSame(['App\\Checkout', 'Legacy\\Checkout'], array_column($both->data['candidates'], 'canonical_name'));
    }

    #[Group('query')]
    public function testEachUsageSaysWhetherItIsTestCodeAndTheSummarySaysWhatTheListCovers(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $test = StableId::symbol($ids['project'], 'php', 'method', 'Tests\\InvoiceTest::testTotal');
        $repository->saveNode($test, $ids['project'], 'php', 'method', 'Tests\\InvoiceTest::testTotal', 'testTotal', null, $ids['file'], 40, 44, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveClassification('classification_test', $ids['project'], $test, ReportableComponent::TEST_ROLE, 'rule', 'certain', 'php.test', $ids['file'], 40, 44, [], $ids['scan']);
        $call = StableId::edge($ids['project'], 'calls', $test, $ids['invoice'], 'src/Checkout.php:42');
        $repository->saveEdge($call, $ids['project'], 'calls', $test, $ids['invoice'], $ids['file'], 42, 42, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);

        $result = $queries->listUsages($ids['project'], 'App\\InvoiceService');
        assertSame([false, true], array_column($result->data['usages'], 'test'));
        assertSame(1, $result->data['bounds']['outside_tests']);
        assertContains('Found 2 usage sites of App\\InvoiceService, 1 outside tests.', $result->summary);
        assertContains('calls through strings, reflection or dynamic dispatch are not', $result->summary);

        // Cut by the limit, the counts still cover every match.
        $limited = $queries->listUsages($ids['project'], 'App\\InvoiceService', limit: 1);
        assertSame(1, $limited->data['bounds']['outside_tests']);
        assertContains('Found 2 usage sites of App\\InvoiceService, 1 outside tests; showing 1.', $limited->summary);
    }

    #[Group('query')]
    public function testDispatchThroughToolService(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $tools = new \Knossos\Mcp\ToolService(
            new \Knossos\Scan\ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            ArchitectureQueryService::forDatabase($pdo),
            new \Knossos\Maintenance\DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );
        $result = $tools->call('list_usages', ['project_id' => $ids['project'], 'symbol' => 'App\\InvoiceService']);
        assertSame(1, count($result->data['usages']));
        // Compact rows keep what a reader of the list uses; full keeps the bookkeeping too.
        $row = $result->data['usages'][0];
        assertSame(['kind', 'confidence', 'source', 'path', 'start_line', 'end_line', 'test'], array_keys($row));
        $full = $tools->call('list_usages', ['project_id' => $ids['project'], 'symbol' => 'App\\InvoiceService', 'verbosity' => 'full'])->data['usages'][0];
        assertSame(true, isset($full['edge_id'], $full['origin']));
        assertSame([], $full['attributes']);
        assertThrows(fn() => $tools->call('list_usages', ['project_id' => $ids['project'], 'symbol' => 'X', 'limit' => 0]), InvalidArgumentException::class);
    }
}
