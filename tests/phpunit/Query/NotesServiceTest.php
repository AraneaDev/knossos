<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\NotesService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\BusyWriteStatement;
use Knossos\Tests\Phpunit\Support\PaneAgreementGraph;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertSame;

/**
 * The pane's Notes tab: every note in the project, grouped so what needs the
 * person comes first (missing, then stale, then the rest), filtered, counted,
 * and the writes the tab offers: adopt, move, flag.
 */
#[Group('query')]
final class NotesServiceTest extends KnossosTestCase
{
    use PaneAgreementGraph;

    public function testNotesAreGroupedMissingThenStaleThenThePersonsThenTheModelsNewestFirst(): void
    {
        [$pdo, $root] = $this->notesGraph();

        $notes = (new NotesService($pdo))->notes($root);

        assertSame('ok', $notes['status']);
        assertSame(['App\\Gone'], self::names($notes['groups']['missing']));
        assertSame(['App\\Chain1'], self::names($notes['groups']['stale']));
        assertSame(['App\\User', 'App\\Maybe', 'App\\Target'], self::names($notes['groups']['rest']));
        assertSame(['total' => 5, 'missing' => 1, 'stale' => 1, 'flagged' => 1], $notes['counts']);
        $target = $notes['groups']['rest'][2];
        assertSame(['model', 'note', 'present', false, true, 'is the cache still per request?'], [$target['author'], $target['kind'], $target['component_status'], $target['stale'], $target['checked'], $target['check_reason']]);
    }

    public function testEachFilterAndTheTextNarrowTheList(): void
    {
        [$pdo, $root] = $this->notesGraph();
        $service = new NotesService($pdo);
        $listed = static fn(string $filter, string $text = ''): array => self::names([...$service->notes($root, $filter, $text)['groups']['missing'], ...$service->notes($root, $filter, $text)['groups']['stale'], ...$service->notes($root, $filter, $text)['groups']['rest']]);

        assertSame(['App\\User'], $listed('yours'));
        assertSame(['App\\Gone', 'App\\Chain1', 'App\\Maybe', 'App\\Target'], $listed('model'));
        assertSame(['App\\Chain1'], $listed('stale'));
        assertSame(['App\\Gone'], $listed('missing'));
        assertSame(['App\\Target'], $listed('flagged'));
        assertSame(['App\\Gone', 'App\\Target'], $listed('all', 'ZEBRAFISH'));
        assertSame(['App\\User'], $listed('all', 'app\\user'));
        // The counts never narrow: the Overview line reads them.
        assertSame(5, $service->notes($root, 'yours')['counts']['total']);
    }

    public function testAnUnknownFilterIsRefusedAndAnUnscannedPathSaysSo(): void
    {
        [$pdo, $root] = $this->notesGraph();
        $service = new NotesService($pdo);

        assertSame('refused', $service->notes($root, 'everything')['status']);
        assertSame('unscanned', $service->notes(sys_get_temp_dir(), 'all')['status']);
    }

    public function testAdoptingWritesThePersonsNoteWithTheModelsTextAndKeepsTheModels(): void
    {
        [$pdo, $root, $projectId] = $this->notesGraph();
        $service = new NotesService($pdo);

        $preview = $service->adopt($root, 'App\\Target');
        assertSame(['ok', false], [$preview['status'], $preview['executed']]);
        assertSame([], self::stored($pdo, $projectId, 'App\\Target', 'user'));

        $adopted = $service->adopt($root, 'App\\Target', execute: true);

        assertSame(['ok', true], [$adopted['status'], $adopted['executed']]);
        assertSame(['Built once per request; the zebrafish cache keeps it.'], self::stored($pdo, $projectId, 'App\\Target', 'user'));
        assertSame(['Built once per request; the zebrafish cache keeps it.'], self::stored($pdo, $projectId, 'App\\Target', 'model'));
        assertSame('refused', $service->adopt($root, 'App\\User', execute: true)['status']);
    }

    public function testAdoptingCanTakeTheEditedText(): void
    {
        [$pdo, $root, $projectId] = $this->notesGraph();

        (new NotesService($pdo))->adopt($root, 'App\\Target', 'Built once per request.', execute: true);

        assertSame(['Built once per request.'], self::stored($pdo, $projectId, 'App\\Target', 'user'));
    }

    public function testMovingWritesTheNoteAtTheTargetAndRemovesTheOld(): void
    {
        [$pdo, $root, $projectId] = $this->notesGraph();

        $moved = (new NotesService($pdo))->move($root, 'App\\Gone', 'App\\Adapter', author: 'model', execute: true);

        assertSame(['ok', true, 'App\\Adapter'], [$moved['status'], $moved['executed'], $moved['to']]);
        assertSame([], self::stored($pdo, $projectId, 'App\\Gone', 'model'));
        assertSame(['Replaced by the zebrafish adapter.'], self::stored($pdo, $projectId, 'App\\Adapter', 'model'));
    }

