<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use JsonException;
use Knossos\Query\SnapshotGraphReader;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * A compressed payload the bounded read does not recognise is handed to the
 * whole decode, which either gives the rows or refuses the payload: a
 * damaged or oddly laid out archive never comes back as rows cut short.
 */
final class SnapshotGraphReaderMalformedPayloadTest extends KnossosTestCase
{
    /** A store holding only `scan_snapshots`, read as one fetched string on every runtime. */
    private static function readerOver(string $stored): SnapshotGraphReader
    {
        $pdo = SqliteConnection::open(':memory:');
        $pdo->exec('CREATE TABLE scan_snapshots (scan_id TEXT PRIMARY KEY, payload_json TEXT NULL)');
        $insert = $pdo->prepare('INSERT INTO scan_snapshots (scan_id, payload_json) VALUES (:scan, :payload)');
        $insert->execute(['scan' => 'scan_m', 'payload' => $stored]);

        return new SnapshotGraphReader($pdo, blobReads: false);
    }

    private static function gzip64(string $json): string
    {
        return 'gzip64:' . base64_encode((string) gzencode($json));
    }

    /**
     * What the bounded read alone makes of a stored payload: its rows, or
     * null where it gives up and the payload is decoded whole.
     *
     * @return array<string, list<array<string, mixed>>>|null
     */
    private static function streamedOnly(SnapshotGraphReader $reader, string $stored): ?array
    {
        $slices = (new \ReflectionMethod($reader, 'slices'))->invoke(null, $stored);

        return (new \ReflectionMethod($reader, 'streamed'))->invoke($reader, $slices, SnapshotGraphReader::COLUMNS);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function decodedWhole(SnapshotGraphReader $reader, string $stored): array
    {
        return (new \ReflectionMethod($reader, 'decodedWhole'))->invoke($reader, $stored, 'scan_m', SnapshotGraphReader::COLUMNS);
    }

    /** The exception reading `$stored` raises, or null when it gives rows. */
    private static function failureOf(string $stored): ?\Throwable
    {
        try {
            self::readerOver($stored)->archivedById('scan_m');
        } catch (\Throwable $caught) {
            return $caught;
        }

        return null;
    }

    #[Group('query')]
    public function testTextAfterTheClosingBracesIsRefusedRatherThanCutShort(): void
    {
        $stored = self::gzip64('{"schema":1,"facts":{"files":[{"id":"f1","relative_path":"a.php"}]}}X');

        assertSame(null, self::streamedOnly(self::readerOver($stored), $stored));
        $this->expectException(JsonException::class);
        self::readerOver($stored)->archivedById('scan_m');
    }

    #[Group('query')]
    public function testTrailingWhitespaceAfterTheDocumentIsAccepted(): void
    {
        $stored = self::gzip64("{\"schema\":1,\"facts\":{\"files\":[{\"id\":\"f1\",\"relative_path\":\"a.php\"}]}}\n  \n");
        $reader = self::readerOver($stored);

        assertNotSame(null, self::streamedOnly($reader, $stored), 'Whitespace after the document is not a reason to give up.');
        assertSame([['id' => 'f1', 'relative_path' => 'a.php']], $reader->archivedById('scan_m')['files']);
    }

    #[Group('query')]
    public function testARowThatIsNotJsonIsRefused(): void
    {
        $stored = self::gzip64('{"schema":1,"facts":{"files":[{abc}]}}');

        assertSame(null, self::streamedOnly(self::readerOver($stored), $stored));
        $this->expectException(JsonException::class);
        self::readerOver($stored)->archivedById('scan_m');
    }

    #[Group('query')]
    public function testBase64MissingItsLastCharacterIsRefused(): void
    {
        $encoded = rtrim(base64_encode((string) gzencode('{"schema":1,"facts":{"files":[{"id":"f1","relative_path":"a.php"}]}}')), '=');
        $stored = 'gzip64:' . substr($encoded, 0, -1);
        assertNotSame(0, (strlen($stored) - strlen('gzip64:')) % 4, 'The stored text leaves a partial group.');

        assertSame(null, self::streamedOnly(self::readerOver($stored), $stored));
        $failure = self::failureOf($stored);
        assertSame(true, $failure instanceof RuntimeException || $failure instanceof InvalidArgumentException, 'Refused, not read as partial rows: ' . get_debug_type($failure));
    }

    #[Group('query')]
    public function testCharactersOutsideBase64AreRefusedRatherThanSkipped(): void
    {
        $encoded = base64_encode((string) gzencode('{"schema":1,"facts":{"files":[{"id":"f1","relative_path":"a.php"}]}}'));
        // A whole group of them: a lenient decode would drop it and read the rest as if nothing were wrong.
        $stored = 'gzip64:' . substr($encoded, 0, 8) . '****' . substr($encoded, 8);

        assertSame(null, self::streamedOnly(self::readerOver($stored), $stored));
        $failure = self::failureOf($stored);
        assertSame(true, $failure instanceof RuntimeException || $failure instanceof InvalidArgumentException, 'Refused, not read as rows: ' . get_debug_type($failure));
    }

    #[Group('query')]
    public function testBase64OfBytesThatAreNotGzipIsRefused(): void
    {
        $stored = 'gzip64:' . base64_encode(str_repeat('not gzip data at all', 4));

        assertSame(null, self::streamedOnly(self::readerOver($stored), $stored));
        $failure = self::failureOf($stored);
        assertSame(true, $failure instanceof RuntimeException, 'Refused as damaged: ' . get_debug_type($failure));
    }

    #[Group('query')]
    public function testAnUnterminatedDocumentIsRefused(): void
    {
        $stored = self::gzip64('{"schema":1,"facts":{"files":[{"id":"a"}');

        assertSame(null, self::streamedOnly(self::readerOver($stored), $stored));
        $this->expectException(JsonException::class);
        self::readerOver($stored)->archivedById('scan_m');
    }

    #[Group('query')]
    public function testATableNotReadIsSkippedAndTheOthersKept(): void
    {
        $stored = self::gzip64('{"schema":1,"facts":{"owners":[{"key":"k","hash":"h"}],"files":[{"id":"f1","relative_path":"a.php","hash":"x"}],"boundaries":[{"id":"b1","name":"Web"}]}}');
        $reader = self::readerOver($stored);

        $streamed = self::streamedOnly($reader, $stored);
        assertNotSame(null, $streamed, 'An extra table is still the archive layout.');
        $facts = $reader->archivedById('scan_m');
        assertSame(array_keys(SnapshotGraphReader::COLUMNS), array_keys($facts));
        assertArrayNotHasKey('owners', $facts);
        assertSame([['id' => 'f1', 'relative_path' => 'a.php']], $facts['files']);
        assertSame([['id' => 'b1']], $facts['boundaries']);
        assertSame([], $facts['nodes']);
    }

    #[Group('query')]
    public function testEscapedQuotesAndBracesInAStringStayOneRow(): void
    {
        $path = 'a"}{b';
        $json = '{"schema":1,"facts":{"files":[' . json_encode(['id' => 'f1', 'relative_path' => $path], JSON_THROW_ON_ERROR)
            . ',' . json_encode(['id' => 'f2', 'relative_path' => 'c\\d'], JSON_THROW_ON_ERROR) . ']}}';
        $stored = self::gzip64($json);
        $reader = self::readerOver($stored);

        assertNotSame(null, self::streamedOnly($reader, $stored));
        assertSame([['id' => 'f1', 'relative_path' => $path], ['id' => 'f2', 'relative_path' => 'c\\d']], $reader->archivedById('scan_m')['files']);
    }

    #[Group('query')]
    public function testOnlyAnErasedKindOfEdgeKeepsItsAttributesOnBothPaths(): void
    {
        $edges = [
            ['id' => 'e1', 'kind' => 'calls', 'source_id' => 'a', 'target_id' => 'b', 'attributes_json' => '{"note":"x"}', 'origin' => 'ast'],
            ['id' => 'e2', 'kind' => 'imports', 'source_id' => 'a', 'target_id' => 'c', 'attributes_json' => '{"type_only":true}', 'origin' => 'ast'],
            ['id' => 'e3', 'kind' => 're_exports', 'source_id' => 'c', 'target_id' => 'b', 'attributes_json' => '{"type_only":false}'],
        ];
        $stored = self::gzip64('{"schema":1,"facts":{"edges":' . json_encode($edges, JSON_THROW_ON_ERROR) . '}}');
        $reader = self::readerOver($stored);
        $expected = [
            ['kind' => 'calls', 'source_id' => 'a', 'target_id' => 'b', 'attributes_json' => null],
            ['kind' => 'imports', 'source_id' => 'a', 'target_id' => 'c', 'attributes_json' => '{"type_only":true}'],
            ['kind' => 're_exports', 'source_id' => 'c', 'target_id' => 'b', 'attributes_json' => '{"type_only":false}'],
        ];

        assertNotSame(null, self::streamedOnly($reader, $stored), 'Read as it inflates.');
        assertSame($expected, self::sortedKeys($reader->archivedById('scan_m')['edges']));
        assertSame($expected, self::sortedKeys(self::decodedWhole($reader, $stored)['edges']));
    }

    #[Group('query')]
    public function testAnEmptyFactsObjectGivesEveryTableEmpty(): void
    {
        $stored = self::gzip64('{"schema":1,"facts":{}}');
        $reader = self::readerOver($stored);

        assertNotSame(null, self::streamedOnly($reader, $stored));
        assertSame(array_fill_keys(array_keys(SnapshotGraphReader::COLUMNS), []), $reader->archivedById('scan_m'));
    }

    /**
     * Rows with their keys in the column order, so the two paths compare by
     * value: each keeps the order the payload wrote.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function sortedKeys(array $rows): array
    {
        $order = array_flip(SnapshotGraphReader::COLUMNS['edges']);

        return array_map(static function (array $row) use ($order): array {
            uksort($row, static fn(string $a, string $b): int => $order[$a] <=> $order[$b]);

            return $row;
        }, $rows);
    }
}
