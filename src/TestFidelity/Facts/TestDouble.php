<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Facts;

use Knossos\Scanner\Protocol\NodeFact;

/**
 * A `test_double` node (a mock, spy, stub or fake) read back into typed form.
 */
final readonly class TestDouble
{
    private const FRAMEWORKS = ['vitest', 'jest', 'phpunit', 'mockery', 'pest', 'prophecy', 'unittest', 'pytest-mock', 'pytest', 'mockall', 'faux', 'mry', 'mockers', 'mockiato', 'mocktopus', 'mock_derive', 'galvanic', 'hand-written'];
    private const TARGET_KINDS = ['module', 'class', 'instance-member', 'global', 'unknown'];

    /**
     * @param array<string, string> $target what the double replaces: `kind`, and when stated `specifier`, `export` and `member`
     * @param list<StubbedMember> $members
     * @param bool $compileChecked true when the language's compiler rejects any mismatch between the double and what it stands in for (Rust); a drift rule must not judge such a double
     * @param list<string>|null $alsoImplements the further classes and interfaces the double is made to be, fully qualified (Mockery's `mock(A::class, B::class)`); null when the wire names some the reader could not read
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
        public bool $allowsProtected = false,
        public ?array $alsoImplements = [],
        public bool $compileChecked = false,
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

        return new self($node->canonicalName, $framework, $pattern, $target, $automock, $invocations, $testCase, $members, ($attributes['allows_protected'] ?? false) === true, self::alsoImplements($attributes), ($attributes['compile_checked'] ?? false) === true);
    }

    /**
     * The `also_implements` list; null when `also_implements_open` says it
     * may be incomplete, or for a malformed one, which keeps the double but
     * leaves unknown what it implements.
     *
     * @param array<string, mixed> $attributes
     * @return list<string>|null
     */
    private static function alsoImplements(array $attributes): ?array
    {
        $raw = $attributes['also_implements'] ?? [];
        if (($attributes['also_implements_open'] ?? false) !== false || !is_array($raw) || !array_is_list($raw) || array_filter($raw, static fn(mixed $name): bool => !is_string($name) || $name === '') !== []) {
            return null;
        }

        return $raw;
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
