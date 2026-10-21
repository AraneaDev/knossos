<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\ProjectDatabaseLocator;
use Knossos\Query\QueryServices;
use Knossos\Query\SessionBriefRenderer;
use Knossos\Query\SessionBriefService;
use Knossos\Store\SqliteConnection;
use Throwable;

/**
 * The session-start orientation brief, as a CLI command.
 *
 * Its own class rather than another arm of QueryCommand for two reasons that
 * both cut against sharing: it is addressed by filesystem path instead of by
 * project id, and it must never throw. Every other query command signals a bad
 * invocation by throwing, which is right for a person at a terminal and wrong
 * for a hook that runs before a session starts.
 *
 * It is also the one command that must not open the database the way every
 * other one does. {@see CliCommandContext::database()} creates the data
 * directory and runs every migration, which is exactly right for a command
 * that is about to write and exactly wrong for the session-start path: a
 * `session-brief` in a directory nobody ever scanned used to leave a migrated
 * SQLite file behind, from a hook nobody asked to run. So this command locates
 * the database itself, checks that it is already there, and opens it only
 * then.
 */
final class SessionCommand implements CliCommand
{
    /** @var resource Where a failure's reason goes; the hook discards it, a person running the command sees it. */
    private $errors;

    /**
     * @param resource|null $errors defaults to STDERR; tests pass their own,
     *                              since a byte on the real STDERR stops a mutation run (see CliErrorRenderer)
     */
    public function __construct($errors = null)
    {
        /** @var resource $target */
        $target = $errors ?? STDERR;
        $this->errors = $target;
    }

    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return $command === 'session-brief';
    }

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return ['db', 'json'];
    }

    /**
     * {@inheritDoc}
     *
     * Always exit 0 and nothing on stdout on failure. A broken brief that breaks a
     * session start is worse than no brief at all, so every error path here
     * degrades to no brief, with one line saying why on the error stream.
     */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        try {
            $path = (string) ($positionals[0] ?? getcwd());
            // A project id in place of the path: brief that project's root.
            [$path, $databasePath] = (new ProjectDatabaseLocator())->target($path, $options, $context);
            // The existence check is the whole read-only guarantee. Opening a
            // path that is not there creates it; migrating it writes to it.
            // Neither may happen on a path that runs before a session starts,
            // so an absent database is answered from nothing at all.
            $brief = is_file($databasePath)
                ? QueryServices::wire(SqliteConnection::open($databasePath))->sessionBrief($databasePath)->brief($path)
                : (new SessionBriefRenderer())->render(SessionBriefService::unscanned($path, $databasePath));
            $context->output(['brief' => $brief], $context->options->flag($options, 'json'), $brief);
        } catch (Throwable $error) {
            // No brief and exit 0, whatever happens: the session still starts.
            // The reason goes to the error stream, which the hook discards, so a
            // person running the command by hand learns why it said nothing.
            fwrite($this->errors, 'knossos session-brief: ' . $error->getMessage() . "\n");
        }
        return CliCommand::EXIT_OK;
    }
}
