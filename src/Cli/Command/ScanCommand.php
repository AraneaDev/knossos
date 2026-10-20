<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Discovery\EnrichersFile;
use Knossos\Query\LedgeredScanner;

/**
 * `scan`: build or refresh a project's graph, honouring interrupt signals.
 * It is an explicit scan, so it runs the project's external enrichers unless
 * `--no-enrichers` says otherwise.
 * A scan of an existing project is recorded in the scan ledger, so a turn
 * brief whose edits it took in can still report them.
 */
final class ScanCommand implements CliCommand
{
    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return $command === 'scan';
    }

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return ['db', 'json', 'name', 'max-files', 'max-file-bytes', 'boundary', 'mode', 'snapshot-retention', 'worker-timeout-ms', 'worker-memory-mb', 'no-enrichers'];
    }

    /** {@inheritDoc} */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $root = $positionals[0] ?? throw new InvalidArgumentException('Usage: knossos scan <path> [--name=NAME] [--db=PATH] [--json]');
        $enrichers = EnrichersFile::forDatabase($context->databasePath());
        $result = LedgeredScanner::local($context->database(), $context->installationRoot(), [$root], enrichers: $enrichers)->scan(
            $root,
            $context->options->single($options, 'name'),
            isset($options['max-files']) ? $context->options->integer($options, 'max-files', 100_000, 1, 100_000) : null,
            isset($options['max-file-bytes']) ? $context->options->integer($options, 'max-file-bytes', 2_000_000, 1, 100_000_000) : null,
            isset($options['boundary']) ? $context->options->boundaries($context->options->values($options, 'boundary')) : null,
            $context->options->single($options, 'mode'),
            $context->cancellationToken(),
            isset($options['snapshot-retention']) ? $context->options->integer($options, 'snapshot-retention', 5, 0, 20) : null,
            isset($options['worker-timeout-ms']) ? $context->options->integer($options, 'worker-timeout-ms', 30_000, 1_000, 120_000) : null,
            isset($options['worker-memory-mb']) ? $context->options->integer($options, 'worker-memory-mb', 1024, 64, 65536) : null,
            // A plain `knossos scan` is explicit and runs the project's enrichers; the shared watcher's scans opt out.
            !$context->options->flag($options, 'no-enrichers'),
        );
        $context->output($result->jsonSerialize(), $context->options->flag($options, 'json'), $result->summary . "\nProject: " . $result->projectId . "\nSnapshot: " . $result->snapshotId);
        return CliCommand::EXIT_OK;
    }
}
