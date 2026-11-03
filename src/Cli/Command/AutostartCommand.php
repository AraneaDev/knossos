<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Roots\AllowedRoots;
use Knossos\Roots\StartClaim;
use Knossos\Runtime\PaneAutostart;

/**
 * Reads or sets whether a project opens the pane when a session starts.
 *
 * The switch lives in `pane-autostart.json` beside the roots file, keyed by
 * the project's toplevel, so any folder inside a repository answers for it.
 */
final class AutostartCommand implements CliCommand
{
    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return $command === 'autostart';
    }

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return ['db', 'json', 'set'];
    }

    /** {@inheritDoc} */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $path = $positionals[0] ?? throw new InvalidArgumentException(
            'Usage: knossos autostart <path> [--set=on|off] [--db=FILE] [--json]',
        );
        if (!is_dir($path)) {
            throw new InvalidArgumentException(sprintf('%s is not an existing directory.', $path));
        }
        $set = $context->options->single($options, 'set');
        if ($set !== null && $set !== 'on' && $set !== 'off') {
            throw new InvalidArgumentException('--set takes on or off.');
        }
        $root = AllowedRoots::normaliseRoot(StartClaim::targetOf($path, null)['root']);
        $autostart = new PaneAutostart(dirname(AllowedRoots::defaultConfigPath($context->databasePath())) . '/pane-autostart.json');
        $json = $context->options->flag($options, 'json');
        if ($set !== null) {
            try {
                $autostart->set($root, $set === 'on');
            } catch (InvalidArgumentException $error) {
                // Called through a wrapper that turns a nonzero exit into silence: the reason is an answer.
                if (!$json) {
                    throw $error;
                }
                $context->output(['status' => 'error', 'root' => $root, 'reason' => $error->getMessage()], true, '');

                return CliCommand::EXIT_OK;
            }
        }
        $on = $autostart->isOn($root);
        $context->output(
            ['status' => 'ok', 'autostart' => $on, 'root' => $root],
            $json,
            sprintf('Pane autostart for %s is %s.', $root, $on ? 'on' : 'off'),
        );

        return CliCommand::EXIT_OK;
    }
}
