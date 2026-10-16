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
}
