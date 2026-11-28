<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Facts;

/**
 * One assertion on the arguments a double's member was called with, such as `toHaveBeenCalledWith(a, b, c)`.
 */
final readonly class CallMatcher
{
    /**
     * @param int $count how many arguments the matcher lists
     * @param bool $spread whether one of them is a spread, so the real count is open; also set when the keyword list is malformed, because a count holding unnamed keywords cannot be bound
     * @param list<string> $keywords the keyword names among the arguments (Python), empty when the wire states none
     */
    public function __construct(
        public string $api,
        public int $line,
        public int $count,
        public bool $spread,
        public array $keywords = [],
    ) {}

    /**
     * Read one matcher from a decoded attribute block; null when it is malformed.
     */
    public static function fromArray(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }
        $api = $raw['api'] ?? null;
        $line = $raw['line'] ?? null;
        $count = $raw['count'] ?? null;
        $spread = $raw['spread'] ?? null;
        if (!is_string($api) || $api === '' || !is_int($line) || $line < 1 || !is_int($count) || $count < 0 || !is_bool($spread)) {
            return null;
        }

        $keywords = $raw['keywords'] ?? [];
        if (!is_array($keywords) || !array_is_list($keywords) || array_filter($keywords, static fn(mixed $name): bool => !is_string($name) || $name === '') !== []) {
            return new self($api, $line, $count, true);
        }

        return new self($api, $line, $count, $spread, $keywords);
    }
}
