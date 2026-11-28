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
     */
    public function __construct(
        public string $api,
        public int $line,
        public bool $once,
        public bool|string $assignable,
        public bool $typed,
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

        return new self($api, $line, $once, $assignable, $typed);
    }
}
