<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\SnapshotGraphReader;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SnapshotPayload;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * The archived graph read through a connection that offers SQLite's
 * incremental blob reader. The runtime may not have one (PHP 8.3), so the
 * connection here is a PDO that declares `openBlob()` itself and hands out a
 * stream over the row's stored payload: the reader feature-detects the
 * method, so this drives the same path `Pdo\Sqlite` does on 8.4 and later.
 */
final class SnapshotGraphReaderBlobStreamTest extends KnossosTestCase
{
    protected function tearDown(): void
    {
        if (in_array(PieceStream::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(PieceStream::SCHEME);
        }
        PieceStream::$payloads = [];
        PieceStream::$served = [];
        PieceStream::$closed = [];
        PieceStream::$requested = [];
        PieceStream::$returned = [];
        parent::tearDown();
    }

    /**
     * The store fixture on a connection with a blob reader, with enough edges
     * that the archived payload spans many 4,096-character slices, archived.
     *
     * @return array{0: BlobReadingPdo, 1: string, 2: string, 3: string} the connection, the project, the archived scan and its stored payload
     */
    private function archivedStore(): array
    {
        $pdo = new BlobReadingPdo('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
        [, $repository, $ids] = $this->storeFixture(pdo: $pdo);
        $edges = [];
        for ($i = 0; $i < 300; $i++) {
            $edges[] = [
                'id' => StableId::edge($ids['project'], $i % 2 === 0 ? 'calls' : 'imports', $ids['checkout'], $ids['invoice'], 'spread:' . $i),
                'kind' => $i % 2 === 0 ? 'calls' : 'imports', 'source_id' => $ids['checkout'], 'target_id' => $ids['invoice'],
                'file_id' => $ids['file'], 'start_line' => $i + 1, 'end_line' => $i + 1, 'origin' => 'ast',
                'confidence' => 'certain', 'attributes' => ['note' => hash('sha512', 'edge ' . $i)], 'owner_key' => 'php:file:src/Checkout.php',
            ];
        }
        $repository->bulkTransaction(static function ($repository) use ($edges, $ids): void {
            $repository->saveEdges($edges, $ids['project'], $ids['scan']);
        });
        $repository->completeScan($ids['project'], $ids['scan']);
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $stored = (string) $pdo->query('SELECT payload_json FROM scan_snapshots WHERE scan_id = ' . $pdo->quote($ids['scan']))->fetchColumn();
        assertSame(true, str_starts_with($stored, 'gzip64:'), 'The archive writes a compressed payload.');
        assertGreaterThan(3 * 4096, strlen($stored), 'The payload spans several slices.');

        return [$pdo, $ids['project'], $ids['scan'], $stored];
    }

    /** @return array<string, list<array<string, mixed>>> every table of the payload, decoded whole */
    private static function whole(string $stored): array
    {
        return json_decode(SnapshotPayload::decode($stored), true, 512, JSON_THROW_ON_ERROR)['facts'];
    }

    /** Stores `$payload` as the scan's payload, the way an earlier version or another writer left it. */
    private static function restore(PDO $pdo, string $scan, string $payload): void
    {
        $statement = $pdo->prepare('UPDATE scan_snapshots SET payload_json = :payload WHERE scan_id = :scan');
        $statement->execute(['payload' => $payload, 'scan' => $scan]);
    }

    #[Group('query')]
    public function testTheBlobStreamGivesTheRowsTheFetchedStringAndTheActiveGraphGive(): void
    {
        [$pdo, $project, $scan] = $this->archivedStore();
        $active = (new SnapshotGraphReader($pdo, blobReads: false))->active($project, $scan);
        $fetched = (new SnapshotGraphReader($pdo, blobReads: false))->archivedById($scan);
        assertSame(0, $pdo->opens);

        $streamed = (new SnapshotGraphReader($pdo))->archivedById($scan);

        assertSame(1, $pdo->opens, 'The payload was read through the blob stream.');
        assertSame([['scan_snapshots', 'payload_json']], $pdo->opened);
        assertSame($fetched, $streamed);
        assertSame(array_keys(SnapshotGraphReader::COLUMNS), array_keys($streamed));
        assertSame(301, count($streamed['edges']));
        foreach (SnapshotGraphReader::COLUMNS as $table => $columns) {
            $keyed = static fn(array $rows): array => array_map(static fn(array $row): array => array_merge(array_fill_keys($columns, null), $row), $rows);
            assertSame($keyed($active[$table]), $keyed($streamed[$table]), $table);
            foreach ($streamed[$table] as $row) {
                assertSame([], array_diff(array_keys($row), $columns), $table . ' rows are cut to their columns.');
            }
        }
    }

    /**
     * A stream need not return whole slices: pieces of 3, 4,093, 1 and 2,050
     * bytes split base64 groups, and the partial group is carried to the next.
     */
    #[Group('query')]
    public function testPiecesOfAnyLengthGiveTheSameRows(): void
    {
        [$pdo, , $scan, $stored] = $this->archivedStore();
        $expected = (new SnapshotGraphReader($pdo, blobReads: false))->archivedById($scan);
        $pdo->opener = static fn(): mixed => PieceStream::open('odd', $stored, [3, 4093, 1, 2050]);

        $streamed = (new SnapshotGraphReader($pdo))->archivedById($scan);

        assertSame($expected, $streamed);
        assertSame(strlen($stored), PieceStream::$served['odd'], 'The whole payload came off the stream, not the fetched string.');
        // Unbuffered: the reader's own requests (the prefix, then slices) reach the stream, so it sees the odd pieces.
        assertSame(7, PieceStream::$requested['odd'][0]);
        assertSame([4096], array_values(array_unique(array_slice(PieceStream::$requested['odd'], 1))), 'Each slice was asked of the stream itself.');
        assertSame([7, 3, 4093, 1, 2050, 3], array_slice(PieceStream::$returned['odd'], 0, 6), 'Pieces that split base64 groups.');
        assertSame(true, PieceStream::$closed['odd']);
    }

    #[Group('query')]
    public function testAPlainJsonPayloadOnTheStreamFallsBackToTheFetchedString(): void
    {
        [$pdo, , $scan, $stored] = $this->archivedStore();
        $json = SnapshotPayload::decode($stored);
        self::restore($pdo, $scan, $json);
        $pdo->opener = static fn(): mixed => PieceStream::open('plain', $json, [4096]);

        $facts = (new SnapshotGraphReader($pdo))->archivedById($scan);

        assertSame(1, $pdo->opens);
        assertSame(7, PieceStream::$served['plain'], 'Only the prefix was read before falling back.');
        assertSame(true, PieceStream::$closed['plain']);
        $whole = json_decode($json, true, 512, JSON_THROW_ON_ERROR)['facts'];
        assertSame(array_map(static fn(array $row): array => ['node_id' => $row['node_id'], 'role' => $row['role']], $whole['classifications']), $facts['classifications']);
        assertSame(array_column($whole['nodes'], 'id'), array_column($facts['nodes'], 'id'));
        assertSame(array_column($whole['edges'], 'kind'), array_column($facts['edges'], 'kind'));
        assertSame(301, count($facts['edges']));
    }

    /**
     * The archive's own column refuses NULL, so the row is one an older
     * table (nullable, as the blob reader meets it) holds.
     */
    #[Group('query')]
    public function testANullPayloadIsNeverOpenedAndIsNotRetained(): void
    {
        $pdo = new BlobReadingPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE scan_snapshots (scan_id TEXT PRIMARY KEY, payload_json TEXT NULL)');
        $pdo->exec("INSERT INTO scan_snapshots VALUES ('scan_null', NULL)");

        try {
            (new SnapshotGraphReader($pdo))->archivedById('scan_null');
            self::fail('A NULL payload has no facts to read.');
        } catch (InvalidArgumentException $error) {
            assertSame('Snapshot facts are not retained: scan_null', $error->getMessage());
        }
        assertSame(0, $pdo->opens);
    }

    #[Group('query')]
    public function testAScanWithNoSnapshotRowIsNotRetained(): void
    {
        [$pdo] = $this->archivedStore();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Snapshot facts are not retained: scan_absent');
        (new SnapshotGraphReader($pdo, blobReads: false))->archivedById('scan_absent');
    }

    #[Group('query')]
    public function testAScanWithNoSnapshotRowIsNeverOpened(): void
    {
        [$pdo] = $this->archivedStore();

        try {
            (new SnapshotGraphReader($pdo))->archivedById('scan_absent');
            self::fail('An absent snapshot has no facts to read.');
        } catch (InvalidArgumentException $error) {
            assertSame('Snapshot facts are not retained: scan_absent', $error->getMessage());
        }
        assertSame(0, $pdo->opens);
    }

    /** An open that warns before it fails (as openBlob() does on a row gone since) falls back without the warning reaching the caller. */
    #[Group('query')]
    public function testAnOpenThatWarnsAndFailsFallsBackSilently(): void
    {
        [$pdo, , $scan] = $this->archivedStore();
        $expected = (new SnapshotGraphReader($pdo, blobReads: false))->archivedById($scan);
        $missing = sys_get_temp_dir() . '/knossos-no-such-dir-' . bin2hex(random_bytes(4)) . '/payload';
        // fopen() on a missing path raises a real E_WARNING and returns false.
        $pdo->opener = static fn(): mixed => fopen($missing, 'r');
        $raised = [];
        set_error_handler(static function (int $level, string $message) use (&$raised): bool {
            $raised[] = $message;
            return true;
        });
        try {
            $facts = (new SnapshotGraphReader($pdo))->archivedById($scan);
        } finally {
            restore_error_handler();
        }

        assertSame(1, $pdo->opens);
        assertSame([], $raised);
        assertSame($expected, $facts);
    }

    #[Group('query')]
    public function testAnOpenThatQuietlyReturnsNoStreamFallsBack(): void
    {
        [$pdo, , $scan] = $this->archivedStore();
        $expected = (new SnapshotGraphReader($pdo, blobReads: false))->archivedById($scan);

        foreach (['false' => false, 'null' => null, 'a string' => 'not a stream'] as $how => $result) {
            $pdo->opener = static fn(): mixed => $result;
            assertSame($expected, (new SnapshotGraphReader($pdo))->archivedById($scan), $how);
        }
        assertSame(3, $pdo->opens);
    }

    #[Group('query')]
    public function testWithoutBlobReadsTheReaderIsNeverOpened(): void
    {
        [$pdo, , $scan] = $this->archivedStore();
        $reader = new SnapshotGraphReader($pdo, blobReads: false);

        $reader->archivedById($scan);
        $reader->archivedTablesById($scan, ['nodes', 'edges']);

        assertSame(0, $pdo->opens);
    }

    /** A compressed payload laid out another way (pretty-printed, keys reordered) is not read as it streams, but decoded whole. */
    #[Group('query')]
    public function testACompressedPayloadLaidOutOtherwiseIsDecodedWhole(): void
    {
        [$pdo, , $scan, $stored] = $this->archivedStore();
        $expected = (new SnapshotGraphReader($pdo, blobReads: false))->archivedById($scan);
        $payload = json_decode(SnapshotPayload::decode($stored), true, 512, JSON_THROW_ON_ERROR);
        $facts = $payload['facts'];
        unset($payload['facts']);
        $reordered = SnapshotPayload::encode(json_encode(['facts' => $facts] + $payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        self::restore($pdo, $scan, $reordered);

        $read = (new SnapshotGraphReader($pdo))->archivedById($scan);

        assertSame(1, $pdo->opens);
        assertSame($expected, $read);
    }

    #[Group('query')]
    public function testTheStreamIsClosedAfterTheRead(): void
    {
        [$pdo, , $scan] = $this->archivedStore();

        (new SnapshotGraphReader($pdo))->archivedById($scan);

        assertSame(1, count($pdo->handed));
        assertSame(false, is_resource($pdo->handed[0]));
        assertSame('Unknown', get_resource_type($pdo->handed[0]), 'A closed stream.');
    }

    /** The decode that follows the stream fails (a compressed payload with no facts), and the stream is closed all the same. */
    #[Group('query')]
    public function testTheStreamIsClosedWhenTheWholeDecodeThenThrows(): void
    {
        [$pdo, , $scan] = $this->archivedStore();
        self::restore($pdo, $scan, SnapshotPayload::encode('{"schema":1}'));

        try {
            (new SnapshotGraphReader($pdo))->archivedById($scan);
            self::fail('A payload without facts is refused.');
        } catch (InvalidArgumentException $error) {
            assertSame('Snapshot archive payload is invalid: ' . $scan, $error->getMessage());
        }
        assertSame(1, count($pdo->handed));
        assertSame(false, is_resource($pdo->handed[0]));
    }

    /** A stream that fails while it is read is closed before the failure reaches the caller. */
    #[Group('query')]
    public function testTheStreamIsClosedWhenReadingItThrows(): void
    {
        [$pdo, , $scan, $stored] = $this->archivedStore();
        $pdo->opener = static fn(): mixed => PieceStream::open('failing', $stored, [4096], failAfter: 2);

        try {
            (new SnapshotGraphReader($pdo))->archivedById($scan);
            self::fail('The stream failed mid-read.');
        } catch (RuntimeException $error) {
            assertSame('The payload stream failed.', $error->getMessage());
        }
        assertSame(true, PieceStream::$closed['failing']);
    }

    #[Group('query')]
    public function testTablesThroughTheBlobStreamKeepEveryColumnOfOnlyThoseTables(): void
    {
        [$pdo, , $scan, $stored] = $this->archivedStore();

        $tables = (new SnapshotGraphReader($pdo))->archivedTablesById($scan, ['nodes']);

        assertSame(1, $pdo->opens);
        assertSame(['nodes'], array_keys($tables));
        assertSame(self::whole($stored)['nodes'], $tables['nodes']);
        assertGreaterThan(count(SnapshotGraphReader::COLUMNS['nodes']), count(array_keys($tables['nodes'][0])), 'Every stored column, not only the compared ones.');
    }
}

/**
 * A connection that declares `openBlob()` as `Pdo\Sqlite` does, counting each
 * call. By default it hands out a stream over the row's stored payload; a test
 * can swap in another result.
 */
final class BlobReadingPdo extends PDO
{
    public int $opens = 0;

    /** @var list<array{0: string, 1: string}> */
    public array $opened = [];

    /** @var list<mixed> every stream handed out */
    public array $handed = [];

    /** @var (\Closure(string, string, int): mixed)|null */
    public ?\Closure $opener = null;

    /** @return resource|false */
    public function openBlob(string $table, string $column, int $rowid, ?string $dbname = 'main', int $flags = 0): mixed
    {
        $this->opens++;
        $this->opened[] = [$table, $column];
        if ($this->opener !== null) {
            $blob = ($this->opener)($table, $column, $rowid);
        } else {
            $statement = $this->prepare(sprintf('SELECT %s FROM %s WHERE rowid = :rowid', $column, $table));
            $statement->execute(['rowid' => $rowid]);
            $blob = fopen('php://memory', 'r+');
            fwrite($blob, (string) $statement->fetchColumn());
            rewind($blob);
        }
        if (is_resource($blob)) {
            $this->handed[] = $blob;
        }

        return $blob;
    }
}

/** A read-only stream that returns its payload in pieces of the lengths it is given, in turn. */
final class PieceStream
{
    public const SCHEME = 'knossos-piece';

    /** @var array<string, array{payload: string, lengths: list<int>, failAfter: ?int}> */
    public static array $payloads = [];

    /** @var array<string, int> bytes handed out per stream */
    public static array $served = [];

    /** @var array<string, bool> */
    public static array $closed = [];

    /** @var array<string, list<int>> the byte counts each read asked for */
    public static array $requested = [];

    /** @var array<string, list<int>> the byte counts each read returned */
    public static array $returned = [];

    /** @var resource|null */
    public $context;

    private string $key = '';

    private int $position = 0;

    private int $reads = 0;

    /**
     * @param list<int> $lengths
     * @return resource
     */
    public static function open(string $key, string $payload, array $lengths, ?int $failAfter = null)
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
        self::$payloads[$key] = ['payload' => $payload, 'lengths' => $lengths, 'failAfter' => $failAfter];
        self::$served[$key] = 0;
        self::$closed[$key] = false;
        self::$requested[$key] = [];
        self::$returned[$key] = [];
        $stream = fopen(self::SCHEME . '://' . $key, 'r');
        // A chunk size of one bypasses the read buffer, so each fread() is one stream_read() and returns that piece's length.
        stream_set_chunk_size($stream, 1);

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->key = substr($path, strlen(self::SCHEME . '://'));

        return array_key_exists($this->key, self::$payloads);
    }

    public function stream_read(int $count): string
    {
        $stream = self::$payloads[$this->key];
        $this->reads++;
        self::$requested[$this->key][] = $count;
        if ($stream['failAfter'] !== null && $this->reads > $stream['failAfter']) {
            throw new RuntimeException('The payload stream failed.');
        }
        // The first read is the prefix check, answered whole; after it, the given lengths in turn.
        $length = $this->position === 0 ? $count : min($count, $stream['lengths'][($this->reads - 2) % count($stream['lengths'])]);
        $piece = substr($stream['payload'], $this->position, $length);
        $this->position += strlen($piece);
        self::$served[$this->key] += strlen($piece);
        self::$returned[$this->key][] = strlen($piece);

        return $piece;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$payloads[$this->key]['payload']);
    }

    public function stream_close(): void
    {
        self::$closed[$this->key] = true;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['size' => strlen(self::$payloads[$this->key]['payload'])];
    }

}
