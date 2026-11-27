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
     * @param bool $spread whether one of them is a spread, so the real count is open
     * @param list<string> $keywords the keyword names among the arguments (Python), empty when the wire states none
     * @param bool $negated whether the matcher is read through `.not`, so it holds that no call lists those arguments
     */
    public function __construct(
        public string $api,
        public int $line,
        public int $count,
        public bool $spread,
        public array $keywords = [],
        public bool $negated = false,
    ) {}

    /**
     * Read one matcher from a decoded attribute block; null when it is malformed: a keyword list that is not a list of names, or longer than the count, cannot be bound.
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
        $negated = $raw['negated'] ?? false;
        if (!is_string($api) || $api === '' || !is_int($line) || $line < 1 || !is_int($count) || $count < 0 || !is_bool($spread) || !is_bool($negated)) {
            return null;
        }

        $keywords = array_key_exists('keywords', $raw) ? $raw['keywords'] : [];
        if (!is_array($keywords) || !array_is_list($keywords) || array_filter($keywords, static fn(mixed $name): bool => !is_string($name) || $name === '') !== [] || count($keywords) > $count) {
            return null;
        }

        return new self($api, $line, $count, $spread, $keywords, $negated);
    }
}
