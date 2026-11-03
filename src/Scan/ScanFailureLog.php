<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Throwable;

/**
 * The last scans that failed and why, one JSON object per line in
 * `scan-failures.jsonl` beside the database.
 *
 * A scan that dies leaves the pane a status and, until now, no reason: the
 * process that knew it was gone. This is where the reason goes, for
 * `knossos doctor` and the next person to ask. It keeps the last
 * {@see self::KEEP} lines, trimmed under an exclusive lock so two scanners
 * ending at once lose no line. Recording never throws: a log that cannot be
 * written must not turn a failed scan into a different failure.
 */
final readonly class ScanFailureLog
{
    /** How many lines the file keeps. */
    public const KEEP = 200;

    /** The longest message kept, in bytes: a worker's stderr can be large. */
    private const MESSAGE_BYTES = 2000;

    public function __construct(private string $path) {}

    /** The log that sits beside the database at `$databasePath`, or null for an in-memory one. */
    public static function forDatabase(string $databasePath): ?self
    {
        if ($databasePath === '' || $databasePath === ':memory:') {
            return null;
        }

        return new self(dirname($databasePath) . '/scan-failures.jsonl');
    }

    /**
     * Append one failure, stamped now.
     *
     * @param array{root: string, started_by: string, class: string, message: string, elapsed_ms: ?int, signal: ?int} $failure
     */
    public function record(array $failure): void
    {
        try {
            $failure['message'] = mb_strcut($failure['message'], 0, self::MESSAGE_BYTES);
            $line = json_encode(['at' => gmdate('c')] + $failure, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($line === false) {
                return;
            }
            $handle = @fopen($this->path, 'c+');
            if ($handle === false) {
                return;
            }
            try {
                if (!flock($handle, LOCK_EX)) {
                    return;
                }
                $lines = self::lines((string) stream_get_contents($handle));
                $lines[] = $line;
                $kept = array_slice($lines, -self::KEEP);
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, implode("\n", $kept) . "\n");
                fflush($handle);
                flock($handle, LOCK_UN);
            } finally {
                fclose($handle);
            }
        } catch (Throwable) {
            // See the class comment.
        }
    }

    /**
     * The most recent failures, newest first, skipping a line that is not a JSON object.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit): array
    {
        $contents = is_file($this->path) ? @file_get_contents($this->path) : false;
        if ($contents === false) {
            return [];
        }
        $entries = [];
        foreach (array_reverse(self::lines($contents)) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
            if (count($entries) >= $limit) {
                break;
            }
        }

        return $entries;
    }

    /**
     * The non-empty lines of `$contents`.
     *
     * @return list<string>
     */
    private static function lines(string $contents): array
    {
        return array_values(array_filter(explode("\n", $contents), static fn(string $line): bool => $line !== ''));
    }
}
