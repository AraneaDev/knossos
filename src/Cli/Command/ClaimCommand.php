<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Roots\AllowedRoots;
use Knossos\Roots\RootGuard;
use Knossos\Roots\RootsFile;
use Knossos\Roots\StartClaim;

/**
 * Allows the folder Knossos was started in, once the person has chosen to.
 *
 * Called through a shell wrapper that turns a nonzero exit into silence, so
 * every outcome the pane has to explain, including a roots file that cannot be
 * used, is a status on stdout with exit 0. Only a folder that does not exist
 * is a plain CLI error. Preview by default, like `allow-root`, which writes
 * the same file.
 */
final class ClaimCommand implements CliCommand
{
    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return $command === 'claim';
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
            'Usage: knossos claim <path> [--execute] [--db=FILE] [--json]',
        );
        if (!is_dir($path)) {
            throw new InvalidArgumentException(sprintf('%s is not an existing directory.', $path));
        }
        $json = $context->options->flag($options, 'json');
        $target = StartClaim::targetOf($path, self::home());
        $root = AllowedRoots::normaliseRoot($target['root']);

        if (RootGuard::containerised()) {
            return $this->report($context, $json, 'fixed', $root, 'This server runs in a container, where a host path is not this server\'s path. '
                . 'Mount the folder and allow it from inside.');
        }
        if ($target['ask']) {
            return $this->report($context, $json, 'ask', $root, sprintf('%s is the filesystem root, your home folder or a folder above it, '
                . 'so it is not allowed without being asked.', $root));
        }

        $file = new RootsFile(AllowedRoots::defaultConfigPath($context->databasePath()));
        try {
            $roots = $file->read();
        } catch (InvalidArgumentException $error) {
            return $this->report($context, $json, 'error', $root, $error->getMessage());
        }
        // Only an exact entry counts: a broader root above this one does not make
        // the project's first scan happen, so the toplevel gets a line of its own.
        if (in_array($root, array_map(AllowedRoots::normaliseRoot(...), $roots), true)) {
            return $this->report($context, $json, 'present', $root, null);
        }
        if (!$context->options->flag($options, 'execute')) {
            return $this->report($context, $json, 'would-add', $root, null);
        }
        $roots[] = $root;
        try {
            $file->write($roots);
        } catch (InvalidArgumentException $error) {
            return $this->report($context, $json, 'error', $root, $error->getMessage());
        }

        return $this->report($context, $json, 'added', $root, null);
    }

    /** Print one outcome, as the JSON object the wrapper reads or a line for a person. */
    private function report(CliCommandContext $context, bool $json, string $status, string $root, ?string $reason): int
    {
        $context->output(
            ['status' => $status, 'root' => $root, 'reason' => $reason],
            $json,
            sprintf('%s: %s%s', $status, $root, $reason === null ? '' : ' (' . $reason . ')'),
        );

        return CliCommand::EXIT_OK;
    }

    /** The home folder from the environment, else the account database; null when neither says. */
    private static function home(): ?string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return $home;
        }
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $entry = posix_getpwuid(posix_geteuid());
            if (is_array($entry) && $entry['dir'] !== '') {
                return $entry['dir'];
            }
        }

        return null;
    }
}
