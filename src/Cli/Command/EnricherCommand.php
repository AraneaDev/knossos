<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Discovery\EnricherDefinition;
use Knossos\Discovery\EnrichersFile;

/**
 * Lists, adds and removes the installation's external enrichers, without hand-editing enrichers.json.
 *
 * The file is the one {@see EnrichersFile::forDatabase()} names, so `--db`,
 * `KNOSSOS_DATA_DIR` and `KNOSSOS_ROOTS_FILE` move it exactly as they move
 * `roots.json`. A new entry is checked by the reader's own rules before it is
 * written, so `add` never writes one the next scan would skip, and a file
 * that already has problems is never rewritten: the rewrite would keep only
 * the entries that load, dropping the broken ones without anyone asking.
 * `env` values can be credentials, so `list` shows their names only.
 */
final class EnricherCommand implements CliCommand
{
    private const USAGE = 'Usage: knossos enricher list|add|remove. '
        . 'knossos enricher add <name> [--timeout=N] [--env=NAME=VALUE]... [--kinds=a,b] [--replace] -- <command> [args...]; '
        . 'knossos enricher remove <name>; knossos enricher list [--json]';

    /** The options each action takes, beyond the router's --db. */
    private const ACTION_OPTIONS = [
        'list' => ['db', 'json'],
        'add' => ['db', 'json', 'timeout', 'env', 'kinds', 'replace'],
        'remove' => ['db', 'json'],
    ];

    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return $command === 'enricher';
    }

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return ['db', 'json', 'timeout', 'env', 'kinds', 'replace'];
    }

    /** {@inheritDoc} */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $action = $positionals[0] ?? '';
        if (!isset(self::ACTION_OPTIONS[$action])) {
            throw new InvalidArgumentException(self::USAGE);
        }
        $context->options->validate($options, self::ACTION_OPTIONS[$action]);
        $file = EnrichersFile::forDatabase($context->databasePath());
        $json = $context->options->flag($options, 'json');
        $arguments = array_slice($positionals, 1);

        return match ($action) {
            'list' => $this->list($file, $arguments, $json, $context),
            'add' => $this->add($file, $arguments, $options, $context),
            default => $this->remove($file, $arguments, $json, $context),
        };
    }

    /**
     * Print every definition, then every problem; exit 1 when there are problems.
     *
     * @param list<string> $arguments
     */
    private function list(EnrichersFile $file, array $arguments, bool $json, CliCommandContext $context): int
    {
        if ($arguments !== []) {
            throw new InvalidArgumentException('Usage: knossos enricher list [--db=FILE] [--json]');
        }
        [$definitions, $problems] = $file->load();
        $context->output(
            [
                'enrichers' => array_values(array_map(static fn(EnricherDefinition $definition): array => [
                    'name' => $definition->name,
                    'command' => $definition->command,
                    'timeout_seconds' => $definition->timeoutSeconds,
                    'input_node_kinds' => $definition->inputNodeKinds,
                    'env_keys' => array_map('strval', array_keys($definition->environment)),
                ], $definitions)),
                'problems' => $problems,
            ],
            $json,
            self::listing($file->path, $definitions, $problems),
        );

        return $problems === [] ? CliCommand::EXIT_OK : CliCommand::EXIT_GATE_FAILED;
    }

    /**
     * The text form of `list`.
     *
     * @param array<string, EnricherDefinition> $definitions
     * @param list<string> $problems
     */
    private static function listing(string $path, array $definitions, array $problems): string
    {
        $lines = [$definitions === [] ? sprintf('No enrichers are defined in %s.', $path) : sprintf('Enrichers in %s:', $path)];
        foreach ($definitions as $definition) {
            $lines[] = $definition->name;
            $lines[] = '  command:     ' . implode(' ', array_map(self::quoted(...), $definition->command));
            $lines[] = sprintf('  timeout:     %ds', $definition->timeoutSeconds);
            $lines[] = '  input kinds: ' . ($definition->inputNodeKinds === [] ? '(none)' : implode(', ', $definition->inputNodeKinds));
            $lines[] = '  env:         ' . ($definition->environment === [] ? '(none)' : implode(', ', array_keys($definition->environment)));
        }
        if ($problems !== []) {
            $lines[] = 'Problems:';
            foreach ($problems as $problem) {
                $lines[] = '  ' . $problem;
            }
        }

        return implode(PHP_EOL, $lines);
    }

    /** One argv word as a shell would need it written, so a word with a space reads as one. */
    private static function quoted(string $word): string
    {
        return preg_match('/^[A-Za-z0-9_@%+=:,.\/-]+$/', $word) === 1 ? $word : escapeshellarg($word);
    }

    /**
     * Add one enricher, or replace it in place with --replace.
     *
     * @param list<string> $arguments the name, then the command
     * @param array<string, list<string>> $options
     */
    private function add(EnrichersFile $file, array $arguments, array $options, CliCommandContext $context): int
    {
        $name = $arguments[0] ?? throw new InvalidArgumentException(
            'Usage: knossos enricher add <name> [--timeout=N] [--env=NAME=VALUE]... [--kinds=a,b] [--replace] -- <command> [args...]',
        );
        $definition = self::definition($name, array_slice($arguments, 1), $options, $context);
        $definitions = self::loadForWriting($file);
        $replacing = isset($definitions[$name]);
        if ($replacing && !$context->options->flag($options, 'replace')) {
            throw new InvalidArgumentException(sprintf(
                'An enricher named %s is already defined in %s. Pass --replace to replace it.',
                $name,
                $file->path,
            ));
        }
        $definitions[$name] = $definition;
        $file->write(array_values($definitions));
        $context->output(
            ['name' => $name, 'enrichers_file' => $file->path, 'added' => !$replacing, 'replaced' => $replacing],
            $context->options->flag($options, 'json'),
            sprintf('%s enricher %s %s %s.', $replacing ? 'Replaced' : 'Added', $name, $replacing ? 'in' : 'to', $file->path),
        );

        return CliCommand::EXIT_OK;
    }

    /**
     * The definition the arguments describe, checked by the rules the file is read with.
     *
     * The entry is built in the file's own shape and handed to
     * {@see EnrichersFile::entry()}, so the command and the reader cannot
     * disagree about what is valid. A --timeout that is not a number is
     * passed on as given, and the reader's rule names the range.
     *
     * @param list<string> $command
     * @param array<string, list<string>> $options
     */
    private static function definition(string $name, array $command, array $options, CliCommandContext $context): EnricherDefinition
    {
        $entry = ['name' => $name, 'command' => $command];
        $timeout = $context->options->single($options, 'timeout');
        if ($timeout !== null) {
            $entry['timeout_seconds'] = filter_var($timeout, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? $timeout;
        }
        $environment = [];
        foreach ($context->options->values($options, 'env') as $assignment) {
            $parts = explode('=', $assignment, 2);
            if (count($parts) !== 2) {
                throw new InvalidArgumentException(sprintf('--env takes NAME=VALUE; %s has no =.', $assignment));
            }
            $environment[$parts[0]] = $parts[1];
        }
        $entry['env'] = $environment;
        $kinds = $context->options->single($options, 'kinds');
        $entry['input_node_kinds'] = $kinds === null ? [] : explode(',', $kinds);

        return EnrichersFile::entry($entry, $problem) ?? throw new InvalidArgumentException(sprintf(
            'Not added: %s',
            $problem,
        ));
    }

    /**
     * Remove one enricher; exit 1 when there is none by that name.
     *
     * @param list<string> $arguments
     */
    private function remove(EnrichersFile $file, array $arguments, bool $json, CliCommandContext $context): int
    {
        if (count($arguments) !== 1) {
            throw new InvalidArgumentException('Usage: knossos enricher remove <name> [--db=FILE] [--json]');
        }
        $name = $arguments[0];
        $definitions = self::loadForWriting($file);
        $present = isset($definitions[$name]);
        if ($present) {
            unset($definitions[$name]);
            $file->write(array_values($definitions));
        }
        $context->output(
            ['name' => $name, 'enrichers_file' => $file->path, 'removed' => $present],
            $json,
            $present
                ? sprintf('Removed enricher %s from %s.', $name, $file->path)
                : sprintf('No enricher named %s is defined in %s. Nothing to remove.', $name, $file->path),
        );

        return $present ? CliCommand::EXIT_OK : CliCommand::EXIT_GATE_FAILED;
    }

    /**
     * The file's definitions, refusing when it has problems, since a rewrite would drop the broken entries.
     *
     * @return array<string, EnricherDefinition>
     */
    private static function loadForWriting(EnrichersFile $file): array
    {
        [$definitions, $problems] = $file->load();
        if ($problems !== []) {
            throw new InvalidArgumentException(sprintf(
                "%s has problems, so it is left as it is; rewriting it would drop the entries it skips. Fix them first:\n%s",
                $file->path,
                implode("\n", $problems),
            ));
        }

        return $definitions;
    }
}
