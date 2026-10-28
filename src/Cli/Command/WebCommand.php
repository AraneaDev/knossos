<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;

/**
 * `web [<dir>] [--port=N] [--no-open] [--editor=<command>]`: the Claude Code
 * pane in a browser tab, for the project in `<dir>` (the working directory by
 * default).
 *
 * The server is Node's (`web/main.ts` in this installation, which loads the
 * pane's TypeScript as it is, with no build step), so this command only
 * hands over to it through {@see NodeHandOver}: its output is the terminal's,
 * and its exit code is this command's. It runs in
 * the foreground until Ctrl-C; a signal sent to this process alone is passed
 * on, so the server always gets to end its watcher and remove its lock.
 */
final class WebCommand implements CliCommand
{
    /** @var resource */
    private $stderr;

    /** @param resource|null $stderr where a refusal is written; the process's STDERR by default, a memory stream in the tests */
    public function __construct($stderr = null)
    {
        /** @var resource $target */
        $target = $stderr ?? STDERR;
        $this->stderr = $target;
    }

    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return $command === 'web';
    }

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return ['port', 'no-open', 'editor'];
    }

    /** {@inheritDoc} */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $argv = [$context->installationRoot() . '/web/main.ts', $positionals[0] ?? (getcwd() ?: '.')];
        $port = $context->options->single($options, 'port');
        if ($port !== null) {
            $argv[] = '--port=' . $port;
        }
        if ($context->options->flag($options, 'no-open')) {
            $argv[] = '--no-open';
        }
        $editor = $context->options->single($options, 'editor');
        if ($editor !== null) {
            $argv[] = '--editor=' . $editor;
        }

        return NodeHandOver::run('knossos web', $argv, $this->stderr);
    }
}
