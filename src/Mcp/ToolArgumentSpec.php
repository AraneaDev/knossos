<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use LogicException;

/**
 * One tool's argument contract as {@see ToolCatalog} declares it: each
 * argument's default and, for an integer, its bounds.
 *
 * The single source both front ends read: {@see ToolService} validates an MCP
 * call through it, and the CLI's query commands take their option defaults and
 * bounds from it. Before, each handler restated the numbers as literals, the
 * CLI restated them a second time, and a bound could move on one side only.
 * The schema is what a client reads, so it is the copy the other two follow.
 *
 * Asking for a default or a bound the schema does not declare is a
 * programming error, not a caller's: it throws LogicException, so a handler
 * cannot quietly invent a number the advertised schema does not carry.
 *
 * One exception: `scan_project`'s bounds (max_files, max_file_bytes,
 * snapshot_retention, worker_timeout_ms) stay as literals in
 * {@see ToolService::scan()}, because the schema gives them no default (an
 * absent one leaves the project's knossos.json or built-in setting in force),
 * so this spec has no default to hand out. ToolBoundsAgreementTest pins those literals to the
 * schema's minimum and maximum.
 */
final readonly class ToolArgumentSpec
{
    /** @param array<string, array<string, mixed>> $properties the tool's input schema properties, by name */
    private function __construct(
        public string $tool,
        private array $properties,
    ) {}

    /** The spec of `$tool`, read from the full catalogue (environment tools included). */
    public static function for(string $tool): self
    {
        /** @var array<string, self>|null $specs */
        static $specs = null;
        if ($specs === null) {
            $specs = [];
            foreach (ToolCatalog::definitions() as $definition) {
                $properties = $definition['inputSchema']['properties'] ?? [];
                $specs[$definition['name']] = new self($definition['name'], is_array($properties) ? array_filter($properties, 'is_array') : []);
            }
        }

        return $specs[$tool] ?? throw new LogicException(sprintf('No tool named %s in the catalogue.', $tool));
    }

    /**
     * Every argument the tool declares, in schema order.
     *
     * @return list<string>
     */
    public function properties(): array
    {
        return array_keys($this->properties);
    }

    /**
     * An integer argument's advertised default and bounds.
     *
     * @return array{default: int, minimum: int, maximum: int}
     */
    public function bounds(string $key): array
    {
        $spec = $this->property($key);
        if (($spec['type'] ?? null) !== 'integer' || !isset($spec['default'], $spec['minimum'], $spec['maximum'])) {
            throw new LogicException(sprintf('%s.%s declares no integer default and bounds.', $this->tool, $key));
        }

        return ['default' => (int) $spec['default'], 'minimum' => (int) $spec['minimum'], 'maximum' => (int) $spec['maximum']];
    }

    /** A boolean argument's advertised default. */
    public function booleanDefault(string $key): bool
    {
        $default = $this->property($key)['default'] ?? null;

        return is_bool($default) ? $default : throw new LogicException(sprintf('%s.%s declares no boolean default.', $this->tool, $key));
    }

    /** A string argument's advertised default. */
    public function stringDefault(string $key): string
    {
        $default = $this->property($key)['default'] ?? null;

        return is_string($default) ? $default : throw new LogicException(sprintf('%s.%s declares no string default.', $this->tool, $key));
    }

    /**
     * An integer argument within its advertised bounds, its default when absent.
     *
     * @param array<string, mixed> $arguments
     */
    public function integer(array $arguments, string $key): int
    {
        ['default' => $default, 'minimum' => $minimum, 'maximum' => $maximum] = $this->bounds($key);

        return ToolArguments::integer($arguments, $key, $default, $minimum, $maximum);
    }

    /**
     * A boolean argument, its advertised default when absent.
     *
     * @param array<string, mixed> $arguments
     */
    public function boolean(array $arguments, string $key): bool
    {
        return ToolArguments::boolean($arguments, $key, $this->booleanDefault($key));
    }

    /**
     * A non-empty string argument, its advertised default when absent.
     *
     * @param array<string, mixed> $arguments
     */
    public function string(array $arguments, string $key): string
    {
        return array_key_exists($key, $arguments) ? ToolArguments::string($arguments, $key) : $this->stringDefault($key);
    }

    /**
     * The schema of one declared argument.
     *
     * @return array<string, mixed>
     */
    private function property(string $key): array
    {
        return $this->properties[$key] ?? throw new LogicException(sprintf('%s declares no argument %s.', $this->tool, $key));
    }
}
