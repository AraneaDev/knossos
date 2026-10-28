<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use Knossos\Cli\CliCommand;

/**
 * Hands a command over to a Node entry point of this installation: the
 * `node` on PATH is checked to be new enough to run TypeScript unflagged,
 * and the entry runs in the foreground with this terminal, its exit code
 * becoming the command's. `web` and the terminal pane share it.
 */
final class NodeHandOver
{
    /** The oldest Node that runs TypeScript unflagged. */
    private const NODE_MAJOR = 24;

    /**
     * Runs `node <arguments>` on this terminal and returns its exit code, or
     * refuses on `$stderr` with "<$name> needs Node 24 or newer" when the
     * `node` on PATH is missing or older. A signal sent to this process alone
     * (a SIGTERM from a supervisor) is passed on to the entry, so it always
     * gets to restore what it changed; Ctrl-C reaches the whole foreground
     * group.
     *
     * @param non-empty-list<string> $arguments the entry point, then its arguments
     * @param resource $stderr where a refusal is written
     */
    public static function run(string $name, array $arguments, $stderr): int
    {
        $node = self::onPath('node');
        $version = $node === null ? null : self::versionOf($node);
        if ($node === null || $version === null || (int) ltrim($version, 'v') < self::NODE_MAJOR) {
            fwrite($stderr, sprintf('%s needs Node %d or newer (found %s).' . PHP_EOL, $name, self::NODE_MAJOR, $version ?? 'none on PATH'));

            return CliCommand::EXIT_ERROR;
        }

        return self::runInForeground([$node, ...$arguments]);
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
