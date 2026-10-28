<?php

declare(strict_types=1);

namespace Knossos;

use Knossos\Cli\CliCommandRouter;
use Knossos\Cli\CliErrorRenderer;
use Knossos\Cli\CliHelpRenderer;
use Knossos\Cli\CliOptionParser;
use Knossos\Runtime\Version;
use Throwable;

/**
 * CLI entry point: parses argv, routes to a command, renders errors.
 *
 * Every failure leaves through here as a stable diagnostic code and a non-zero
 * exit, because the CLI is consumed by CI as well as by people.
 */
final class Application
{
    /** The installation's version; see {@see Version::CURRENT}. */
    public const VERSION = Version::CURRENT;

    /** @var resource|null */
    private $errorStream;

    /** @var resource|null */
    private $helpStream;

    /**
     * @param resource|null $errorStream Destination for the failure diagnostic;
     *                                   null uses the process STDERR. Tests pass
     *                                   an in-memory stream so the suite never
     *                                   writes to the real STDERR — see
     *                                   CliErrorRenderer for why that matters to
     *                                   mutation testing.
     * @param resource|null $helpStream Destination for the help text; null
     *                                  uses the process STDOUT. Tests pass an
     *                                  in-memory stream, as for the errors,
     *                                  since a write to STDOUT passes PHPUnit's
     *                                  output capture.
     */
    public function __construct($errorStream = null, $helpStream = null)
    {
        $this->errorStream = $errorStream;
        $this->helpStream = $helpStream;
    }

    /**
     * Parse argv, route to a command, and turn any failure into a diagnostic code and exit status.
     *
     * @param list<string> $arguments
     */
    public function run(array $arguments): int
    {
        // Bare `knossos`, with or without options (`knossos --theme=light`), opens the pane in the terminal.
        $first = $arguments[0] ?? null;
        $bare = $first === null || (str_starts_with($first, '--') && !in_array($first, ['--help', '--version'], true));
        $command = $bare ? 'tui' : (string) array_shift($arguments);
        $parser = new CliOptionParser();
        try {
            [$positionals, $options] = $parser->parse($arguments);
            return (new CliCommandRouter(dirname(__DIR__), $parser, new CliHelpRenderer($this->helpStream), self::VERSION))
                ->route($command, $positionals, $options);
        } catch (Throwable $error) {
            return (new CliErrorRenderer($this->errorStream))->render($error);
        }
    }
}
