<?php

declare(strict_types=1);

namespace Knossos\Runtime;

use InvalidArgumentException;
use Knossos\Cancellation\ScanCancelledException;
use Knossos\Discovery\DiscoveryException;
use Knossos\Scan\ScanSnapshotChangedException;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Store\ScanBusyException;
use Knossos\Store\SqliteBusy;
use PDOException;
use Throwable;

/**
 * The stable code every adapter gives a failure, and the message it may show.
 *
 * One table for the MCP server and the CLI: kept apart, the two drifted, so
 * the same failure carried a different code depending on which way it was
 * reached. The adapters only render what this decides.
 */
final class ErrorCode
{
    /** A failure no row of the table maps: its message is not written for a caller. */
    public const UNEXPECTED = 'KNOSSOS_RUNTIME_ERROR';

    /** A database failure other than a contended write lock. */
    public const STORAGE = 'KNOSSOS_STORAGE_ERROR';

    /** Said in place of an unexpected failure's own message. */
    public const GENERIC_MESSAGE = 'An unexpected error occurred while running the tool.';

    /** Said in place of the driver's text for a database failure, which names files. */
    public const STORAGE_MESSAGE = 'The graph database could not be read or written. Run `knossos doctor` to check it.';

    private function __construct() {}

    /** The stable code for this failure; {@see self::UNEXPECTED} when nothing maps it. */
    public static function for(Throwable $error): string
    {
        return match (true) {
            // The caller chose to stop: no fault, whatever was running when it did.
            $error instanceof ScanCancelledException => 'KNOSSOS_SCAN_CANCELLED',
            // Before the busy check: it wraps the busy driver error it gave up on.
            $error instanceof ScanBusyException => 'KNOSSOS_SCAN_BUSY',
            SqliteBusy::anywhere($error) => SqliteBusy::CODE,
            // Its own code rather than the runtime default: a tree that moved
            // under a scan is an expected, retryable condition an automated
            // caller should be able to recognise without parsing prose. The
            // message survives too: the file it names is what the caller acts on.
            $error instanceof ScanSnapshotChangedException => 'KNOSSOS_SCAN_SNAPSHOT_CHANGED',
            $error instanceof WorkerException => $error->diagnosticCode,
            $error instanceof DiscoveryException => 'KNOSSOS_UNSAFE_PATH',
            $error instanceof PDOException => self::STORAGE,
            $error instanceof InvalidArgumentException => 'KNOSSOS_INVALID_ARGUMENT',
            default => self::UNEXPECTED,
        };
    }

    /**
     * The message safe to show any caller: the exception's own where it is
     * written for one, a fixed text where it is not (a busy or failed
     * database, whose driver text names files, and an unexpected failure).
     */
    public static function publicMessage(Throwable $error): string
    {
        return match (self::for($error)) {
            SqliteBusy::CODE => SqliteBusy::MESSAGE,
            self::STORAGE => self::STORAGE_MESSAGE,
            self::UNEXPECTED => self::GENERIC_MESSAGE,
            default => $error->getMessage(),
        };
    }

    /**
     * Whether the exception's own message is withheld from a caller other
     * than the operator: a database failure or an unexpected one, whose text
     * can name internal paths. A busy database is not: its text says nothing
     * the fixed message does not.
     */
    public static function withholdsDetail(Throwable $error): bool
    {
        $code = self::for($error);

        return $code === self::STORAGE || $code === self::UNEXPECTED;
    }
}
