<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Facts;

/**
 * One member of a test double that the test configures, with the values it pins.
 */
final readonly class StubbedMember
{
    private const APIS = ['spyOn', 'mockFactoryKey', 'objectLiteralKey', 'instance-member', 'unknown'];

    /**
     * @param list<mixed>|null $params the member's signature parameters as the wire states them
     * @param list<PinnedValue> $returns
     */
    public function __construct(
        public string $name,
        public int $line,
        public string $api,
        public ?array $params,
        public array $returns,
    ) {}

    /**
     * Read one member from a decoded attribute block; null when it is malformed.
     */
    public static function fromArray(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }
        $name = $raw['name'] ?? null;
        $line = $raw['line'] ?? null;
        $api = $raw['api'] ?? null;
        $params = $raw['params'] ?? null;
        $rawReturns = $raw['returns'] ?? [];
        if (!is_string($name) || $name === '' || !is_int($line) || !is_string($api) || !in_array($api, self::APIS, true)) {
            return null;
        }
        if (($params !== null && (!is_array($params) || !array_is_list($params))) || !is_array($rawReturns) || !array_is_list($rawReturns)) {
            return null;
        }
        $returns = [];
        foreach ($rawReturns as $rawReturn) {
            $pinned = PinnedValue::fromArray($rawReturn);
            if ($pinned === null) {
                return null;
            }
            $returns[] = $pinned;
        }

        return new self($name, $line, $api, $params, $returns);
    }
}
