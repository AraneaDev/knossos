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

    /** Each note says where it lives: the file its component is in and that file's boundary; a note on a missing name, nowhere. */
    public function testEachNoteSaysWhereItLives(): void
    {
        [$pdo, $root, $projectId] = $this->notesGraph();
        // A boundary that holds every component sorts first by name, and says nothing about where a file sits.
        $scan = (string) $pdo->query("SELECT active_scan_id FROM projects WHERE id = '{$projectId}'")->fetchColumn();
        $repository = new \Knossos\Store\SqliteGraphRepository($pdo);
        $wide = \Knossos\Store\StableId::boundary($projectId, 'aaa-everything', 'inferred');
        $repository->saveBoundary($wide, $projectId, 'aaa-everything', [], 'inferred', $scan);
        foreach ($pdo->query("SELECT id FROM nodes WHERE project_id = '{$projectId}'")->fetchAll(PDO::FETCH_COLUMN) as $node) {
            $repository->saveBoundaryMembership($wide, $projectId, (string) $node, $scan);
        }
        // And one that holds Target's file alone, sorting after it: the label a person reads it by.
        $own = \Knossos\Store\StableId::boundary($projectId, 'zzz-target', 'explicit');
        $repository->saveBoundary($own, $projectId, 'zzz-target', [], 'explicit', $scan);
        foreach ($pdo->query("SELECT n.id FROM nodes n JOIN files f ON f.id = n.file_id WHERE n.project_id = '{$projectId}' AND f.relative_path = 'src/App/Target.php'")->fetchAll(PDO::FETCH_COLUMN) as $node) {
            $repository->saveBoundaryMembership($own, $projectId, (string) $node, $scan);
        }
        $groups = (new NotesService($pdo))->notes($root)['groups'];
        $all = array_merge(...array_values($groups));
        $by = array_column($all, null, 'component');
        assertSame('src/App/Target.php', $by['App\\Target']['path']);
        // The file's own boundary, as the fan-in map and the Branch tab label it: the most specific, not the first by name.
        $label = \Knossos\Query\BoundaryLabels::load($pdo, $projectId)->forFiles($projectId, ['src/App/Target.php'])['src/App/Target.php'] ?? null;
        assertSame($label, $by['App\\Target']['boundary']);
        assertSame([null, null], [$by['App\\Gone']['path'], $by['App\\Gone']['boundary']]);
    }

    /**
     * A file no worker scans that a note names is a component with no file
     * row: its note lives at its own path, in the boundary that holds it.
     */
    public function testANoteOnAFileNoScanReadsLivesAtThatFile(): void
    {
        [$pdo, $root, $projectId] = $this->notesGraph();
        $scan = (string) $pdo->query("SELECT active_scan_id FROM projects WHERE id = '{$projectId}'")->fetchColumn();
        $repository = new \Knossos\Store\SqliteGraphRepository($pdo);
        $node = \Knossos\Store\StableId::symbol($projectId, 'file', 'file', 'hooks/scripts/run.sh');
        $repository->saveNode($node, $projectId, 'file', 'file', 'hooks/scripts/run.sh', 'run.sh', null, null, 1, 1, 'derived', 'certain', [], 'knossos.files:named', $scan);
        $scripts = \Knossos\Store\StableId::boundary($projectId, 'scripts', 'explicit');
        $repository->saveBoundary($scripts, $projectId, 'scripts', [], 'explicit', $scan);
        $repository->saveBoundaryMembership($scripts, $projectId, $node, $scan);
        $pdo->prepare("INSERT INTO annotations(project_id, canonical_name, kind, value, author, created_at, updated_at) VALUES (?, 'hooks/scripts/run.sh', 'note', 'Runs the CLI.', 'user', '2026-10-16T12:00:00Z', '2026-10-16T12:00:00Z')")->execute([$projectId]);

        $all = array_merge(...array_values((new NotesService($pdo))->notes($root)['groups']));
        $by = array_column($all, null, 'component');

        assertSame(['hooks/scripts/run.sh', 'scripts'], [$by['hooks/scripts/run.sh']['path'], $by['hooks/scripts/run.sh']['boundary']]);
    }

    /**
     * Confirming a stale note rests it on its files as they are now and
     * changes nothing else: not its text, not when it was written, not its
     * author, and not the person's flag on it.
     */
    public function testConfirmingAStaleNoteClearsItAndKeepsItsTextDateAndFlag(): void
    {
        [$pdo, $root, $projectId] = $this->notesGraph();
        $service = new NotesService($pdo);
        $service->check($root, 'App\\Chain1', 'still true?', execute: true);
        $before = self::row($service->notes($root), 'App\\Chain1');
        assertSame(true, $before['stale']);

        $preview = $service->confirm($root, 'App\\Chain1');
        assertSame([false, true], [$preview['executed'], self::row($service->notes($root), 'App\\Chain1')['stale']]);

        $answer = $service->confirm($root, 'App\\Chain1', execute: true);
        $after = self::row($service->notes($root), 'App\\Chain1');
        assertSame(['ok', 'confirm', true], [$answer['status'], $answer['action'], $answer['executed']]);
        assertSame(false, $after['stale']);
        assertSame([$before['value'], $before['updated_at'], $before['author'], true], [$after['value'], $after['updated_at'], $after['author'], $after['checked']]);
        assertSame(['still true?'], self::stored($pdo, $projectId, 'App\\Chain1', 'user', 'check'));
    }

    /** Only a model note rests on files, so only a model note is confirmed; a name with none is refused. */
    public function testConfirmingRefusesANameWithNoModelNote(): void
    {
        [$pdo, $root] = $this->notesGraph();
        assertSame('refused', (new NotesService($pdo))->confirm($root, 'App\\User', execute: true)['status']);
    }

    /**
     * A confirmed note keeps every file it rested on: its own, and each one it
     * cited, up to the cap on cited files alone.
     */
    public function testConfirmingKeepsTheOwnFileAndEveryCitedOne(): void
    {
        [$pdo, $root, $projectId] = $this->notesGraph();
        $cited = ['src/App/User.php', 'src/App/Maybe.php', 'src/App/Chain2.php', 'src/App/Chain3.php', 'src/App/Port.php'];
        ArchitectureQueryService::forDatabase($pdo)->upsertAnnotation($projectId, 'App\\Chain1', 'note', 'Wraps the user, as five others do.', true, 'model', $cited, true);
        $pdo->prepare("UPDATE files SET content_hash = 'edited again' WHERE project_id = ? AND relative_path = 'src/App/Chain1.php'")->execute([$projectId]);
        $service = new NotesService($pdo);
        assertSame(true, self::row($service->notes($root), 'App\\Chain1')['stale']);

        $answer = $service->confirm($root, 'App\\Chain1', execute: true);

        assertSame('ok', $answer['status']);
        assertSame(['src/App/Chain1.php', ...$cited], self::basisPaths($pdo, $projectId, 'App\\Chain1'));
        assertSame(false, self::row($service->notes($root), 'App\\Chain1')['stale']);
    }

    /** A cited file that is gone from the scan and the disk leaves the basis; the note is confirmed on what is left. */
    public function testConfirmingANoteWhoseCitedFileWasRemovedDropsThatFile(): void
    {
        [$pdo, $root, $projectId] = $this->notesGraph();
        ArchitectureQueryService::forDatabase($pdo)->upsertAnnotation($projectId, 'App\\Chain1', 'note', 'Wraps the user, unlike Maybe.', true, 'model', ['src/App/User.php', 'src/App/Maybe.php'], true);
        $pdo->prepare("DELETE FROM files WHERE project_id = ? AND relative_path = 'src/App/Maybe.php'")->execute([$projectId]);
        $service = new NotesService($pdo);
        assertSame('written before src/App/Maybe.php was removed', self::row($service->notes($root), 'App\\Chain1')['stale_reason']);

        $answer = $service->confirm($root, 'App\\Chain1', execute: true);

        assertSame('ok', $answer['status']);
        assertSame(['src/App/Chain1.php', 'src/App/User.php'], self::basisPaths($pdo, $projectId, 'App\\Chain1'));
        assertSame(false, self::row($service->notes($root), 'App\\Chain1')['stale']);
    }

    /** A note that rests on no file has nothing to rest on now either, so confirming it is refused and leaves it stale. */
    public function testConfirmingANoteOnNoFileIsRefused(): void
    {
        [$pdo, $root] = $this->notesGraph();
        $service = new NotesService($pdo);

        $answer = $service->confirm($root, 'App\\Gone', execute: true);

        assertSame('refused', $answer['status']);
        assertSame(true, self::row($service->notes($root), 'App\\Gone')['stale']);
    }

    /** A name the graph reads as another component's is refused, as every other note write refuses it, and nothing is confirmed. */
    public function testConfirmingRefusesANameThatLandsOnAnotherComponent(): void
    {
        [$pdo, $root, $projectId] = $this->notesGraph();
        $pdo->prepare("INSERT INTO annotations(project_id, canonical_name, kind, value, author, created_at, updated_at, basis_json) VALUES (?, 'Target', 'note', 'Short.', 'model', '2026-10-16T12:00:00Z', '2026-10-16T12:00:00Z', ?)")->execute([$projectId, '[{"path":"src/App/User.php","hash":"old"}]']);

        $answer = (new NotesService($pdo))->confirm($root, 'Target', execute: true);

        assertSame('refused', $answer['status']);
        assertSame('[{"path":"src/App/User.php","hash":"old"}]', $pdo->query("SELECT basis_json FROM annotations WHERE canonical_name = 'Target'")->fetchColumn());
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
     * A note left on a short name the graph resolves to another component
     * (`Target`, which App\\Target displays as) moves as that stored row and
     * no other: onto App\\Target itself, or elsewhere without touching the
     * person's own note on App\\Target.
     */
    public function testAMoveTakesTheStoredRowByItsExactNameAndNoOther(): void
    {
        [$pdo, $root, $projectId] = $this->notesGraph();
        $service = new NotesService($pdo);
        $short = $pdo->prepare("INSERT INTO annotations(project_id, canonical_name, kind, value, author, created_at, updated_at) VALUES (?, 'Target', 'note', ?, 'user', '2026-10-16T09:00:00Z', '2026-10-16T09:00:00Z')");
        $short->execute([$projectId, 'written before the class existed']);

        $onto = $service->move($root, 'Target', 'App\\Target', execute: true);

        assertSame('ok', $onto['status']);
        assertSame([], self::stored($pdo, $projectId, 'Target', 'user'));
        assertSame(['written before the class existed'], self::stored($pdo, $projectId, 'App\\Target', 'user'));

        $short->execute([$projectId, 'a second one']);
        $elsewhere = $service->move($root, 'Target', 'App\\Adapter', execute: true);

        assertSame('ok', $elsewhere['status']);
        assertSame([], self::stored($pdo, $projectId, 'Target', 'user'));
        assertSame(['a second one'], self::stored($pdo, $projectId, 'App\\Adapter', 'user'));
        assertSame(['written before the class existed'], self::stored($pdo, $projectId, 'App\\Target', 'user'));
    }

    /**
     * A moved model note keeps what it was written against, so it reads stale
     * where it lands until the model looks; and moving it is the person's
     * doing, not the model's look, so a check on the target stays.
     */
    public function testAMovedModelNoteKeepsItsBasisAndTheTargetsCheck(): void
    {
        [$pdo, $root, $projectId] = $this->notesGraph();
        $service = new NotesService($pdo);
        $service->check($root, 'App\\Adapter', 'look at this', execute: true);
        $basis = $pdo->prepare("SELECT basis_json FROM annotations WHERE project_id = ? AND canonical_name = ? AND kind = 'note' AND author = 'model'");
        $basis->execute([$projectId, 'App\\Gone']);
        $before = $basis->fetchColumn();

        $service->move($root, 'App\\Gone', 'App\\Adapter', author: 'model', execute: true);

        $basis->execute([$projectId, 'App\\Adapter']);
        assertSame($before, $basis->fetchColumn());
        assertSame(['look at this'], self::stored($pdo, $projectId, 'App\\Adapter', 'user', 'check'));
    }

    /**
     * Adopting or flagging a note never writes on another component than the
     * one it names: a short name the graph resolves elsewhere is refused.
     */
    public function testAdoptAndCheckRefuseANameTheGraphResolvesToAnotherComponent(): void
    {
        [$pdo, $root, $projectId] = $this->notesGraph();
        $pdo->prepare("INSERT INTO annotations(project_id, canonical_name, kind, value, author, created_at, updated_at) VALUES (?, 'Target', 'note', 'short', 'model', '2026-10-16T09:00:00Z', '2026-10-16T09:00:00Z')")->execute([$projectId]);
        $service = new NotesService($pdo);
        $before = self::all($pdo, $projectId);

        $adopt = $service->adopt($root, 'Target', execute: true);
        $check = $service->check($root, 'Target', execute: true);

        assertSame(['refused', 'refused'], [$adopt['status'], $check['status']]);
        assertSame('Target names App\\Target in the graph: move the note there first.', $adopt['reason']);
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

    /** @param array<string, mixed> $answer @return array<string, mixed> the row of `$component`'s model note */
    private static function row(array $answer, string $component): array
    {
        foreach (array_merge(...array_values($answer['groups'])) as $row) {
            if ($row['component'] === $component && $row['author'] === 'model') {
                return $row;
            }
        }
        throw new \RuntimeException("no model note on {$component}");
    }

    /** @return list<string> the values stored on a component by an author */
    private static function stored(PDO $pdo, string $projectId, string $component, string $author, string $kind = 'note'): array
    {
        $statement = $pdo->prepare('SELECT value FROM annotations WHERE project_id = ? AND canonical_name = ? AND kind = ? AND author = ?');
        $statement->execute([$projectId, $component, $kind, $author]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> the paths a component's model note rests on, as stored */
    private static function basisPaths(PDO $pdo, string $projectId, string $component): array
    {
        $statement = $pdo->prepare("SELECT basis_json FROM annotations WHERE project_id = ? AND canonical_name = ? AND kind = 'note' AND author = 'model'");
        $statement->execute([$projectId, $component]);

        return array_map(static fn(array $entry): string => (string) $entry['path'], json_decode((string) $statement->fetchColumn(), true));
    }

    /** @return list<array<string, mixed>> every annotation row, in a fixed order */
    private static function all(PDO $pdo, string $projectId): array
    {
        $statement = $pdo->prepare('SELECT canonical_name, kind, author, value FROM annotations WHERE project_id = ? ORDER BY canonical_name, kind, author');
        $statement->execute([$projectId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
