<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;

/**
 * `web [<dir>] [--port=N] [--no-open]`: the Claude Code pane in a browser
 * tab, for the project in `<dir>` (the working directory by default).
 *
 * The server is Node's (`web/main.ts` in this installation, which loads the
 * pane's TypeScript as it is, with no build step), so this command only
 * checks that the `node` on PATH is new enough and hands over to it: its
 * output is the terminal's, and its exit code is this command's. It runs in
 * the foreground until Ctrl-C; a signal sent to this process alone is passed
 * on, so the server always gets to end its watcher and remove its lock.
 */
final class WebCommand implements CliCommand
{
    /** The oldest Node that runs TypeScript unflagged. */
    private const NODE_MAJOR = 24;

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
        return ['port', 'no-open'];
    }

    /** {@inheritDoc} */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $node = self::onPath('node');
        $version = $node === null ? null : self::versionOf($node);
        if ($node === null || $version === null || (int) ltrim($version, 'v') < self::NODE_MAJOR) {
            fwrite($this->stderr, sprintf('knossos web needs Node %d or newer (found %s).' . PHP_EOL, self::NODE_MAJOR, $version ?? 'none on PATH'));

            return CliCommand::EXIT_ERROR;
        }
        $argv = [$node, $context->installationRoot() . '/web/main.ts', $positionals[0] ?? (getcwd() ?: '.')];
        $port = $context->options->single($options, 'port');
        if ($port !== null) {
            $argv[] = '--port=' . $port;
        }
        if ($context->options->flag($options, 'no-open')) {
            $argv[] = '--no-open';
        }

        return self::runInForeground($argv);
    }

    /** The executable `$name` on PATH, or null. */
    private static function onPath(string $name): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $candidate = rtrim($directory, '/') . '/' . $name;
            if ($directory !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** What `$node --version` prints (`v24.3.0`), or null when it prints no version. */
    private static function versionOf(string $node): ?string
    {
        $process = proc_open([$node, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        $output = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return preg_match('/^v\d+\.\d+\.\d+/', $output, $match) === 1 ? $match[0] : null;
    }

    /**
     * Runs `$argv` on this terminal and returns its exit code. Ctrl-C reaches
     * the whole foreground group, the server included; a signal sent to this
     * process alone (a SIGTERM from a supervisor) is passed on to it.
     *
     * @param non-empty-list<string> $argv
     */
    private static function runInForeground(array $argv): int
    {
        $process = proc_open($argv, [STDIN, STDOUT, STDERR], $pipes);
        if (!is_resource($process)) {
            return CliCommand::EXIT_ERROR;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
                pcntl_signal($signal, static function (int $signal) use ($process): void {
                    proc_terminate($process, $signal);
                });
            }
        }
        // Polled rather than waited on: a blocking wait would hold a passed-on signal until the server had ended.
        do {
            $status = proc_get_status($process);
            if ($status['running']) {
                usleep(50_000);
            }
        } while ($status['running']);
        proc_close($process);
        if ($status['signaled']) {
            return 128 + $status['termsig'];
        }

        return $status['exitcode'] < 0 ? CliCommand::EXIT_ERROR : $status['exitcode'];
    }
}
