<?php

declare(strict_types=1);

namespace Knossos\Cli;

use InvalidArgumentException;
use Knossos\Cli\Command\CliCommandSet;
use Knossos\Runtime\RuntimeFactory;

/**
 * Picks the command for an invocation and validates its options first.
 *
 * An unrecognised option is an error, never a no-op: a mistyped flag that appears
 * to work is how a CI job silently stops checking what it was meant to check.
 */
final class CliCommandRouter
{
    /** @var list<CliCommand> */
    private array $commands;

    public function __construct(
        private readonly string $installationRoot,
        private readonly CliOptionParser $options,
        CliHelpRenderer $help,
        string $version,
    ) {
        $this->commands = CliCommandSet::all($help, $version);
    }

    /** The first command that claims the name, or null. */
    private function handlerFor(string $command): ?CliCommand
    {
        foreach ($this->commands as $candidate) {
            if ($candidate->supports($command)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Pick the command for an invocation, validating its options before it runs.
     *
     * @param list<string> $positionals @param array<string, list<string>> $options
     */
    public function route(string $command, array $positionals, array $options): int
    {
        // Validate and resolve the command BEFORE any database work, so a
        // typo'd command never creates `.knossos`, opens a connection, or runs
        // migrations. The handler opens the database lazily only when needed.
        $handler = $this->handlerFor($command);
        if ($handler === null && is_dir($command)) {
            // A directory where a command would be: the terminal pane for it, as `knossos <dir>` says.
            array_unshift($positionals, $command);
            $command = 'tui';
            $handler = $this->handlerFor($command);
        }
        if ($handler === null) {
            throw new InvalidArgumentException(sprintf('Unknown command: %s', $command));
        }
        $this->options->validate($options, $handler->allowedOptions($command));
        $meta = in_array($command, ['version', '--version', 'help', '--help', '-h', 'usage'], true);
        $context = new CliCommandContext(
            $this->options,
            new CliInputLoader(),
            new RuntimeFactory($this->installationRoot),
            $meta ? null : $this->options->single($options, 'db'),
        );
        return $handler->run($command, $positionals, $options, $context);
    }
}
