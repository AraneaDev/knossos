<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use Closure;
use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliHelpRenderer;

/**
 * `tui [<dir>] [--editor=<command>] [--theme=dark|light]`, what a bare
 * `knossos` runs: the Claude Code pane fullscreen in this terminal, for the
 * project in `<dir>` (the working directory by default).
 *
 * Like `web` it hands over to Node (`tui/main.ts` in this installation),
 * which is a client of the project's `web` server. Without a terminal on
 * both ends there is no pane to show, so it prints the command list instead
 * (a script that ran bare `knossos` for it gets it on stderr) and fails.
 */
final class TuiCommand implements CliCommand
{
    /** @var resource */
    private $stderr;

    /** @var Closure(): bool */
    private readonly Closure $isTerminal;

    /**
     * @param resource|null $stderr where a refusal is written; the process's STDERR by default, a memory stream in the tests
     * @param (callable(): bool)|null $isTerminal whether stdin and stdout are both a terminal; the real check by default
     */
    public function __construct($stderr = null, ?callable $isTerminal = null)
    {
        /** @var resource $target */
        $target = $stderr ?? STDERR;
        $this->stderr = $target;
        $this->isTerminal = $isTerminal === null
            ? static fn(): bool => stream_isatty(STDIN) && stream_isatty(STDOUT)
            : Closure::fromCallable($isTerminal);
    }

    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return $command === 'tui';
    }

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return ['editor', 'theme'];
    }

    /** {@inheritDoc} */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        // A value written after a space leaves the option bare and the value a positional: say how to write it.
        foreach (['editor' => '<command>', 'theme' => 'dark|light'] as $name => $form) {
            if (($options[$name] ?? null) === ['']) {
                throw new InvalidArgumentException(sprintf('--%s needs its value after an equals sign: --%s=%s', $name, $name, $form));
            }
        }
        // One directory at most, as the pane itself takes it: a second would otherwise be dropped without a word.
        if (count($positionals) > 1) {
            throw new InvalidArgumentException(sprintf('one directory at most, not %s and %s', $positionals[0], $positionals[1]));
        }
        if (!($this->isTerminal)()) {
            (new CliHelpRenderer($this->stderr))->render();
            fwrite($this->stderr, PHP_EOL . 'knossos opens the pane in this terminal; knossos usage prints this list.' . PHP_EOL);

            return CliCommand::EXIT_ERROR;
        }
        $argv = [$context->installationRoot() . '/tui/main.ts', $positionals[0] ?? (getcwd() ?: '.')];
        $editor = $context->options->single($options, 'editor');
        if ($editor !== null) {
            $argv[] = '--editor=' . $editor;
        }
        $theme = $context->options->single($options, 'theme');
        if ($theme !== null) {
            $argv[] = '--theme=' . $theme;
        }

        return NodeHandOver::run('knossos', $argv, $this->stderr);
    }
}
