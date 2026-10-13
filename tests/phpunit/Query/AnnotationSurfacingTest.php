<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A result carries the notes of the components it names, so what an earlier
 * session learned arrives with the answer it bears on, not only when an agent
 * thinks to inspect the component.
 */
final class AnnotationSurfacingTest extends KnossosTestCase
{
    /** @return array{0: ArchitectureQueryService, 1: string} */
    private function annotated(bool $withNotes = true): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);
        if ($withNotes) {
            $queries->upsertAnnotation($ids['project'], 'App\\InvoiceService', 'note', 'totals are recomputed nightly', execute: true);
            $queries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'the only way an order is placed', execute: true, author: 'user');
        }
        return [$queries, $ids['project']];
    }

    /** @param array<string, mixed> $data @return list<string> */
    private static function noted(array $data): array
    {
        return array_map(static fn(array $n): string => $n['author'] . ' ' . $n['component'], $data['notes'] ?? []);
    }

    #[Group('query')]
    public function testAUsageListCarriesTheNotesOfItsTargetAndItsCallers(): void
    {
        [$queries, $project] = $this->annotated();
        assertSame(['user App\\Checkout', 'model App\\InvoiceService'], self::noted($queries->listUsages($project, 'App\\InvoiceService')->data));
    }

    #[Group('query')]
    public function testImpactFlowFindFileAndInspectCarryTheNotesTheyName(): void
    {
        [$queries, $project] = $this->annotated();
        assertSame(['user App\\Checkout', 'model App\\InvoiceService'], self::noted($queries->impactAnalysis($project, 'App\\InvoiceService')->data));
        assertSame(['user App\\Checkout', 'model App\\InvoiceService'], self::noted($queries->explainFlow($project, 'App\\Checkout', 'App\\InvoiceService')->data));
        assertSame(['model App\\InvoiceService'], self::noted($queries->findComponent($project, 'InvoiceService')->data));
        // Inspecting Checkout names InvoiceService as what it calls, so its note comes along too.
        assertSame(['user App\\Checkout', 'model App\\InvoiceService'], self::noted($queries->inspectComponent($project, 'App\\Checkout')->data));
        // inspect_component keeps its own annotations list beside the notes.
        assertSame('the only way an order is placed', $queries->inspectComponent($project, 'App\\Checkout')->data['component']['annotations'][0]['value']);
        assertSame(true, self::noted($queries->fileContext($project, 'src/Checkout.php')->data) !== []);
    }

    #[Group('query')]
    public function testAResultNamingNoAnnotatedComponentCarriesNoNotes(): void
    {
        [$queries, $project] = $this->annotated(withNotes: false);
        assertSame(false, array_key_exists('notes', $queries->listUsages($project, 'App\\InvoiceService')->data));
        assertSame(false, array_key_exists('notes', $queries->inspectComponent($project, 'App\\Checkout')->data));
    }
}
