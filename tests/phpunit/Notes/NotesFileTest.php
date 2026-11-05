<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Notes;

use Knossos\Notes\NotesFile;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The notes file codec: what it writes, what it reads back, and what it refuses. */
final class NotesFileTest extends KnossosTestCase
{
    private const ORIGIN = ['exported_at' => '2026-11-05T10:00:00Z', 'root' => '/work/app', 'project_id' => 'p1', 'knossos_version' => '0.43.0'];

    /** @return array<string, mixed> a file body that decodes cleanly */
    private function validFile(): array
    {
        return [
            'format' => 'knossos-notes',
            'version' => 1,
            'origin' => self::ORIGIN,
            'notes' => [[
                'canonical_name' => 'App\\Foo', 'kind' => 'note', 'author' => 'user', 'value' => 'v',
                'basis' => [], 'created_at' => '2026-01-01T00:00:00Z', 'updated_at' => '2026-01-02T00:00:00Z',
            ]],
        ];
    }

    /** Both rows come back identical, basis and timestamps included. */
    #[Group('notes')]
    public function testRoundTripKeepsEveryColumn(): void
    {
        $rows = [
            ['canonical_name' => 'App\\Foo', 'kind' => 'note', 'author' => 'user', 'value' => 'keep/slashes', 'basis_json' => '[]', 'created_at' => '2026-01-01T00:00:00Z', 'updated_at' => '2026-01-02T00:00:00Z'],
            ['canonical_name' => 'App\\Bar', 'kind' => 'confirmed_dead', 'author' => 'model', 'value' => 'unused', 'basis_json' => '[{"path":"src/Bar.php","line":3}]', 'created_at' => '2026-02-01T00:00:00Z', 'updated_at' => '2026-02-03T00:00:00Z'],
        ];

        $decoded = NotesFile::decode(NotesFile::encode(self::ORIGIN, $rows));

        assertSame(self::ORIGIN, $decoded['origin']);
        assertSame($rows, $decoded['rows']);
    }

    /** Two notes on one key are refused, naming the key. */
    #[Group('notes')]
    public function testRefusesDuplicateKeys(): void
    {
        $file = $this->validFile();
        $file['notes'][] = $file['notes'][0];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('NOTES_FILE_INVALID: duplicate note App\\Foo/note/user');

        NotesFile::decode(json_encode($file, JSON_THROW_ON_ERROR));
    }

    /** A project with no annotations still writes a valid file. */
    #[Group('notes')]
    public function testEmptyNotesRoundTrip(): void
    {
        $decoded = NotesFile::decode(NotesFile::encode(self::ORIGIN, []));

        assertSame([], $decoded['rows']);
    }

    /** Text that is not JSON is refused. */
    #[Group('notes')]
    public function testRefusesNonJson(): void
    {
        $this->expectExceptionMessageMatches('/^NOTES_FILE_INVALID:/');
        NotesFile::decode('not json');
    }

    /** Another tool's JSON file is refused. */
    #[Group('notes')]
    public function testRefusesWrongFormat(): void
    {
        $file = $this->validFile();
        $file['format'] = 'something-else';

        $this->expectExceptionMessageMatches('/^NOTES_FILE_INVALID:/');
        NotesFile::decode(json_encode($file, JSON_THROW_ON_ERROR));
    }

    /** A kind outside the vocabulary is refused. */
    #[Group('notes')]
    public function testRefusesUnknownKind(): void
    {
        $file = $this->validFile();
        $file['notes'][0]['kind'] = 'bogus';

        $this->expectExceptionMessageMatches('/^NOTES_FILE_INVALID:/');
        NotesFile::decode(json_encode($file, JSON_THROW_ON_ERROR));
    }

    /** An author outside the vocabulary is refused. */
    #[Group('notes')]
    public function testRefusesUnknownAuthor(): void
    {
        $file = $this->validFile();
        $file['notes'][0]['author'] = 'robot';

        $this->expectExceptionMessageMatches('/^NOTES_FILE_INVALID:/');
        NotesFile::decode(json_encode($file, JSON_THROW_ON_ERROR));
    }

    /** A row missing a column is refused. */
    #[Group('notes')]
    public function testRefusesMissingUpdatedAt(): void
    {
        $file = $this->validFile();
        unset($file['notes'][0]['updated_at']);

        $this->expectExceptionMessageMatches('/^NOTES_FILE_INVALID:/');
        NotesFile::decode(json_encode($file, JSON_THROW_ON_ERROR));
    }

    /** A non-string origin field is refused. */
    #[Group('notes')]
    public function testRefusesIllTypedOrigin(): void
    {
        $file = $this->validFile();
        $file['origin']['root'] = 5;

        $this->expectExceptionMessageMatches('/^NOTES_FILE_INVALID:/');
        NotesFile::decode(json_encode($file, JSON_THROW_ON_ERROR));
    }

    /** A file from a newer Knossos is refused with its own code. */
    #[Group('notes')]
    public function testRefusesNewerVersion(): void
    {
        $file = $this->validFile();
        $file['version'] = 2;

        $this->expectExceptionMessageMatches('/^NOTES_FILE_VERSION:/');
        NotesFile::decode(json_encode($file, JSON_THROW_ON_ERROR));
    }
}
