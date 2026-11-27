<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Facts;

use Knossos\Scanner\Protocol\NodeFact;

/**
 * A `test_case` node read back into typed form (named to stay apart from PHPUnit's `TestCase`).
 */
final readonly class TestCaseFact
{
    /**
     * @param list<string> $subjects canonical names of the production modules the test's production calls land in
     * @param list<Assertion> $assertions
     * @param bool $fails whether the runner expects the test to fail (Vitest's `it.fails`), so it passes only when an assertion fails
     */
    public function __construct(
        public string $canonicalName,
        public string $name,
        public int $productionCalls,
        public array $subjects,
        public ?bool $shouldPanic,
        public array $assertions,
        public bool $fails,
    ) {}

    /**
     * Read a `test_case` node; null for another kind of node or a malformed attribute block, never a throw.
     */
    public static function fromNode(NodeFact $node): ?self
    {
        if ($node->kind !== 'test_case') {
            return null;
        }
        $attributes = $node->attributes;
        $name = $attributes['name'] ?? null;
        $calls = $attributes['production_calls'] ?? null;
        $subjects = $attributes['subjects'] ?? null;
        $shouldPanic = $attributes['should_panic'] ?? null;
        $rawAssertions = $attributes['assertions'] ?? null;
        $fails = $attributes['fails'] ?? false;
        if (!is_string($name) || !is_int($calls) || $calls < 0 || ($shouldPanic !== null && !is_bool($shouldPanic)) || !is_bool($fails)) {
            return null;
        }
        if (!is_array($subjects) || !array_is_list($subjects) || !is_array($rawAssertions) || !array_is_list($rawAssertions)) {
            return null;
        }
        foreach ($subjects as $subject) {
            if (!is_string($subject)) {
                return null;
            }
        }
        $assertions = [];
        foreach ($rawAssertions as $rawAssertion) {
            $assertion = Assertion::fromArray($rawAssertion);
            if ($assertion === null) {
                return null;
            }
            $assertions[] = $assertion;
        }

        return new self($node->canonicalName, $name, $calls, $subjects, $shouldPanic, $assertions, $fails);
    }
}
