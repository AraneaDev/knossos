<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use JsonException;

/**
 * The installation's `enrichers.json`: which external enrichers exist and how to start them.
 *
 * Sits beside `roots.json`, so `KNOSSOS_ROOTS_FILE` moves both. Read
 * tolerantly: a broken file or entry never fails a scan, it only shrinks the
 * set of enrichers and leaves a line in {@see problems()} for the scan to
 * report. There is no writer; the file is edited by hand.
 */
final readonly class EnrichersFile
{
    public function __construct(public string $path) {}

    /** The enrichers file that belongs to a database path, following the roots file's location. */
    public static function forDatabase(string $databasePath): self
    {
        return new self(dirname(AllowedRoots::defaultConfigPath($databasePath)) . '/enrichers.json');
    }

    /**
     * The valid definitions, keyed by name, in file order.
     *
     * @return array<string, EnricherDefinition>
     */
    public function read(): array
    {
        return $this->load()[0];
    }

    /**
     * One line per entry or file that was skipped, naming the entry index.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->load()[1];
    }

    /**
     * Read the file once and sort its entries into definitions and problems.
     *
     * A caller that wants both reads the file once through this method rather
     * than through {@see read()} and {@see problems()}.
     *
     * @return array{array<string, EnricherDefinition>, list<string>} [definitions by name, problem lines]
     */
    public function load(): array
    {
        if (!is_file($this->path)) {
            return [[], []];
        }
        $contents = @file_get_contents($this->path);
        if ($contents === false) {
            return [[], [sprintf('%s could not be read.', $this->path)]];
        }
        try {
            $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            return [[], [sprintf('%s is not valid JSON (%s).', $this->path, $error->getMessage())]];
        }
        $entries = is_array($decoded) ? ($decoded['enrichers'] ?? null) : null;
        if (!is_array($entries) || !array_is_list($entries)) {
            return [[], [sprintf('%s does not have the expected {"enrichers": [...]} shape.', $this->path)]];
        }

        $definitions = [];
        $problems = [];
        foreach ($entries as $index => $entry) {
            $definition = is_array($entry) ? self::definition($entry, $problem) : null;
            if ($definition === null) {
                $problems[] = sprintf('enrichers.json entry %d skipped: %s', $index, $problem ?? 'it is not an object.');
                continue;
            }
            if (isset($definitions[$definition->name])) {
                $problems[] = sprintf('enrichers.json entry %d skipped: the name %s is already defined by an earlier entry.', $index, $definition->name);
                continue;
            }
            $definitions[$definition->name] = $definition;
        }

        return [$definitions, $problems];
    }

    /**
     * Validate one entry; null with a reason in $problem when it is unusable.
     *
     * @param array<mixed> $entry
     */
    private static function definition(array $entry, ?string &$problem): ?EnricherDefinition
    {
        $name = $entry['name'] ?? null;
        if (!is_string($name) || preg_match(EnricherDefinition::NAME_PATTERN, $name) !== 1) {
            $problem = 'name must match ' . EnricherDefinition::NAME_PATTERN . '.';
            return null;
        }
        $command = $entry['command'] ?? null;
        if (!is_array($command) || $command === [] || !array_is_list($command) || self::notAllStrings($command, nonEmpty: true)) {
            $problem = sprintf('command of %s must be a non-empty list of non-empty strings.', $name);
            return null;
        }
        $timeout = $entry['timeout_seconds'] ?? EnricherDefinition::DEFAULT_TIMEOUT_SECONDS;
        if (!is_int($timeout) || $timeout < EnricherDefinition::MIN_TIMEOUT_SECONDS || $timeout > EnricherDefinition::MAX_TIMEOUT_SECONDS) {
            $problem = sprintf('timeout_seconds of %s must be an integer from %d to %d.', $name, EnricherDefinition::MIN_TIMEOUT_SECONDS, EnricherDefinition::MAX_TIMEOUT_SECONDS);
            return null;
        }
        $environment = $entry['env'] ?? [];
        if (!is_array($environment) || self::notAllStrings($environment, nonEmpty: false) || array_is_list($environment) && $environment !== [] || self::badEnvironmentName($environment)) {
            $problem = sprintf('env of %s must be an object of string values with usable names (no empty name, no = or NUL).', $name);
            return null;
        }
        $kinds = $entry['input_node_kinds'] ?? [];
        if (!is_array($kinds) || !array_is_list($kinds) || self::notAllStrings($kinds, nonEmpty: true)) {
            $problem = sprintf('input_node_kinds of %s must be a list of non-empty strings.', $name);
            return null;
        }
        /** @var array{0: non-empty-list<string>, 1: array<string, string>, 2: list<string>} $checked */
        $checked = [$command, $environment, $kinds];

        return new EnricherDefinition($name, $checked[0], $timeout, $checked[1], $checked[2]);
    }

    /**
     * True when an environment variable name is empty or holds `=` or NUL, which no process can receive.
     *
     * @param array<mixed> $environment
     */
    private static function badEnvironmentName(array $environment): bool
    {
        foreach (array_keys($environment) as $key) {
            $key = (string) $key;
            if ($key === '' || str_contains($key, '=') || str_contains($key, "\0")) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when any value is not a string, or is an empty one when that is not allowed.
     *
     * @param array<mixed> $values
     */
    private static function notAllStrings(array $values, bool $nonEmpty): bool
    {
        foreach ($values as $value) {
            if (!is_string($value) || ($nonEmpty && $value === '')) {
                return true;
            }
        }

        return false;
    }
}
