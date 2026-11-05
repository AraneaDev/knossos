<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\PaneCommands;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\BusyWriteStatement;
use PDO;

use function PHPUnit\Framework\assertFileExists;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringStartsWith;

use PHPUnit\Framework\Attributes\Group;

/** The pane's notes-export and notes-import commands: answer shapes, refusals and a busy database. */
final class PaneNotesTransferTest extends KnossosTestCase
{
    private const FIXTURE = 'turn-brief';

    /**
     * @param array<string, string> $options
     * @return array<string, mixed>
     */
    private function ask(PDO $pdo, string $command, string $root, array $options = []): array
    {
        return (new PaneCommands($pdo))->answer($command, $root, static fn(string $name): ?string => $options[$name] ?? null, static fn(string $name): bool => isset($options[$name]));
    }

    /** Export answers the file, the total and whether it replaced one; the options are file and execute. */
    #[Group('query')]
    public function testExportAnswersItsShape(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            assertSame(['file'], PaneCommands::options('notes-export'));
            assertSame(['file', 'execute', 'expect'], PaneCommands::options('notes-import'));
            $project = (string) $pdo->query('SELECT id FROM projects')->fetchColumn();
            ArchitectureQueryService::forDatabase($pdo)->services()->annotationQueries->upsertAnnotation($project, 'App\\Greeter', 'note', 'keep me', execute: true);

            $first = $this->ask($pdo, 'notes-export', $root);
            $second = $this->ask($pdo, 'notes-export', $root);

            assertSame(['status' => 'ok', 'executed' => true, 'file' => $root . '/knossos-notes.json', 'total' => 1, 'replaced' => false], $first);
            assertSame(true, $second['replaced']);
            assertFileExists($root . '/knossos-notes.json');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A dry import writes nothing; an executed one adds the note; both answer the narrowed origin. */
    #[Group('query')]
    public function testImportPreviewsThenExecutes(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $project = (string) $pdo->query('SELECT id FROM projects')->fetchColumn();
            ArchitectureQueryService::forDatabase($pdo)->services()->annotationQueries->upsertAnnotation($project, 'App\\Greeter', 'note', 'keep me', execute: true);
            $this->ask($pdo, 'notes-export', $root);
            $pdo->exec('DELETE FROM annotations');

            $dry = $this->ask($pdo, 'notes-import', $root);
            assertSame(['status', 'executed', 'file', 'sha256', 'origin', 'total', 'new', 'replaced', 'kept', 'unmatched', 'stale'], array_keys($dry));
            assertSame(hash_file('sha256', $root . '/knossos-notes.json'), $dry['sha256']);
            assertSame(['exported_at', 'root'], array_keys($dry['origin']));
            assertSame(['ok', false, 1, 1], [$dry['status'], $dry['executed'], $dry['total'], $dry['new']]);
            assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM annotations')->fetchColumn());

            // The file changed after the preview: execute with the preview's hash is refused, and writes nothing.
            $previewed = $dry['sha256'];
            file_put_contents($root . '/knossos-notes.json', (string) file_get_contents($root . '/knossos-notes.json') . ' ');
            $changed = $this->ask($pdo, 'notes-import', $root, ['execute' => '1', 'expect' => $previewed]);
            assertSame('refused', $changed['status']);
            assertStringStartsWith('NOTES_FILE_CHANGED: ', $changed['reason']);
            assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM annotations')->fetchColumn());

            $again = $this->ask($pdo, 'notes-import', $root);
            $done = $this->ask($pdo, 'notes-import', $root, ['execute' => '1', 'expect' => $again['sha256']]);
            assertSame(true, $done['executed']);
            assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM annotations')->fetchColumn());
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A missing file is refused with its name, and an unscanned path says so. */
    #[Group('query')]
    public function testRefusalsAndUnscanned(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $refused = $this->ask($pdo, 'notes-import', $root);
            assertSame('refused', $refused['status']);
            assertStringContainsString('knossos-notes.json', $refused['reason']);
            assertSame('unscanned', $this->ask($pdo, 'notes-import', sys_get_temp_dir() . '/knossos-never-scanned')['status']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** An import that meets another writer's lock answers with the busy code. */
    #[Group('query')]
    public function testImportMeetingALockAnswersBusy(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $project = (string) $pdo->query('SELECT id FROM projects')->fetchColumn();
            ArchitectureQueryService::forDatabase($pdo)->services()->annotationQueries->upsertAnnotation($project, 'App\\Greeter', 'note', 'keep me', execute: true);
            $this->ask($pdo, 'notes-export', $root);
            $pdo->exec('DELETE FROM annotations');
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [BusyWriteStatement::class, []]);
            BusyWriteStatement::$failOn = 'INSERT INTO annotations';
            $answer = $this->ask($pdo, 'notes-import', $root, ['execute' => '1']);
            BusyWriteStatement::$failOn = null;

            assertSame(['error', 'KNOSSOS_DATABASE_BUSY'], [$answer['status'], $answer['code']]);
        } finally {
            BusyWriteStatement::$failOn = null;
            $this->removeTempTree($root);
        }
    }
}
