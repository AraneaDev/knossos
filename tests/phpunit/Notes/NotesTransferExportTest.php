<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Notes;

use JsonException;
use Knossos\Notes\NotesFile;
use Knossos\Notes\NotesTransferService;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

/** Exporting a project's annotations to a notes file. */
final class NotesTransferExportTest extends KnossosTestCase
{
    private string $tempDir;
    private PDO $pdo;
    private string $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/knossos-stale-notes-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
        $this->pdo = SqliteConnection::open(':memory:');
        (new MigrationRunner($this->pdo, self::repositoryRoot() . '/migrations'))->migrate();
        $this->projectId = StableId::project('root:/work/app');
        (new SqliteGraphRepository($this->pdo))->saveProject($this->projectId, 'app', '/work/app');
    }

    protected function tearDown(): void
    {
        @chmod($this->tempDir . '/locked', 0o755);
        $this->removeTempTree($this->tempDir);
        parent::tearDown();
    }

    private function note(string $name, string $kind, string $author, string $basis = '[]'): void
    {
        $this->pdo->prepare(
            'INSERT INTO annotations(project_id, canonical_name, kind, value, author, basis_json, created_at, updated_at) ' .
            "VALUES (:p, :n, :k, 'v', :a, :b, '2026-10-01T00:00:00Z', '2026-10-02T00:00:00Z')",
        )->execute(['p' => $this->projectId, 'n' => $name, 'k' => $kind, 'a' => $author, 'b' => $basis]);
    }

    private function seed(): void
    {
        $this->note('App\\B', 'note', 'model', '[{"path":"src/B.php"}]');
        $this->note('App\\A', 'note', 'user');
        $this->note('App\\A', 'confirmed_dead', 'model');
    }

    /** Every row lands in the file, in key order, and the counts say who wrote them. */
    #[Group('notes')]
    public function testExportWritesEveryRowAndCounts(): void
    {
        $this->seed();
        $file = $this->tempDir . '/' . NotesFile::DEFAULT_NAME;

        $result = (new NotesTransferService($this->pdo))->export($this->projectId, '/work/app', $file);

        $decoded = NotesFile::decode((string) file_get_contents($file));
        self::assertCount(3, $decoded['rows']);
        self::assertSame(['App\\A', 'App\\A', 'App\\B'], array_column($decoded['rows'], 'canonical_name'));
        self::assertSame('/work/app', $decoded['origin']['root']);
        self::assertSame($this->projectId, $decoded['origin']['project_id']);
        self::assertSame(3, $result['total']);
        self::assertSame(['user' => 1, 'model' => 2], $result['by_author']);
        self::assertFalse($result['replaced']);
        self::assertSame($file, $result['file']);
    }

    /** An existing file is overwritten and the result says so. */
    #[Group('notes')]
    public function testExportOverwritesAndSaysSo(): void
    {
        $this->seed();
        $file = $this->tempDir . '/notes.json';
        file_put_contents($file, '{"format":"knossos-notes","version":1}');

        $result = (new NotesTransferService($this->pdo))->export($this->projectId, '/work/app', $file);

        self::assertTrue($result['replaced']);
        self::assertCount(3, NotesFile::decode((string) file_get_contents($file))['rows']);
    }

    /** No notes still writes a valid, empty file. */
    #[Group('notes')]
    public function testExportOfProjectWithNoNotesWritesEmptyFile(): void
    {
        $file = $this->tempDir . '/notes.json';

        $result = (new NotesTransferService($this->pdo))->export($this->projectId, '/work/app', $file);

        self::assertSame(0, $result['total']);
        self::assertSame([], NotesFile::decode((string) file_get_contents($file))['rows']);
    }

    /** A write that cannot happen throws and leaves neither the file nor a temp file. */
    #[Group('notes')]
    public function testFailedWriteLeavesNoFile(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignores directory permissions.');
        }
        $this->seed();
        $dir = $this->tempDir . '/locked';
        mkdir($dir, 0o755);
        chmod($dir, 0o555);

        try {
            (new NotesTransferService($this->pdo))->export($this->projectId, '/work/app', $dir . '/notes.json');
            self::fail('expected the write to throw');
        } catch (Throwable $e) {
            self::assertStringNotContainsString('expected the write', $e->getMessage());
        }

        self::assertSame([], glob($dir . '/*'));
    }

    /** Corrupt stored basis surfaces as a JsonException and leaves nothing behind. */
    #[Group('notes')]
    public function testCorruptBasisThrowsAndLeavesNothing(): void
    {
        $this->note('App\\A', 'note', 'model', '{not json');
        $file = $this->tempDir . '/notes.json';

        try {
            (new NotesTransferService($this->pdo))->export($this->projectId, '/work/app', $file);
            self::fail('expected a JsonException');
        } catch (JsonException) {
            // expected
        }

        self::assertSame([], glob($this->tempDir . '/*'));
    }

    /** A file that is not a notes file is never replaced, and is left as it was. */
    #[Group('notes')]
    public function testExportRefusesToReplaceANonNotesFile(): void
    {
        $this->seed();
        $file = $this->tempDir . '/composer.json';
        file_put_contents($file, '{"name":"app/app","require":{}}');

        try {
            (new NotesTransferService($this->pdo))->export($this->projectId, '/work/app', $file);
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            self::assertStringStartsWith('NOTES_FILE_NOT_NOTES: ' . $file . ' exists and is not a knossos notes file', $e->getMessage());
        }

        self::assertSame('{"name":"app/app","require":{}}', file_get_contents($file));
        self::assertSame([$file], glob($this->tempDir . '/*'));
    }

    /** A previous notes file is replaced, and the result says so. */
    #[Group('notes')]
    public function testExportStillReplacesAPreviousNotesFile(): void
    {
        $this->seed();
        $file = $this->tempDir . '/' . NotesFile::DEFAULT_NAME;
        $service = new NotesTransferService($this->pdo);
        $service->export($this->projectId, '/work/app', $file);
        $this->note('App\\C', 'note', 'user');

        $again = $service->export($this->projectId, '/work/app', $file);

        self::assertTrue($again['replaced']);
        self::assertCount(4, NotesFile::decode((string) file_get_contents($file))['rows']);
    }
}
