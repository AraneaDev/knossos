<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use InvalidArgumentException;
use Knossos\Discovery\DiscoveryException;
use Knossos\Scan\ScanSnapshotChangedException;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Store\ScanBusyException;
use Knossos\Store\SqliteBusy;
use Throwable;

/**
 * What a client is told about a failure: a stable code, plus the exception's
 * own message only where that message is written for the caller. Anything
 * else is logged and replaced, so a driver error or an internal path never
 * reaches a client, whichever route the failure took out.
 */
final class ToolErrorMapper
{
    public const GENERIC_CODE = 'KNOSSOS_TOOL_ERROR';
    public const GENERIC_MESSAGE = 'An unexpected error occurred while running the tool.';

    /** Said for a write that met another process's write lock, a scan's most often. */
    public const BUSY_MESSAGE = SqliteBusy::MESSAGE;

    private function __construct() {}

    /** The stable error code a client is given for this failure. */
    public static function code(Throwable $error): string
    {
        return match (true) {
            $error instanceof ScanBusyException => 'KNOSSOS_SCAN_BUSY',
            SqliteBusy::anywhere($error) => SqliteBusy::CODE,
            // Mapped so the message survives: the file this exception names
            // is the whole of what the caller needs to act on.
            $error instanceof ScanSnapshotChangedException => 'KNOSSOS_SCAN_SNAPSHOT_CHANGED',
            $error instanceof WorkerException => $error->diagnosticCode,
            $error instanceof DiscoveryException => 'KNOSSOS_UNSAFE_PATH',
            $error instanceof InvalidArgumentException => 'KNOSSOS_INVALID_ARGUMENT',
            default => self::GENERIC_CODE,
        };
    }

    /** The message safe to send. An unexpected failure is logged and replaced. */
    public static function publicMessage(Throwable $error): string
    {
        $code = self::code($error);
        if ($code === SqliteBusy::CODE) {
            // The driver's text names files; the caller only needs to know to retry.
            return self::BUSY_MESSAGE;
        }
        if ($code !== self::GENERIC_CODE) {
            return $error->getMessage();
        }
        error_log('knossos tool error: ' . $error->getMessage());

        return self::GENERIC_MESSAGE;
    }
}
