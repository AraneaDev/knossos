<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A note is keyed by its component's name, so a rename or move leaves it on a
 * name the graph no longer has. The listing says which notes those are, so the
 * one who wrote them can move or remove them.
 */
final class AnnotationPresenceTest extends KnossosTestCase
{
    #[Group('query')]
    public function testAListingMarksNotesWhoseComponentIsGone(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);
        $queries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'present', execute: true);
        $queries->upsertAnnotation($ids['project'], 'App\\RenamedAway', 'note', 'orphaned', execute: true);

        $result = $queries->listAnnotations($ids['project']);

        $presence = array_column($result->data['annotations'], 'component_status', 'canonical_name');
        self::assertSame(['App\\Checkout' => 'present', 'App\\RenamedAway' => 'missing'], $presence);
        self::assertSame('Found 2 annotations, 1 on a component the graph no longer has.', $result->summary);
    }

    /**
     * A model note whose file changed since it was written is marked stale,
     * with the reason; a person's note never is.
     */
    public function testAListingMarksStaleModelNotesAndNeverAPersons(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);
        $queries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'the model saw this', execute: true);
        $queries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'the person wrote this', execute: true, author: 'user');
        $queries->upsertAnnotation($ids['project'], 'App\\InvoiceService', 'note', 'declared in that file too', execute: true);
        $before = array_column($queries->listAnnotations($ids['project'])->data['annotations'], 'stale');
        $pdo->exec("UPDATE files SET content_hash = 'edited' WHERE relative_path = 'src/Checkout.php'");

        $rows = $queries->listAnnotations($ids['project'])->data['annotations'];

        self::assertSame([false, false, false], $before);
        self::assertSame(
            [['user', false, null], ['model', true, 'written before src/Checkout.php changed'], ['model', true, 'written before src/Checkout.php changed']],
            array_map(static fn(array $row): array => [$row['author'], $row['stale'], $row['stale_reason']], $rows),
        );
    }
}
