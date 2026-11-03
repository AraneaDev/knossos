<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\ProjectDatabaseLocator;
use Knossos\Query\PaneCommands;
use Knossos\Query\RescanService;
use Knossos\Roots\AllowedRoots;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Scan\ScanFailureLog;
use Throwable;

/**
 * The commands the Claude Code mod makes, as {@see PaneCommands} declares
 * them: this class only finds the database, checks the options, and prints.
 *
 * Addressed by path like `session-brief`, through the same database
 * resolution. All always exit 0, even on an unknown option or a stray
 * argument, which they check themselves rather than leave to the router:
 * the caller is a hook that must never break a session, so failure is a
 * status in the JSON, not an exit code.
 * Only `rescan` creates a database, and only for a path that is exactly an
 * allowed root: a fresh install has none until the person's first start. The
 * read-only commands never do. `turn-brief` and `rescan` write by scanning a
 * project that already exists in an allowed root (`rescan` also makes the
 * first scan of a path that is exactly an allowed root), and
 * `annotate`, a note on a component, only with `--execute`.
 */
final class BriefCommand implements CliCommand
{
    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return in_array($command, PaneCommands::commands(), true);
    }

    /**
     * {@inheritDoc}
     *
     * Any: the router's rejection would exit non-zero, so run() checks them
     * against the command's own options and reports an error status instead.
     */
    public function allowedOptions(string $command): array
    {
        return [CliOptionParser::ANY];
    }

    /**
     * {@inheritDoc}
     *
     * Always exit 0. With `--json` a failure prints `{"status":"error"}`;
     * without it nothing is printed.
     */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $json = $context->options->flag($options, 'json');
        try {
            $context->options->validate($options, ['db', 'json', ...PaneCommands::options($command)]);
            [$path, $name] = PaneCommands::target($command, $positionals);
            // A project id in place of the path: answer for that project's root.
            [$path, $databasePath] = (new ProjectDatabaseLocator())->target($path, $options, $context);
        } catch (Throwable) {
            if ($json) {
                $context->output(['status' => 'error'], true, '');
            }

            return CliCommand::EXIT_OK;
        }
        try {
            $result = is_file($databasePath) || ($command === 'rescan' && $this->isExactAllowedRoot($path, $databasePath))
                ? $this->answer($command, $path, $name, $databasePath, $options, $context)
                : ['status' => 'unscanned', 'path' => realpath($path) ?: $path];
            $context->output($result, $json, (string) $result['status']);
        } catch (Throwable $failure) {
            // A database that cannot be opened fails every command; for the ones that scan, the reason is kept
            // where `knossos doctor` reads it, and it is said, so the pane need not guess.
            // A refused argument is the caller's mistake and needs no account; anything else is the installation's.
            $refused = $failure instanceof \InvalidArgumentException;
            $reason = mb_strcut($failure->getMessage(), 0, 300);
            if (!$refused && in_array($command, ['rescan', 'turn-brief'], true)) {
                ScanFailureLog::forDatabase($databasePath)?->recordThrowable(realpath($path) ?: $path, $command === 'rescan' ? 'pane' : 'turn', $failure, hrtime(true));
            }
            if ($json) {
                $context->output($refused ? ['status' => 'error'] : ['status' => 'error', 'reason' => $reason], true, '');
            }
        }

        return CliCommand::EXIT_OK;
    }

    /**
     * Whether `$path` is exactly one of the roots allowed for the database at
     * `$databasePath`, which is what lets `rescan` make a database that a fresh
     * install does not have yet.
     */
    private function isExactAllowedRoot(string $path, string $databasePath): bool
    {
        $absolute = realpath($path);
        if ($absolute === false) {
            return false;
        }
        $allowed = new AllowedRoots(AllowedRoots::fromEnvironment(), AllowedRoots::defaultConfigPath($databasePath));

        return RescanService::isAllowedRoot($allowed, $absolute);
    }

    /**
     * Opens the database and runs the requested command.
     *
     * Only reached for a database that exists, or for a `rescan` of a path that
     * is exactly an allowed root, which creates it. Every other command only
     * brings a stale schema up to date before it reads or scans.
     *
     * @param array<string, list<string>> $options
     * @return array<string, mixed>
     */
    private function answer(string $command, string $path, string $name, string $databasePath, array $options, CliCommandContext $context): array
    {
        $pdo = (new RuntimeFactory($context->installationRoot()))->database($databasePath);
        $read = $context->options;

        return (new PaneCommands($pdo, $databasePath, $context->installationRoot()))->answer(
            $command,
            $path,
            static fn(string $option): ?string => $read->single($options, $option),
            static fn(string $option): bool => $read->flag($options, $option),
            [
                'name' => $name,
                'values' => static fn(string $option): array => $read->values($options, $option),
                'integer' => static fn(string $option, int $default, int $minimum, int $maximum): int => $read->integer($options, $option, $default, $minimum, $maximum),
                'policies' => static fn(string $file): array => $context->input->policies($file),
            ],
        );
    }
}
