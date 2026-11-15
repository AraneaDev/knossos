<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Install\GitHubReleaseSource;
use Knossos\Install\Installation;
use Knossos\Install\InstallException;
use Knossos\Install\ProcessReleaseProbe;
use Knossos\Install\ReleaseAsset;
use Knossos\Install\ReleaseProbe;
use Knossos\Install\ReleaseSource;
use Knossos\Install\SelfUpdater;
use Knossos\Install\UpdateCheck;
use Knossos\Runtime\Version;

/**
 * Updates an installed release, checks for a newer one, or rolls back.
 *
 * Only a native installation made by `install.sh` is updated here. A source
 * checkout is refused (it is updated with git), and so is this command inside
 * a container install's container: there the launcher on the host answers
 * `knossos self-update` itself (tools/launcher/container-update), because the
 * code in a container cannot replace its own image. `--check --quiet` is what
 * the session-start hook runs in the background: it prints nothing, fails
 * silently, and touches the network at most once a day.
 */
final class SelfUpdateCommand implements CliCommand
{
    /** The exit status of `--check` when a newer release exists. */
    public const EXIT_UPDATE_AVAILABLE = 10;

    /** Said after a switch, because a running session goes on with the release it started with. */
    public const RESTART_NOTE = 'A running Claude Code session keeps the previous release loaded until you restart it.';

    /**
     * @param Installation|null $installation defaults to the one this process runs from
     * @param ReleaseSource|null $source defaults to GitHub, or `KNOSSOS_RELEASE_BASE`
     * @param ReleaseProbe|null $probe defaults to running the release's own binary
     */
    public function __construct(
        private readonly ?Installation $installation = null,
        private readonly ?ReleaseSource $source = null,
        private readonly ?ReleaseProbe $probe = null,
    ) {}

    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return $command === 'self-update';
    }

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return ['check', 'rollback', 'version', 'json', 'quiet'];
    }

    /** {@inheritDoc} */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $read = $context->options;
        $check = $read->flag($options, 'check');
        $rollback = $read->flag($options, 'rollback');
        $quiet = $read->flag($options, 'quiet');
        $version = $read->single($options, 'version');
        $json = $read->flag($options, 'json');
        if ($quiet && !$check) {
            throw new InvalidArgumentException('--quiet goes with --check.');
        }
        if (($check && $rollback) || ($version !== null && ($check || $rollback))) {
            throw new InvalidArgumentException('--check, --rollback and --version cannot be combined.');
        }
        $installation = $this->installation ?? new Installation($context->installationRoot());
        // The hook's background call is never a failure and never a lookup it was not meant to make:
        // opted out, a checkout, a container install or an install without a record all end here.
        if ($quiet && (getenv('KNOSSOS_NO_UPDATE_CHECK') === '1' || $installation->isSourceCheckout() || $installation->mode() !== 'native')) {
            return CliCommand::EXIT_OK;
        }
        $installation->assertNotUnrecorded();
        if ($installation->isSourceCheckout()) {
            throw new InstallException('This is a source checkout: update it with git pull.');
        }
        if ($installation->mode() === 'container') {
            throw new InstallException('This is a container install: it is updated by the launcher on the host, so run knossos self-update there, not inside the container.');
        }
        if ($installation->mode() !== 'native') {
            throw new InstallException(sprintf('%s/install.json is missing or unreadable; run the installer again.', $installation->directory()));
        }
        $updater = new SelfUpdater(
            $installation,
            $this->source ?? new GitHubReleaseSource(),
            $this->probe ?? new ProcessReleaseProbe(),
            ReleaseAsset::platform(),
        );

        if ($check) {
            return $this->check($updater, $installation, $quiet, $json, $context);
        }
        $result = $rollback ? $updater->rollback() : $updater->update($version);
        $text = match ($result['status']) {
            'current' => sprintf('Knossos %s is already the latest version.', $result['to']),
            'rolled back' => sprintf('Rolled back from %s to %s.', $result['from'], $result['to']),
            default => sprintf('Updated Knossos from %s to %s.', $result['from'], $result['to']),
        };
        $payload = ['status' => $result['status'], 'from' => $result['from'], 'to' => $result['to']];
        if ($result['status'] !== 'current') {
            // The MCP server and a running watch are long-lived: they keep the code they started with, and a
            // later prune removes it from under them. (--quiet only goes with --check, so it never reaches here.)
            $payload['restart'] = self::RESTART_NOTE;
            $text .= PHP_EOL . self::RESTART_NOTE;
        }
        $context->output($payload, $json, $text);

        return CliCommand::EXIT_OK;
    }

    /** `--check`: look up the newest release, cache it, and say whether it is newer. */
    private function check(SelfUpdater $updater, Installation $installation, bool $quiet, bool $json, CliCommandContext $context): int
    {
        if ($quiet) {
            // The background refresh: at most one look a day, never a message, never a failure.
            if ((new UpdateCheck($installation->directory()))->due()) {
                try {
                    $updater->check(Version::CURRENT);
                } catch (InstallException) {
                }
            }

            return CliCommand::EXIT_OK;
        }
        $result = $updater->check(Version::CURRENT);
        $context->output(
            $result,
            $json,
            $result['update']
                ? sprintf('Knossos %s is available (you have %s): run knossos self-update.', $result['latest'], $result['current'])
                : sprintf('Knossos %s is the latest version.', $result['current']),
        );

        return $result['update'] ? self::EXIT_UPDATE_AVAILABLE : CliCommand::EXIT_OK;
    }
}
