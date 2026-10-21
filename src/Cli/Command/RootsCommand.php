<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Roots\AllowedRoots;
use Knossos\Roots\RootsFile;
use Knossos\Runtime\RuntimeFactory;

/**
 * Grants a project directory scan access, without hand-editing roots.json.
 *
 * `serve` refuses to scan anything outside its allowed roots, and the only
 * remedy until now was editing that file directly. A running server re-reads
 * it per request, which is what makes a CLI command for this worthwhile
 * rather than a formality: the root it appends takes effect immediately,
 * with no restart and no re-registration of the server.
 *
 * Preview by default, matching `annotate-component` and
 * `install-agent-plugin`, because this command mutates a security-relevant
 * allowlist: the set of directory trees the server may read.
 */
final class RootsCommand implements CliCommand
{
    /**
     * What a command that writes a file beside the database says when nothing
     * named that location. Shared with `enricher`, whose file sits beside this one.
     */
    public const WORKING_DIRECTORY_WARNING = 'That location came from the working directory, not from KNOSSOS_ROOTS_FILE or KNOSSOS_DATA_DIR, '
        . 'so it may not be the file your server reads. Check server_info.';

    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return $command === 'allow-root';
    }

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return ['db', 'json', 'execute'];
    }

    /** {@inheritDoc} */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $path = $positionals[0] ?? throw new InvalidArgumentException(
            'Usage: knossos allow-root <path> [--execute] [--db=FILE] [--json]',
        );
        // Roots are compared against the canonical path a scan is asked to
        // cover, which is always absolute, so a relative entry would never
        // match anything and would silently grant nothing.
        if (!str_starts_with($path, '/')) {
            throw new InvalidArgumentException(sprintf(
                '%s is not absolute. allow-root needs an absolute path so it can be compared against scan requests.',
                $path,
            ));
        }
        // A root that does not exist looks identical to a working one until
        // something tries to scan it, which is the confusion this check
        // prevents up front instead of at scan time.
        if (!is_dir($path)) {
            throw new InvalidArgumentException(sprintf('%s is not an existing directory.', $path));
        }
        // Both the comparison below and the entry written out use the reader's
        // own spelling of a root, borrowed from AllowedRoots rather than
        // reinvented, so `/p` and `/p/` cannot become two lines granting one
        // directory. AllowedRoots::normalise() folds them back together when it
        // reads the file, so the duplicate was never a security hole; it was
        // rot in a file people hand-edit, and a command reporting "already
        // present" for neither spelling.
        $path = AllowedRoots::normaliseRoot($path);

        $configPath = AllowedRoots::defaultConfigPath($context->databasePath());
        $fresh = !is_file($configPath);
        $json = $context->options->flag($options, 'json');
        $named = self::locationIsKnown($context);
        // The natural way to grant a project is to run this from inside it,
        // and that is exactly the working directory that resolves the roots
        // file to a brand-new store under the project being granted. The
        // grant then lands in a file no server reads, and the project gains
        // an untracked `.knossos/` directory of its own. Nobody means that,
        // so it is refused rather than warned about.
        if (!$named && $fresh && self::inside($path, $configPath)) {
            throw new InvalidArgumentException(sprintf(
                "Refusing to create a new roots file at %s, inside the directory being granted.\n"
                . "That path came from the working directory, so it is a new store no running server reads.\n"
                . "Name the location instead: KNOSSOS_DATA_DIR=<dir>, KNOSSOS_ROOTS_FILE=<file>, or --db=<file>.\n"
                . 'server_info reports the roots file your server actually reads.',
                $configPath,
            ));
        }
        $roots = (new RootsFile($configPath))->read();
        $effect = match (true) {
            $named => 'A server configured with that roots file re-reads it per request, so %s with no restart.',
            // No %s: like the plain working-directory case below, this must
            // not promise an effect. It is a new file nothing is reading yet.
            $fresh => 'That is a NEW roots file at a location that came from the working directory, '
                . 'not from KNOSSOS_ROOTS_FILE or KNOSSOS_DATA_DIR. A server started anywhere else reads a '
                . 'different one and will not see this grant. Check server_info.',
            default => self::WORKING_DIRECTORY_WARNING,
        };

        // Existing entries are compared normalised but rewritten verbatim: a
        // root someone typed with a trailing slash before this fix is already
        // granted, and silently rewriting lines this call did not add would
        // make an append look like an edit.
        if (in_array($path, array_map(AllowedRoots::normaliseRoot(...), $roots), true)) {
            $context->output(
                ['path' => $path, 'roots_file' => $configPath, 'added' => false],
                $json,
                sprintf('%s is already present in %s. Nothing to do.', $path, $configPath),
            );
            return CliCommand::EXIT_OK;
        }

        $roots[] = $path;

        if (!$context->options->flag($options, 'execute')) {
            $context->output(
                [
                    'path' => $path,
                    'roots_file' => $configPath,
                    'roots_file_source' => $named ? 'named' : 'working-directory',
                    'added' => false,
                    'preview' => true,
                ],
                $json,
                sprintf(
                    "Would add %s to %s.\n%s\nRe-run with --execute to write it.",
                    $path,
                    $configPath,
                    sprintf($effect, 'the addition would take effect'),
                ),
            );
            return CliCommand::EXIT_OK;
        }

        (new RootsFile($configPath))->write($roots);
        $context->output(
            [
                'path' => $path,
                'roots_file' => $configPath,
                'roots_file_source' => $named ? 'named' : 'working-directory',
                'added' => true,
            ],
            $json,
            sprintf(
                "Added %s to %s.\n%s",
                $path,
                $configPath,
                sprintf($effect, 'this takes effect immediately'),
            ),
        );
        return CliCommand::EXIT_OK;
    }

    /**
     * Whether a path lies within a directory, whatever either is spelled like.
     *
     * A lexical prefix test answers for the spelling rather than the location,
     * so `<project>/../<project>` and a symlink to the project both read as
     * somewhere else and walked straight past the refusal, writing the unused
     * store into the project after all. Both sides are resolved first.
     */
    private static function inside(string $directory, string $path): bool
    {
        $directory = realpath($directory);
        if ($directory === false) {
            return false;
        }

        return str_starts_with(self::canonical($path), rtrim($directory, '/') . '/');
    }

    /**
     * A path with its deepest existing ancestor resolved.
     *
     * The roots file is the path being tested and it usually does not exist
     * yet, which is the whole case this guard is for, so realpath() alone
     * answers false. Its nearest existing ancestor does resolve, and the
     * segments below it are names nothing can alias.
     */
    private static function canonical(string $path): string
    {
        $unresolved = [];
        $current = $path;
        while (($resolved = realpath($current)) === false) {
            $parent = dirname($current);
            if ($parent === $current) {
                return $path;
            }
            array_unshift($unresolved, basename($current));
            $current = $parent;
        }

        return $unresolved === [] ? $resolved : rtrim($resolved, '/') . '/' . implode('/', $unresolved);
    }

    /**
     * Whether the roots file's location is known rather than guessed from the working directory.
     *
     * Four things make it known. Three are somebody naming it, and they are
     * the three {@see AllowedRoots::defaultConfigPath()} and
     * {@see RuntimeFactory::defaultDatabasePath()} consult before falling
     * back: `KNOSSOS_ROOTS_FILE`, which names the file outright;
     * `KNOSSOS_DATA_DIR`, which names the directory it sits in; and `--db`,
     * which names the database it sits beside. The fourth is the
     * installation's home graph, `~/.knossos/knossos.sqlite`, which the
     * runtime picks when it exists: `tools/install` pins the server and hooks
     * to it, so the file beside it is the one the server reads. With none of
     * them the location is whatever the working directory implies, which is
     * rarely the file a running server reads and is therefore worth
     * disclosing rather than asserting a restart-free effect the caller
     * cannot rely on.
     */
    public static function locationIsKnown(CliCommandContext $context): bool
    {
        foreach (['KNOSSOS_ROOTS_FILE', 'KNOSSOS_DATA_DIR'] as $variable) {
            $value = getenv($variable);
            if (is_string($value) && $value !== '') {
                return true;
            }
        }
        if ($context->databasePathWasGiven()) {
            return true;
        }
        $home = RuntimeFactory::homeDatabasePath();

        return $home !== null && $context->databasePath() === $home;
    }
}
