<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\SnapshotPayload;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * A captured graph is stored as its gzip bytes, not as base64 text of them:
 * the text is a third larger, and it is written on every scan that changed
 * anything. The forms earlier versions wrote have to keep reading.
 */
#[Group('storage')]
final class SnapshotPayloadBinaryTest extends KnossosTestCase
{
    private const JSON = '{"schema":1,"facts":{"nodes":[{"id":"symbol_x","kind":"class"}]}}';

    /** The writer hands back the prefix and the gzip bytes as they are, and they decode to what was written. */
    public function testTheWriterStoresTheGzipBytesThemselves(): void
    {
        $writer = SnapshotPayload::writer(1_000_000);
        $writer->write(self::JSON);
        $stored = $writer->finish();

        self::assertSame('gzipb1:', substr($stored, 0, 7));
        self::assertSame(self::JSON, gzdecode(substr($stored, 7)));
        self::assertTrue(SnapshotPayload::isBinary($stored));
        self::assertSame(self::JSON, SnapshotPayload::decode($stored));
    }

    /** Both prefixes are one length, so a reader tells them apart from a single fixed-size read. */
    public function testTheTwoPrefixesAreTheSameLength(): void
    {
        self::assertSame(strlen(SnapshotPayload::PREFIX), strlen(SnapshotPayload::BINARY_PREFIX));
        self::assertFalse(SnapshotPayload::isBinary(SnapshotPayload::encode(self::JSON)));
        self::assertFalse(SnapshotPayload::isBinary(self::JSON));
    }

    /** The base64 text an earlier version stored still decodes. */
    public function testTheBase64FormOfAnEarlierVersionStillDecodes(): void
    {
        self::assertSame(self::JSON, SnapshotPayload::decode('gzip64:' . base64_encode((string) gzencode(self::JSON))));
    }

    /** Damaged gzip bytes are refused, never handed back as a payload. */
    public function testDamagedBinaryBytesAreRejected(): void
    {
        $writer = SnapshotPayload::writer(1_000_000);
        $writer->write(self::JSON);
        $stored = $writer->finish();

        $this->expectException(RuntimeException::class);
        SnapshotPayload::decode(substr($stored, 0, 20));
    }

    /**
     * The archive binds the payload as a BLOB. Bound as text, gzip bytes are
     * stored as text that is not valid UTF-8, and nothing promises they come
     * back as they went in.
     */
    public function testAnArchivedSnapshotIsStoredAsABlobAndReadsBackByteForByte(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);

        $row = $pdo->query('SELECT typeof(payload_json) AS type, payload_json, byte_size, complete FROM scan_snapshots')->fetch();

        self::assertSame('blob', $row['type']);
        self::assertSame(1, (int) $row['complete']);
        $json = SnapshotPayload::decode((string) $row['payload_json']);
        self::assertSame((int) $row['byte_size'], strlen($json));
        self::assertSame(1, json_decode($json, true, 512, JSON_THROW_ON_ERROR)['schema']);
    }
}
