<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\AnnotationNotes;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The notes a result carries for the components it names: who wrote each,
 * when, and whether a model's note still rests on the files it was written
 * against.
 */
final class AnnotationNotesTest extends KnossosTestCase
{
    #[Group('query')]
    public function testAModelNoteGoesStaleWhenAFileItRestsOnChangesAndFreshWhenReRecorded(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);
        $notes = new AnnotationNotes($pdo);
        $queries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'reached via the invoice queue', execute: true);

        $fresh = $notes->forComponents($ids['project'], ['App\\Checkout']);
        assertSame([[
            'component' => 'App\\Checkout',
            'kind' => 'note',
            'author' => 'model',
            'text' => 'reached via the invoice queue',
            'written' => gmdate('Y-m-d'),
            'stale' => false,
            'stale_reason' => null,
        ]], $fresh);

        $pdo->exec("UPDATE files SET content_hash = 'changed' WHERE relative_path = 'src/Checkout.php'");
        $stale = $notes->forComponents($ids['project'], ['App\\Checkout'])[0];
        assertSame([true, 'written before src/Checkout.php changed'], [$stale['stale'], $stale['stale_reason']]);

        // Re-recording confirms it against the code as it is now.
        $queries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'reached via the invoice queue', execute: true);
        assertSame(false, $notes->forComponents($ids['project'], ['App\\Checkout'])[0]['stale']);
    }

    #[Group('query')]
    public function testANoteWhoseFileIsGoneIsStaleAndAPersonsNoteNeverIs(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);
        $queries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'an agent found this', execute: true);
        $queries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'the team says so', execute: true, author: 'user');
        $pdo->exec("UPDATE nodes SET file_id = NULL WHERE file_id IN (SELECT id FROM files WHERE relative_path = 'src/Checkout.php')");
        $pdo->exec("UPDATE edges SET file_id = NULL WHERE file_id IN (SELECT id FROM files WHERE relative_path = 'src/Checkout.php')");
        $pdo->exec("DELETE FROM files WHERE relative_path = 'src/Checkout.php'");

        $notes = (new AnnotationNotes($pdo))->forComponents($ids['project'], ['App\\Checkout']);

        // The person's first, never stale; the model's says its file is gone.
        assertSame(['user', 'model'], array_column($notes, 'author'));
        assertSame([false, null], [$notes[0]['stale'], $notes[0]['stale_reason']]);
        assertSame([true, 'written before src/Checkout.php was removed'], [$notes[1]['stale'], $notes[1]['stale_reason']]);
    }

    #[Group('query')]
    public function testNotesAreCappedAndCutToFitAResult(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);
        $names = [];
        for ($i = 1; $i <= 12; $i++) {
            $names[] = 'App\\Upcoming' . $i;
            $queries->upsertAnnotation($ids['project'], 'App\\Upcoming' . $i, 'note', str_repeat('x', $i === 1 ? 400 : 10), execute: true);
        }

        $notes = (new AnnotationNotes($pdo))->forComponents($ids['project'], $names);

        assertSame(AnnotationNotes::MAX_NOTES, count($notes));
        assertSame(AnnotationNotes::MAX_TEXT, mb_strlen($notes[0]['text']));
        assertSame(true, str_ends_with($notes[0]['text'], '…'));
        assertSame([], (new AnnotationNotes($pdo))->forComponents($ids['project'], ['App\\Nothing']));
    }

    #[Group('query')]
    public function testTheNotesOfTheComponentAskedAboutSurviveTheCap(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);
        $names = ['Zz\\Target'];
        $queries->upsertAnnotation($ids['project'], 'Zz\\Target', 'note', 'the one asked about', execute: true);
        for ($i = 1; $i <= 11; $i++) {
            $names[] = 'App\\Dependant' . $i;
            $queries->upsertAnnotation($ids['project'], 'App\\Dependant' . $i, 'note', 'n' . $i, execute: true);
        }
        $queries->upsertAnnotation($ids['project'], 'App\\Dependant9', 'note', 'the team says so', execute: true, author: 'user');

        $notes = (new AnnotationNotes($pdo))->forComponents($ids['project'], $names);

        // A person's note first, then in the order the result named them: the target before its dependants.
        assertSame(['user App\\Dependant9', 'model Zz\\Target'], array_map(static fn(array $n): string => $n['author'] . ' ' . $n['component'], array_slice($notes, 0, 2)));
    }

    #[Group('query')]
    public function testAModelNoteRestingOnNoScannedFileSaysSo(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        ArchitectureQueryService::forDatabase($pdo)->upsertAnnotation($ids['project'], 'App\\NotYetWritten', 'note', 'planned seam', execute: true);

        $note = (new AnnotationNotes($pdo))->forComponents($ids['project'], ['App\\NotYetWritten'])[0];

        assertSame([true, 'rests on no scanned file'], [$note['stale'], $note['stale_reason']]);
    }
}
