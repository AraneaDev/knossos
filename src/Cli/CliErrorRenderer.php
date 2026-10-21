<?php

declare(strict_types=1);

namespace Knossos\Cli;

use Knossos\Runtime\ErrorCode;
use Throwable;

/**
 * Renders failures as a stable diagnostic code plus a human message.
 *
 * The codes are the contract CI scripts match on, so they are deliberately more
 * durable than the prose beside them.
 */
final class CliErrorRenderer
{
    /** @var resource */
    private $stream;

    /**
     * @param resource|null $stream Destination for the diagnostic line; defaults
     *                              to the process STDERR.
     *
     * The stream is injectable so tests can render into an in-memory stream and
     * assert the emitted diagnostic code. That is not merely convenience: under
     * `infection --filter=<file>` Infection's InitialTestsRunner calls
     * `$process->stop()` on the FIRST byte the test process writes to STDERR
     * (Process::ERR), which SIGTERMs PHPUnit mid-suite ("PHPUnit reported an
     * exit code of 143") and aborts the whole mutation run before any mutant is
     * generated. A suite that writes real diagnostics to STDERR is therefore
     * un-mutation-testable, so tests must render into their own stream.
     */
    public function __construct($stream = null)
    {
        /** @var resource $target */
        $target = $stream ?? STDERR;
        $this->stream = $target;
    }

    /**
     * Stable diagnostic code for a throwable, independent of rendering: the
     * one {@see ErrorCode} gives every adapter. Exposed so the classification
     * can be asserted directly without a stream.
     */
    public static function diagnosticCode(Throwable $error): string
    {
        return ErrorCode::for($error);
    }

    /**
     * The message shown beside the code. The CLI runs for the operator on
     * their own machine, so it shows the detail an MCP client is spared (a
     * database or unexpected failure's own text); everywhere else it says
     * what every adapter says.
     */
    public static function message(Throwable $error): string
    {
        return ErrorCode::withholdsDetail($error) ? $error->getMessage() : ErrorCode::publicMessage($error);
    }

    /** Render a failure as a stable diagnostic code plus a human message. */
    public function render(Throwable $error): int
    {
        fwrite($this->stream, self::diagnosticCode($error) . ': ' . self::message($error) . PHP_EOL);
        return CliCommand::EXIT_ERROR;
    }
}
