<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Facts;

use Knossos\Scanner\Protocol\NodeFact;

/**
 * A `test_double` node (a mock, spy, stub or fake) read back into typed form.
 */
final readonly class TestDouble
{
    private const FRAMEWORKS = ['vitest', 'jest'];
    private const TARGET_KINDS = ['module', 'class', 'instance-member', 'global', 'unknown'];

    /**
     * @param array<string, string> $target what the double replaces: `kind`, and when stated `specifier`, `export` and `member`
     * @param list<StubbedMember> $members
     */
    public function __construct(
        public string $canonicalName,
        public string $framework,
        public string $pattern,
        public array $target,
        public bool $automock,
        public int $invocations,
        public ?string $testCase,
        public array $members,
    ) {}

    /**
     * Read a `test_double` node; null for another kind of node or a malformed attribute block, never a throw.
     */
    public static function fromNode(NodeFact $node): ?self
    {
        if ($node->kind !== 'test_double') {
            return null;
        }
        $attributes = $node->attributes;
        $framework = $attributes['framework'] ?? null;
        $pattern = $attributes['pattern'] ?? null;
        $automock = $attributes['automock'] ?? false;
        $invocations = $attributes['invocations'] ?? 0;
        $testCase = $attributes['test_case'] ?? null;
        $rawMembers = $attributes['members'] ?? [];
        if (!is_string($framework) || !in_array($framework, self::FRAMEWORKS, true) || !is_string($pattern) || $pattern === '') {
            return null;
        }
        if (!is_bool($automock) || !is_int($invocations) || $invocations < 0 || ($testCase !== null && !is_string($testCase))) {
            return null;
        }
        $target = self::target($attributes['target'] ?? null);
        if ($target === null || !is_array($rawMembers) || !array_is_list($rawMembers)) {
            return null;
        }
        $members = [];
        foreach ($rawMembers as $rawMember) {
            $member = StubbedMember::fromArray($rawMember);
            if ($member === null) {
                return null;
            }
            $members[] = $member;
        }

        return new self($node->canonicalName, $framework, $pattern, $target, $automock, $invocations, $testCase, $members);
    }

    /**
     * Validate a target block, keeping only the keys the wire names.
     *
     * @return array<string, string>|null
     */
    private static function target(mixed $raw): ?array
    {
        if (!is_array($raw) || !is_string($raw['kind'] ?? null) || !in_array($raw['kind'], self::TARGET_KINDS, true)) {
            return null;
        }
        $target = ['kind' => $raw['kind']];
        foreach (['specifier', 'export', 'member'] as $key) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }
            if (!is_string($raw[$key])) {
                return null;
            }
            $target[$key] = $raw[$key];
        }

        return $target;
    }
}
