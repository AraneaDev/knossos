<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use InvalidArgumentException;
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
    /**
     * @param array<string, array<string, mixed>> $properties the tool's input schema properties, by name
     * @param list<string> $required the arguments the schema requires
     */
    private function __construct(
        public string $tool,
        private array $properties,
        private array $required = [],
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
                $specs[$definition['name']] = new self(
                    $definition['name'],
                    is_array($properties) ? array_filter($properties, 'is_array') : [],
                    array_values($definition['inputSchema']['required'] ?? []),
                );
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

    /** The JSON Schema type `$key` declares, such as `integer` or `array`. */
    public function type(string $key): ?string
    {
        $type = $this->property($key)['type'] ?? null;

        return is_string($type) ? $type : null;
    }

    /** The type a list argument's items declare, or null for an argument that is no list. */
    public function itemType(string $key): ?string
    {
        $type = $this->property($key)['items']['type'] ?? null;

        return is_string($type) ? $type : null;
    }

    /** Whether the schema requires `$key`. */
    public function isRequired(string $key): bool
    {
        $this->property($key);

        return in_array($key, $this->required, true);
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
     * One argument read the way its declared schema says, which is how every
     * bound tool reads its arguments ({@see ToolBindings}):
     *
     * - an integer or boolean takes its advertised default and bounds;
     * - a string with a default takes it when absent; one with a maxLength is
     *   counted in characters; an optional one without a default is null
     *   when absent;
     * - a list of strings holds at most its maxItems entries (20 when none is
     *   declared), and a list of objects is a list or absent (null);
     * - an object is an object, an empty one included, or absent (null).
     *
     * @param array<string, mixed> $arguments
     */
    public function read(array $arguments, string $key): mixed
    {
        $spec = $this->property($key);

        return match ($spec['type'] ?? null) {
            'integer' => $this->integer($arguments, $key),
            'boolean' => $this->boolean($arguments, $key),
            'string' => $this->text($arguments, $key, $spec),
            'array' => ($spec['items']['type'] ?? null) === 'string'
                ? ToolArguments::strings($arguments, $key, (int) ($spec['maxItems'] ?? 20))
                : self::objects($arguments, $key),
            'object' => self::object($arguments, $key),
            default => throw new LogicException(sprintf('%s.%s declares no type an argument is read as.', $this->tool, $key)),
        };
    }

    /**
     * A string argument per its schema: its default when it has one, else
     * required, or null when optional and absent; bounded by its maxLength.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $spec
     */
    private function text(array $arguments, string $key, array $spec): ?string
    {
        if (array_key_exists('default', $spec)) {
            return $this->string($arguments, $key);
        }
        if (!array_key_exists($key, $arguments) && !in_array($key, $this->required, true)) {
            return null;
        }

        return isset($spec['maxLength']) ? ToolArguments::text($arguments, $key, (int) $spec['maxLength']) : ToolArguments::string($arguments, $key);
    }

    /**
     * A list of objects, such as policies, or null when absent; the entries
     * are left to the service's own validator.
     *
     * @param array<string, mixed> $arguments
     * @return list<mixed>|null
     */
    private static function objects(array $arguments, string $key): ?array
    {
        $value = $arguments[$key] ?? null;
        if ($value !== null && (!is_array($value) || !array_is_list($value))) {
            throw new InvalidArgumentException(sprintf('%s must be a list.', $key));
        }

        return $value;
    }

    /**
     * An object, such as budgets, or null when absent. An empty JSON object
     * decodes to an empty array, so that one is accepted as an object.
     *
     * @param array<string, mixed> $arguments
     * @return array<mixed>|null
     */
    private static function object(array $arguments, string $key): ?array
    {
        $value = $arguments[$key] ?? null;
        if ($value !== null && (!is_array($value) || ($value !== [] && array_is_list($value)))) {
            throw new InvalidArgumentException(sprintf('%s must be an object.', $key));
        }

        return $value;
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
