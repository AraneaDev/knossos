<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\TestFidelity\Facts\StubbedMember;
use Knossos\TestFidelity\Facts\TestDouble;
use Knossos\TestFidelity\FindingCategory;
use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\TestFinding;

/**
 * One member a double stubs, with the production node the double replaces and
 * the node of that name the target holds, when it holds one.
 *
 * What the four drift rules share: the pairing, the evidence at the stub's
 * line, and a finding built only from text that has been made safe.
 */
final readonly class DriftPair
{
    public function __construct(
        public TestDouble $double,
        public NodeFact $doubleNode,
        public NodeFact $target,
        public StubbedMember $stub,
        public ?NodeFact $member,
    ) {}

    /**
     * A finding about this stub, or null when its text leaves nothing to say.
     *
     * The message is built from worker text, so it is cleaned here: control
     * characters become spaces and a long name is cut.
     *
     * @param array<string, mixed> $attributes
     */
    public function finding(FindingCategory $category, FindingConfidence $confidence, string $ruleId, string $subRule, string $message, array $attributes = [], ?int $line = null): ?TestFinding
    {
        $message = DriftText::clean($message);
        if ($message === '') {
            return null;
        }
        $at = $line ?? $this->stub->line;
        $evidence = $at >= 1 ? new Evidence($this->doubleNode->evidence->relativePath, $at, $at) : $this->doubleNode->evidence;

        return new TestFinding($this->doubleNode->localId, $this->target->localId, $category, $confidence, $ruleId, $subRule, $message, $evidence, [
            'member' => DriftText::clean($this->stub->name),
            ...$attributes,
        ]);
    }
}
