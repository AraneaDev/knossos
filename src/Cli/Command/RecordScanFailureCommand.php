<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\DirectoryArgument;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ProjectWriterLock;
use Knossos\Scan\ScanFailureClass;
use Knossos\Scan\ScanFailureLog;
use Throwable;

/**
 * Keeps a scan failure that only the shell wrapper saw: a process a signal ended, or one that ran out of time.
 *
 * Neither leaves an exception behind in the scan, so the wrapper that watched
 * it die says so here, and the line lands in the same log the scan writes its
 * own failures to. Called from hooks that turn a nonzero exit into silence and
 * must never delay a session, so it does no more than append a line, and a log
 * that cannot be written is not an error.
 */
final class RecordScanFailureCommand implements CliCommand
{
    private const STARTED_BY = ['pane', 'mcp', 'refresh', 'turn', 'watch', 'cli'];

    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return $command === 'record-scan-failure';
    }

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return ['db', 'class', 'signal', 'started-by'];
    }

    /** {@inheritDoc} */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $path = DirectoryArgument::of($positionals, 'Usage: knossos record-scan-failure <path> --class=C [--signal=N] [--started-by=S] [--db=FILE]');
        $class = $context->options->single($options, 'class');
        if ($class === null || !in_array($class, ScanFailureClass::ALL, true)) {
            throw new InvalidArgumentException('--class is one of ' . implode(', ', ScanFailureClass::ALL) . '.');
        }
        $startedBy = $context->options->single($options, 'started-by') ?? 'cli';
        if (!in_array($startedBy, self::STARTED_BY, true)) {
            throw new InvalidArgumentException('--started-by is one of ' . implode(', ', self::STARTED_BY) . '.');
        }
        $signal = $context->options->single($options, 'signal') === null ? null : $context->options->integer($options, 'signal', 0, 1, 64);
        $root = realpath($path) ?: $path;

        ScanFailureLog::forDatabase($context->databasePath())?->record([
            'root' => $root,
            'started_by' => $startedBy,
            'class' => $class,
            'message' => self::message($class, $signal, $root, $context),
            'elapsed_ms' => null,
            'signal' => $signal,
        ]);

        return CliCommand::EXIT_OK;
    }

    /** The log's message for a failure the wrapper reported. */
    private static function message(string $class, ?int $signal, string $root, CliCommandContext $context): string
    {
        return match ($class) {
            ScanFailureClass::KILLED => sprintf('The scan process ended on signal %s.', $signal ?? 'unknown'),
            ScanFailureClass::TIMED_OUT => 'The scan did not finish within the wrapper bound.',
            ScanFailureClass::BRIEF_TIMEOUT => 'The session brief did not finish in time' . self::holderOf($root, $context) . '.',
            default => 'Reported by a wrapper.',
        };
    }

    /** Who holds the project's write lease, as ` (pid 4242, held 40s)`, when there is a database to ask and a holder. */
    private static function holderOf(string $root, CliCommandContext $context): string
    {
        $databasePath = $context->databasePath();
        if (!is_file($databasePath)) {
            return '';
        }
        try {
            $holder = (new ProjectWriterLock($context->database()))->describeHolder(ProjectScanService::projectIdOf($root));

            return $holder === '' ? '' : ' while a scan held the graph' . $holder;
        } catch (Throwable) {
            return '';
        }
    }
}
