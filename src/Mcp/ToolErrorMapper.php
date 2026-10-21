<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use Knossos\Runtime\ErrorCode;
use Knossos\Store\SqliteBusy;
use Throwable;

/**
 * What a client is told about a failure: the code {@see ErrorCode} decides,
 * plus the exception's own message only where that message is written for
 * the caller. Anything else is logged and replaced, so a driver error or an
 * internal path never reaches a client, whichever route the failure took out.
 */
final class ToolErrorMapper
{
    /** What an MCP client is given for a failure the shared table does not map. */
    public const GENERIC_CODE = 'KNOSSOS_TOOL_ERROR';
    public const GENERIC_MESSAGE = ErrorCode::GENERIC_MESSAGE;

    /** Said for a write that met another process's write lock, a scan's most often. */
    public const BUSY_MESSAGE = SqliteBusy::MESSAGE;

    private function __construct() {}

    /** The stable error code a client is given for this failure. */
    public static function code(Throwable $error): string
    {
        $code = ErrorCode::for($error);

        return $code === ErrorCode::UNEXPECTED ? self::GENERIC_CODE : $code;
    }

    /** The message safe to send. A failure whose detail is withheld is logged first. */
    public static function publicMessage(Throwable $error): string
    {
        if (ErrorCode::withholdsDetail($error)) {
            error_log('knossos tool error: ' . $error->getMessage());
        }

        return ErrorCode::publicMessage($error);
    }
}