    public function testAMoveToAMissingNameOrOntoANoteByTheSameAuthorIsRefusedAndWritesNothing(): void
    {
        [$pdo, $root, $projectId] = $this->notesGraph();
        $service = new NotesService($pdo);
        $before = self::all($pdo, $projectId);

        $missing = $service->move($root, 'App\\Gone', 'App\\AlsoGone', author: 'model', execute: true);
        $taken = $service->move($root, 'App\\Gone', 'App\\Target', author: 'model', execute: true);
        $nothing = $service->move($root, 'App\\Gone', 'App\\Adapter', author: 'user', execute: true);

        assertSame(['refused', 'App\\AlsoGone is not in the graph either: pick a component it holds.'], [$missing['status'], $missing['reason']]);
        assertSame(['refused', 'App\\Target already holds a note by the model: edit or remove that one first.'], [$taken['status'], $taken['reason']]);
        assertSame(['refused', 'App\\Gone has no note by the user to move.'], [$nothing['status'], $nothing['reason']]);
        assertSame($before, self::all($pdo, $projectId));
    }

    public function testFlaggingWritesThePersonsCheckAndClearingRemovesIt(): void
    {
        [$pdo, $root, $projectId] = $this->notesGraph();
        $service = new NotesService($pdo);

        $service->check($root, 'App\\Maybe', 'the probable edge is gone?', execute: true);
        $flagged = $service->notes($root, 'flagged');
        $service->check($root, 'App\\Maybe', remove: true, execute: true);

        assertSame(['App\\Maybe', 'App\\Target'], self::names($flagged['groups']['rest']));
        assertSame(['App\\Target'], self::names($service->notes($root, 'flagged')['groups']['rest']));
        assertSame([], self::stored($pdo, $projectId, 'App\\Maybe', 'user', 'check'));
    }

    /**
     * A write that meets a scan's lock answers with the busy code and leaves
     * the notes as they were: a move's two writes go together or not at all.
     */
    public function testABusyDatabaseAnswersWithItsCodeAndWritesNothing(): void
    {
        [$pdo, $root, $projectId] = $this->notesGraph();
        $service = new NotesService($pdo);
        $before = self::all($pdo, $projectId);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [BusyWriteStatement::class, []]);

        BusyWriteStatement::$failOn = 'INSERT INTO annotations';
        $adopt = $service->adopt($root, 'App\\Target', execute: true);
        BusyWriteStatement::$failOn = 'DELETE FROM annotations';
        $move = $service->move($root, 'App\\Gone', 'App\\Adapter', author: 'model', execute: true);
        BusyWriteStatement::$failOn = null;

        foreach ([$adopt, $move] as $answer) {
            assertSame(['error', 'KNOSSOS_DATABASE_BUSY'], [$answer['status'], $answer['code']]);
        }
        assertSame($before, self::all($pdo, $projectId));
    }

    /**
     * The agreement graph's two model notes (on App\Target, with a check on
     * it, and on App\Gone, which no component holds), plus a model note on
     * App\Maybe, a stale one on App\Chain1, and the person's on App\User,
     * written a minute apart in that order.
     *
     * @return array{0: PDO, 1: string, 2: string}
     */
    private function notesGraph(): array
    {
        [$pdo, $root, $projectId] = $this->paneAgreementGraph();
        $queries = ArchitectureQueryService::forDatabase($pdo);
        // Recorded again through the service, so it rests on its file and is not stale.
        $queries->upsertAnnotation($projectId, 'App\\Target', 'note', 'Built once per request; the zebrafish cache keeps it.', execute: true);
        $queries->upsertAnnotation($projectId, 'App\\Chain1', 'note', 'Wraps the user.', execute: true);
        $queries->upsertAnnotation($projectId, 'App\\Maybe', 'note', 'Calls the target on a guess.', execute: true);
        $queries->upsertAnnotation($projectId, 'App\\User', 'note', 'Mine.', execute: true, author: 'user');
        $pdo->prepare("INSERT INTO annotations(project_id, canonical_name, kind, value, author, created_at, updated_at) VALUES (?, 'App\\Target', 'check', 'is the cache still per request?', 'user', '2026-10-16T10:00:00Z', '2026-10-16T10:00:00Z')")->execute([$projectId]);
        $at = 0;
        foreach (['App\\Target', 'App\\Gone', 'App\\Chain1', 'App\\Maybe', 'App\\User'] as $name) {
            $pdo->prepare("UPDATE annotations SET updated_at = ? WHERE project_id = ? AND canonical_name = ? AND kind = 'note'")->execute([sprintf('2026-10-16T11:%02d:00Z', $at++), $projectId, $name]);
        }
        $pdo->prepare("UPDATE files SET content_hash = 'edited' WHERE project_id = ? AND relative_path = 'src/App/Chain1.php'")->execute([$projectId]);

        return [$pdo, $root, $projectId];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private static function names(array $rows): array
    {
        return array_map(static fn(array $row): string => (string) $row['component'], $rows);
    }

    /** @return list<string> the values stored on a component by an author */
    private static function stored(PDO $pdo, string $projectId, string $component, string $author, string $kind = 'note'): array
    {
        $statement = $pdo->prepare('SELECT value FROM annotations WHERE project_id = ? AND canonical_name = ? AND kind = ? AND author = ?');
        $statement->execute([$projectId, $component, $kind, $author]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array<string, mixed>> every annotation row, in a fixed order */
    private static function all(PDO $pdo, string $projectId): array
    {
        $statement = $pdo->prepare('SELECT canonical_name, kind, author, value FROM annotations WHERE project_id = ? ORDER BY canonical_name, kind, author');
        $statement->execute([$projectId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
