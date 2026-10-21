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
        $queries->services()->annotationQueries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'reached via the invoice queue', execute: true);

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
        $queries->services()->annotationQueries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'reached via the invoice queue', execute: true);
        assertSame(false, $notes->forComponents($ids['project'], ['App\\Checkout'])[0]['stale']);
    }

    #[Group('query')]
    public function testANoteWhoseFileIsGoneIsStaleAndAPersonsNoteNeverIs(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);
        $queries->services()->annotationQueries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'an agent found this', execute: true);
        $queries->services()->annotationQueries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'the team says so', execute: true, author: 'user');
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
            $queries->services()->annotationQueries->upsertAnnotation($ids['project'], 'App\\Upcoming' . $i, 'note', str_repeat('x', match ($i) {
                1 => 1200, 2 => 980, default => 10,
            }), execute: true);
        }

        $notes = (new AnnotationNotes($pdo))->forComponents($ids['project'], $names);

        assertSame(AnnotationNotes::MAX_NOTES, count($notes));
        assertSame(AnnotationNotes::MAX_TEXT, mb_strlen($notes[0]['text']));
        assertSame(true, str_ends_with($notes[0]['text'], '…'));
        // A note the length agents write when they record a missed path comes back whole.
        assertSame(980, mb_strlen($notes[1]['text']));
        assertSame([], (new AnnotationNotes($pdo))->forComponents($ids['project'], ['App\\Nothing']));
    }

    #[Group('query')]
    public function testTheNotesOfTheComponentAskedAboutSurviveTheCap(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);
        $names = ['Zz\\Target'];
        $queries->services()->annotationQueries->upsertAnnotation($ids['project'], 'Zz\\Target', 'note', 'the one asked about', execute: true);
        for ($i = 1; $i <= 11; $i++) {
            $names[] = 'App\\Dependant' . $i;
            $queries->services()->annotationQueries->upsertAnnotation($ids['project'], 'App\\Dependant' . $i, 'note', 'n' . $i, execute: true);
        }
        $queries->services()->annotationQueries->upsertAnnotation($ids['project'], 'App\\Dependant9', 'note', 'the team says so', execute: true, author: 'user');

        $notes = (new AnnotationNotes($pdo))->forComponents($ids['project'], $names);

        // A person's note first, then in the order the result named them: the target before its dependants.
        assertSame(['user App\\Dependant9', 'model Zz\\Target'], array_map(static fn(array $n): string => $n['author'] . ' ' . $n['component'], array_slice($notes, 0, 2)));
    }

    #[Group('query')]
    public function testAModelNoteRestingOnNoScannedFileSaysSo(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        ArchitectureQueryService::forDatabase($pdo)->services()->annotationQueries->upsertAnnotation($ids['project'], 'App\\NotYetWritten', 'note', 'planned seam', execute: true);

        $note = (new AnnotationNotes($pdo))->forComponents($ids['project'], ['App\\NotYetWritten'])[0];

        assertSame([true, 'rests on no scanned file'], [$note['stale'], $note['stale_reason']]);
    }

    #[Group('query')]
    public function testANoteMayRestOnAFileNoScanReadsAndGoesStaleWhenItChanges(): void
    {
        // A CI script or a shell tool is no scanned file, yet often the fact a
        // note records rests on it. Its content hash is read from disk.
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $root = sys_get_temp_dir() . '/knossos-stale-evidence-' . bin2hex(random_bytes(6));
        mkdir($root . '/tools', 0o755, true);
        try {
            file_put_contents($root . '/tools/quality', "#!/bin/sh\necho gate\n");
            $pdo->prepare('UPDATE projects SET root_realpath = ? WHERE id = ?')->execute([realpath($root), $ids['project']]);
            $queries = ArchitectureQueryService::forDatabase($pdo);
            $queries->services()->annotationQueries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'the gate lane fails it', execute: true, evidencePaths: ['tools/quality']);
            $notes = new AnnotationNotes($pdo);

            assertSame(false, $notes->forComponents($ids['project'], ['App\\Checkout'])[0]['stale']);
            // A scan that reads the file later keeps its own hash; the note still checks the disk.
            $pdo->prepare("INSERT INTO files(id, project_id, relative_path, content_hash, size, mtime, language, scanner_version, last_scan_id) VALUES ('file_quality', ?, 'tools/quality', 'scanned-hash', 1, 1, 'shell', '1', ?)")
                ->execute([$ids['project'], $ids['scan']]);
            assertSame(false, $notes->forComponents($ids['project'], ['App\\Checkout'])[0]['stale']);
            file_put_contents($root . '/tools/quality', "#!/bin/sh\necho changed\n");
            assertSame('written before tools/quality changed', $notes->forComponents($ids['project'], ['App\\Checkout'])[0]['stale_reason']);
            unlink($root . '/tools/quality');
            assertSame('written before tools/quality was removed', $notes->forComponents($ids['project'], ['App\\Checkout'])[0]['stale_reason']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAFileOutsideTheProjectOrNotThereIsNoEvidence(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $root = sys_get_temp_dir() . '/knossos-stale-evidence-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        $outside = tempnam(sys_get_temp_dir(), 'knossos-outside-');
        try {
            symlink($outside, $root . '/escape');
            mkdir($root . '/folder');
            $pdo->prepare('UPDATE projects SET root_realpath = ? WHERE id = ?')->execute([realpath($root), $ids['project']]);
            $queries = ArchitectureQueryService::forDatabase($pdo);
            foreach (['escape' => 'outside the project', 'missing.sh' => 'is not a file of this project', 'folder' => 'is not a file of this project'] as $path => $refusal) {
                try {
                    $queries->services()->annotationQueries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'x', execute: true, evidencePaths: [$path]);
                    self::fail($path . ' was taken as evidence');
                } catch (\InvalidArgumentException $e) {
                    assertSame(true, str_contains($e->getMessage(), $refusal), $e->getMessage());
                }
            }
        } finally {
            @unlink($root . '/escape');
            @unlink($outside);
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testASearchFindsAComponentByTheTextOfANoteOnIt(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);
        $queries->services()->annotationQueries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'reached through the reconciliation hook, which also drafts the invoice', execute: true);

        $found = $queries->searchArchitecture($ids['project'], 'reconciliation hook');

        // Only the note holds those words, so the note says why the component came up.
        assertSame(['App\\Checkout'], array_column($found->data['results'], 'canonical_name'));
        assertSame(['App\\Checkout'], array_column($found->data['notes'], 'component'));

        // A name that holds the words still ranks above a note that does.
        $named = $queries->searchArchitecture($ids['project'], 'Invoice');
        assertSame(['App\\InvoiceService', 'App\\Checkout'], array_column($named->data['results'], 'canonical_name'));
    }

    /**
     * A note may rest on a name the graph does not hold: a symbol built at
     * runtime, or one kept before it exists. A search still finds it, listed
     * apart from the components because there is no component to show.
     */
    #[Group('query')]
    public function testASearchFindsANoteOnANameTheGraphDoesNotHold(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);
        $queries->services()->annotationQueries->upsertAnnotation($ids['project'], 'App\\Plugins\\Loader::boot', 'note', 'Plugins are loaded by name from config/plugins.php at boot', execute: true);
        $queries->services()->annotationQueries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'Plugins hook in here', execute: true);

        $found = $queries->searchArchitecture($ids['project'], 'plugins');

        assertSame(['App\\Checkout'], array_column($found->data['results'], 'canonical_name'));
        $apart = $found->data['notes_without_component'];
        assertSame(['App\\Plugins\\Loader::boot'], array_column($apart, 'component'));
        assertSame('model', $apart[0]['author']);
        assertSame(true, str_contains($apart[0]['text'], 'loaded by name'));
        // Every word must match, in the name or the note.
        assertSame([], $queries->searchArchitecture($ids['project'], 'plugins invoice')->data['notes_without_component']);
        // A filter on components leaves out notes that have none.
        assertSame([], $queries->searchArchitecture($ids['project'], 'plugins', kinds: ['class'])->data['notes_without_component']);
    }
}
