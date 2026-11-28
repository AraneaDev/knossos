<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Facts;

/**
 * One value a test pins a double's member to, such as `mockReturnValue(5)`.
 */
final readonly class PinnedValue
{
    /**
     * @param bool|string $assignable true, false or "unknown": whether the pinned value fits the member's declared return type
     * @param string|null $type the pinned value's own type as the worker names it, null when it states none
     * @param list<string> $typeAncestors for a class built into the language, the classes and interfaces it extends or implements, as the worker reflected them
     */
    public function __construct(
        public string $api,
        public int $line,
        public bool $once,
        public bool|string $assignable,
        public bool $typed,
        public ?string $type = null,
        public array $typeAncestors = [],
    ) {}

    /**
     * Read one pinned value from a decoded attribute block; null when it is malformed.
     */
    public static function fromArray(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }
        $api = $raw['api'] ?? null;
        $line = $raw['line'] ?? null;
        $once = $raw['once'] ?? null;
        $assignable = $raw['assignable'] ?? null;
        $typed = $raw['typed'] ?? null;
        if (!is_string($api) || $api === '' || !is_int($line) || !is_bool($once) || !is_bool($typed)) {
            return null;
        }
        if (!is_bool($assignable) && $assignable !== 'unknown') {
            return null;
        }

        $type = $raw['type'] ?? null;

        return new self($api, $line, $once, $assignable, $typed, is_string($type) && $type !== '' ? $type : null, self::names($raw['type_ancestors'] ?? null));
    }

    /**
     * A list of non-empty names; empty when it is anything else, so a malformed list says nothing.
     *
     * @return list<string>
     */
    private static function names(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return [];
        }
        foreach ($raw as $name) {
            if (!is_string($name) || $name === '') {
                return [];
            }
        }

        return $raw;
    }
}
