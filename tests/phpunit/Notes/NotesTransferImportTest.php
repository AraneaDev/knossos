<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Notes;

use InvalidArgumentException;
use Knossos\Notes\NotesFile;
use Knossos\Notes\NotesTransferService;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanOptions;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/** Importing a notes file: the preview counts, the conflict rule and the write. */
final class NotesTransferImportTest extends KnossosTestCase
{
    private string $tempDir;
    private string $root;
    private PDO $pdo;
    private string $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/knossos-stale-notes-' . bin2hex(random_bytes(6));
        $this->root = $this->tempDir . '/app';
        mkdir($this->root . '/src', 0o755, true);
        file_put_contents($this->root . '/composer.json', '{"name":"app/app","autoload":{"psr-4":{"App\\\\":"src/"}}}');
        file_put_contents($this->root . '/src/A.php', "<?php\nnamespace App;\nfinal class A {}\n");
        $this->pdo = $this->freshTestDatabase();
        $this->projectId = (new ProjectScanService($this->pdo, self::repositoryRoot(), [$this->root]))
            ->scan($this->root, new ScanOptions(mode: 'full'))->projectId;
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->tempDir);
        parent::tearDown();
    }

    /** @return array{canonical_name: string, kind: string, author: 'user'|'model', value: string, basis_json: string, created_at: string, updated_at: string} */
    private static function row(string $name, string $kind, string $author, string $updated, string $value = 'v', string $basis = '[]'): array
    {
        return [
            'canonical_name' => $name,
            'kind' => $kind,
            'author' => $author === 'model' ? 'model' : 'user',
            'value' => $value,
            'basis_json' => $basis,
            'created_at' => '2026-09-01T00:00:00Z',
            'updated_at' => $updated,
        ];
    }

    private function existing(string $name, string $kind, string $author, string $updated): void
    {
        $this->pdo->prepare(
            'INSERT INTO annotations(project_id, canonical_name, kind, value, author, basis_json, created_at, updated_at) ' .
            "VALUES (:p, :n, :k, 'mine', :a, '[]', '2026-09-15T00:00:00Z', :u)",
        )->execute(['p' => $this->projectId, 'n' => $name, 'k' => $kind, 'a' => $author, 'u' => $updated]);
    }

    /** @param list<array<string, string>> $rows */
    private function file(array $rows): string
    {
        $path = $this->tempDir . '/' . NotesFile::DEFAULT_NAME;
        file_put_contents($path, NotesFile::encode([
            'exported_at' => '2026-11-01T00:00:00Z',
            'root' => '/elsewhere',
            'project_id' => 'other',
            'knossos_version' => '0.0.0',
        ], $rows));

        return $path;
    }

    /** @return list<array<string, mixed>> */
    private function stored(): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM annotations WHERE project_id = ? ORDER BY canonical_name, kind, author');
        $statement->execute([$this->projectId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function seedMixed(): string
    {
        $this->existing('App\\A', 'note', 'user', '2026-10-05T00:00:00Z');
        $this->existing('App\\A', 'intentional', 'user', '2026-10-05T00:00:00Z');

        return $this->file([
            self::row('App\\A', 'note', 'user', '2026-10-09T00:00:00Z', 'newer'),
            self::row('App\\A', 'intentional', 'user', '2026-10-01T00:00:00Z', 'older'),
            self::row('App\\A', 'confirmed_dead', 'user', '2026-10-01T00:00:00Z'),
            self::row('App\\Gone', 'note', 'user', '2026-10-01T00:00:00Z'),
            self::row('App\\A', 'note', 'model', '2026-10-01T00:00:00Z', 'v', '[{"path":"src/A.php","hash":"wrong"}]'),
        ]);
    }

    /** A preview counts every bucket and writes nothing. */
    #[Group('notes')]
    public function testDryRunCountsAndWritesNothing(): void
    {
        $file = $this->seedMixed();
        $before = $this->stored();

        $result = (new NotesTransferService($this->pdo))->import($this->projectId, $file, false);

        self::assertFalse($result['executed']);
        self::assertSame($file, $result['file']);
        self::assertSame('/elsewhere', $result['origin']['root']);
        self::assertSame(5, $result['total']);
        self::assertSame(3, $result['new']);
        self::assertSame(1, $result['replaced']);
        self::assertSame(1, $result['kept']);
        self::assertSame(1, $result['unmatched']);
        self::assertSame(1, $result['stale']);
        self::assertSame(2, $result['conflicts']['total']);
        self::assertSame($before, $this->stored());
    }

    /** An equal updated_at leaves the project's note and names it in the conflicts. */
    #[Group('notes')]
    public function testTieKeepsTheProjectsNote(): void
    {
        $this->existing('App\\A', 'note', 'user', '2026-10-05T00:00:00Z');
        $file = $this->file([self::row('App\\A', 'note', 'user', '2026-10-05T00:00:00Z', 'theirs')]);

        $result = (new NotesTransferService($this->pdo))->import($this->projectId, $file, true);

        self::assertSame(1, $result['kept']);
        self::assertSame(0, $result['replaced']);
        self::assertSame('project', $result['conflicts']['items'][0]['kept']);
        self::assertSame('mine', $this->stored()[0]['value']);
    }

    /** Execute writes new and replaced rows with the file's own timestamps. */
    #[Group('notes')]
    public function testExecuteWritesAndKeepsFileTimestamps(): void
    {
        $file = $this->seedMixed();

        $result = (new NotesTransferService($this->pdo))->import($this->projectId, $file, true);

        self::assertTrue($result['executed']);
        $rows = [];
        foreach ($this->stored() as $row) {
            $rows[$row['canonical_name'] . '|' . $row['kind'] . '|' . $row['author']] = $row;
        }
        self::assertCount(5, $rows);
        self::assertSame('newer', $rows['App\\A|note|user']['value']);
        self::assertSame('2026-09-01T00:00:00Z', $rows['App\\A|note|user']['created_at']);
        self::assertSame('2026-10-09T00:00:00Z', $rows['App\\A|note|user']['updated_at']);
        self::assertSame('mine', $rows['App\\A|intentional|user']['value']);
        self::assertArrayHasKey('App\\Gone|note|user', $rows);
        self::assertSame(['file', 'project'], array_column($result['conflicts']['items'], 'kept'));
    }

    /** Running the same file again changes nothing. */
    #[Group('notes')]
    public function testSecondImportOfSameFileKeepsEverything(): void
    {
        $file = $this->seedMixed();
        $service = new NotesTransferService($this->pdo);
        $service->import($this->projectId, $file, true);
        $after = $this->stored();

        $second = $service->import($this->projectId, $file, true);

        self::assertSame(0, $second['new']);
        self::assertSame(0, $second['replaced']);
        self::assertSame(5, $second['kept']);
        self::assertSame($after, $this->stored());
    }

    /** A file with no notes reports zeros. */
    #[Group('notes')]
    public function testImportOfEmptyFileReportsNothing(): void
    {
        $result = (new NotesTransferService($this->pdo))->import($this->projectId, $this->file([]), true);

        foreach (['total', 'new', 'replaced', 'kept', 'unmatched', 'stale'] as $key) {
            self::assertSame(0, $result[$key], $key);
        }
        self::assertSame(['total' => 0, 'items' => []], $result['conflicts']);
    }

    /** A path that is not there is refused by name. */
    #[Group('notes')]
    public function testMissingFileIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^NOTES_FILE_MISSING:/');

        (new NotesTransferService($this->pdo))->import($this->projectId, $this->tempDir . '/nope.json', false);
    }

    /** Sixty conflicts are all counted but only fifty listed. */
    #[Group('notes')]
    public function testConflictListIsCappedAt50(): void
    {
        $rows = [];
        for ($i = 0; $i < 60; ++$i) {
            $name = sprintf('App\\N%02d', $i);
            $this->existing($name, 'note', 'user', '2026-10-05T00:00:00Z');
            $rows[] = self::row($name, 'note', 'user', '2026-10-09T00:00:00Z');
        }

        $result = (new NotesTransferService($this->pdo))->import($this->projectId, $this->file($rows), false);

        self::assertSame(60, $result['conflicts']['total']);
        self::assertCount(50, $result['conflicts']['items']);
    }
}
