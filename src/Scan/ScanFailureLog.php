<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Cancellation\ScanCancelledException;
use Knossos\Reconciliation\Milliseconds;
use Knossos\Scanner\Worker\WorkerException;
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
            // The lock is its own file: the log is replaced by rename, and a lock on a
            // file that is replaced would be held on one nobody reads any more.
            $lock = @fopen($this->path . '.lock', 'c');
            if ($lock === false) {
                return;
            }
            try {
                if (!flock($lock, LOCK_EX)) {
                    return;
                }
                $existing = is_file($this->path) ? (string) @file_get_contents($this->path) : '';
                $lines = self::lines($existing);
                $lines[] = $line;
                $this->replaceWith(implode("\n", array_slice($lines, -self::KEEP)) . "\n");
            } finally {
                fclose($lock);
            }
        } catch (Throwable) {
            // See the class comment.
        }
    }

    /**
     * Keep the PHP fatal that ends the process, if one does, as a failure of the scan of `$root`.
     *
     * A fatal (memory exhaustion above all) skips every catch block, so nothing
     * of the scan can say what happened; a shutdown function still runs. The
     * returned closure disarms it once the scan has ended some other way.
     *
     * @return \Closure(): void
     */
    public function watchForFatal(string $root, string $startedBy): \Closure
    {
        $armed = true;
        $startedAt = hrtime(true);
        register_shutdown_function(function () use (&$armed, $root, $startedBy, $startedAt): void {
            $error = $armed ? error_get_last() : null;
            if ($error === null || !in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) {
                return;
            }
            // The fatal is often the memory limit itself; a line needs a little room to be written.
            @ini_set('memory_limit', '-1');
            $this->record([
                'root' => $root,
                'started_by' => $startedBy,
                'class' => ScanFailureClass::OTHER,
                'message' => $error['message'],
                'elapsed_ms' => (int) round(Milliseconds::since($startedAt)),
                'signal' => null,
            ]);
        });

        return static function () use (&$armed): void {
            $armed = false;
        };
    }

    /**
     * Put `$contents` in the log whole, or leave the log as it was.
     *
     * Written beside it and renamed over it, so a full disk costs the new line
     * and never the lines already kept.
     */
    private function replaceWith(string $contents): void
    {
        $temporary = $this->path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporary, $contents) !== strlen($contents) || !@rename($temporary, $this->path)) {
            @unlink($temporary);
        }
    }

    /**
     * Append the failure `$failure` ended a scan of `$root` with, `$startedAt` being an `hrtime(true)` reading from when it began.
     *
     * A cancelled scan is the caller's decision, not a failure to explain, and is not kept.
     */
    public function recordThrowable(string $root, string $startedBy, Throwable $failure, int $startedAt): void
    {
        if ($failure instanceof ScanCancelledException) {
            return;
        }
        $this->record([
            'root' => $root,
            'started_by' => $startedBy,
            'class' => ScanFailureClass::of($failure),
            'message' => $failure->getMessage(),
            'elapsed_ms' => (int) round(Milliseconds::since($startedAt)),
            'signal' => $failure instanceof WorkerException ? $failure->terminatingSignal : null,
        ]);
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
