<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

use InvalidArgumentException;
use Knossos\Scanner\Protocol\Evidence;

/**
 * One thing a test-fidelity rule found wrong with a test double or a test case.
 *
 * Both references are node references as a worker emitted them (a node's
 * local id), resolved onto stored nodes the way a classification's node
 * reference is.
 */
final readonly class TestFinding
{
    /**
     * @param string $doubleOrCaseReference the `test_double` or `test_case` node the finding is about
     * @param ?string $targetReference the production node it was checked against, when there is one
     * @param string $ruleId `test.fidelity.<category in lowercase>.v<N>`
     * @param string $subRule the case within the category, in the Momus and Nemesis lineage (`mock_echo`, `missing_member`)
     * @param string $message what is wrong, in one sentence
     * @param Evidence $evidence where it is, in the test
     * @param array<string, mixed> $attributes what a reader of the finding may want beside the message
     * @throws InvalidArgumentException when a required text is empty, or the rule id does not name the category
     */
    public function __construct(
        public string $doubleOrCaseReference,
        public ?string $targetReference,
        public FindingCategory $category,
        public FindingConfidence $confidence,
        public string $ruleId,
        public string $subRule,
        public string $message,
        public Evidence $evidence,
        public array $attributes = [],
    ) {
        if ($doubleOrCaseReference === '' || $targetReference === '' || $message === '') {
            throw new InvalidArgumentException('A test finding needs its node reference and a message; a target reference, when given, must not be empty.');
        }
        if (preg_match('/^test\.fidelity\.' . preg_quote($category->ruleSegment(), '/') . '\.v[1-9][0-9]*$/D', $ruleId) !== 1) {
            throw new InvalidArgumentException(sprintf('Rule id %s does not name the category %s.', $ruleId, $category->value));
        }
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $subRule) !== 1) {
            throw new InvalidArgumentException('A sub-rule is a lowercase snake_case name.');
        }
    }
}
