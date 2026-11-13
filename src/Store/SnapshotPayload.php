<?php

declare(strict_types=1);

namespace Knossos\Store;

use RuntimeException;

/**
 * How an archived snapshot's facts are stored.
 *
 * A snapshot is a full copy of a project's graph, kept so a later scan can be
 * diffed against it. Stored as plain JSON, a repository's graph can make that
 * payload tens of megabytes. The payload is highly repetitive — the same
 * column names and stable-id prefixes on every row — so it compresses to about
 * a fifth of that.
 *
 * A captured graph is stored as the gzip bytes themselves behind a sentinel
 * prefix, bound as a BLOB. Earlier versions wrote them as base64 text, a third
 * larger, which on a graph of 105,000 edges was 9 MB more written to the log
 * on every scan that changed anything. SQLite keeps a BLOB as it was bound
 * whatever the column is declared as, and `.dump`, a file backup and the
 * online backup all carry it byte for byte.
 *
 * All three forms stay readable: the binary one, the base64 one earlier
 * versions wrote and the small stub of a capture that stopped still uses,
 * and anything with neither prefix, which is the plain JSON of the first
 * versions. A version older than this one cannot read the binary form, so a
 * downgrade loses the snapshots taken since, not the graph.
 */
final readonly class SnapshotPayload
{
    /**
     * Marks a compressed payload. Chosen so it can never be confused with JSON,
     * which always starts with `{` here.
     */
    public const PREFIX = 'gzip64:';

    /**
     * Marks a payload stored as raw gzip bytes. The same length as
     * {@see self::PREFIX}, so a reader decides between the two from one
     * fixed-size read.
     */
    public const BINARY_PREFIX = 'gzipb1:';

    /**
     * Measured on a 29.6 MB payload from this repository: level 1 compresses in
     * 176 ms, level 3 in 196 ms, level 6 in 470 ms, for stored sizes of 9.1,
     * 8.8, and 7.9 MB. Level 6 buys under a megabyte per snapshot for more than
     * twice the time, and archiving runs on every scan that changed anything.
     */
    private const LEVEL = 3;

    /** Whether a stored payload is the binary form, which has to be bound as a BLOB to be kept byte for byte. */
    public static function isBinary(string $stored): bool
    {
        return str_starts_with($stored, self::BINARY_PREFIX);
    }

    /** Compress an encoded payload for storage. */
    public static function encode(string $json): string
    {
        return self::PREFIX . base64_encode(gzencode($json, self::LEVEL));
    }

    /**
     * A writer that compresses a payload as it is produced.
     *
     * Use this when the payload is assembled from a query rather than already in
     * hand, which is every archive of a real project.
     *
     * @param int $maxBytes uncompressed ceiling past which the payload is abandoned
     */
    public static function writer(int $maxBytes): SnapshotPayloadWriter
    {
        return new SnapshotPayloadWriter(self::BINARY_PREFIX, self::LEVEL, $maxBytes);
    }

    /**
     * Return the payload's JSON, decompressing it when it was stored that way.
     *
     * @throws RuntimeException when a compressed payload cannot be read back,
     *         which means the row is damaged rather than merely old
     */
    public static function decode(string $stored): string
    {
        if (str_starts_with($stored, self::BINARY_PREFIX)) {
            $compressed = substr($stored, strlen(self::BINARY_PREFIX));
        } elseif (str_starts_with($stored, self::PREFIX)) {
            $compressed = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        } else {
            return $stored;
        }
        $json = $compressed === false ? false : @gzdecode($compressed);
        if (!is_string($json)) {
            throw new RuntimeException('A stored snapshot payload could not be decompressed; the row is damaged.');
        }

        return $json;
    }
}
