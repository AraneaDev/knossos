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
 * Only an installation made by `install.sh` can be updated this way. A source
 * checkout is refused (it is updated with git), and so is a container install
 * for now. `--check --quiet` is what the session-start hook runs in the
 * background: it prints nothing, fails silently, and touches the network at
 * most once a day.
 */
final class SelfUpdateCommand implements CliCommand
{
    /** The exit status of `--check` when a newer release exists. */
    public const EXIT_UPDATE_AVAILABLE = 10;

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
        if ($installation->isSourceCheckout()) {
            throw new InstallException('This is a source checkout: update it with git pull.');
        }
        if ($installation->mode() === 'container') {
            throw new InstallException('This is a container install: container installs are updated by the launcher, not by self-update.');
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
        $context->output(['status' => $result['status'], 'from' => $result['from'], 'to' => $result['to']], $json, $text);

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
