<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\ProjectDatabaseLocator;
use Knossos\Cli\ProjectReference;
use Knossos\Query\PaneCommands;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Store\SqliteConnection;
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
 * None creates a database; only `turn-brief` and `rescan` write, by
 * scanning a project that already exists in an allowed root, and
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
            $databasePath = (new ProjectDatabaseLocator())->locate($path, $options, $context);
            if (is_file($databasePath) && !file_exists($path)) {
                // A project id in place of the path: answer for that project's root.
                $path = (new ProjectReference(SqliteConnection::open($databasePath), $databasePath))->byId($path)['root'] ?? $path;
            }
            $result = is_file($databasePath)
                ? $this->answer($command, $path, $name, $databasePath, $options, $context)
                : ['status' => 'unscanned', 'path' => realpath($path) ?: $path];
            $context->output($result, $json, (string) $result['status']);
        } catch (Throwable) {
            if ($json) {
                $context->output(['status' => 'error'], true, '');
            }
        }
        return CliCommand::EXIT_OK;
    }

    /**
     * Opens the existing database (migrating it) and runs the requested command.
     *
     * Only reached after the `is_file` guard, so the migration never creates a
     * database; it only brings a stale schema up to date before a scan writes.
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
