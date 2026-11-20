<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Facts;

/**
 * One member of a test double that the test configures, with the values it pins.
 */
final readonly class StubbedMember
{
    /** How the test named the member: the TypeScript worker's forms, then PHPUnit's, Mockery's and Prophecy's. */
    private const APIS = ['spyOn', 'mockFactoryKey', 'objectLiteralKey', 'instance-member', 'method', 'shouldReceive', 'configured', 'partial', 'prophecy', 'shouldHaveReceived', 'unknown'];

    /**
     * @param list<mixed>|null $params the member's signature parameters as the wire states them
     * @param list<PinnedValue> $returns
     * @param list<CallMatcher> $calledWith the argument-list assertions on the member, empty when the wire states none
     */
    public function __construct(
        public string $name,
        public int $line,
        public string $api,
        public ?array $params,
        public array $returns,
        public array $calledWith = [],
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

        return new self($name, $line, $api, $params, $returns, self::calledWith($raw['called_with'] ?? null));
    }

    /**
     * Read the matchers, dropping any that is malformed so one bad entry never costs the double.
     *
     * @return list<CallMatcher>
     */
    private static function calledWith(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return [];
        }
        $matchers = [];
        foreach ($raw as $rawMatcher) {
            $matcher = CallMatcher::fromArray($rawMatcher);
            if ($matcher !== null) {
                $matchers[] = $matcher;
            }
        }

        return $matchers;
    }
}
