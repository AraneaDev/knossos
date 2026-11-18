<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

use Knossos\Scanner\Protocol\ScanContribution;
use LogicException;

/**
 * Runs the test-fidelity rules over a scan's facts.
 *
 * Runs on every scan that rebuilds the graph, over every contribution the
 * scan holds, reused ones included: a double in a test file nobody touched
 * still has to be judged again when the code it replaces changes.
 */
final readonly class TestFidelityAnalysis
{
    /** @var list<TestFidelityRule> */
    private array $rules;

    public function __construct(TestFidelityRule ...$rules)
    {
        $this->rules = array_values($rules);
    }

    /**
     * Every finding of every rule, sorted by file, line, then rule id, so an
     * identical workspace gives the same list in the same order.
     *
     * @param list<ScanContribution> $contributions
     * @return list<TestFinding>
     * @throws LogicException when a rule reports a finding under another rule's id
     */
    public function analyze(array $contributions): array
    {
        if ($this->rules === []) {
            return [];
        }
        $graph = TestFidelityGraph::fromContributions($contributions);
        $findings = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->findings($graph) as $finding) {
                if ($finding->ruleId !== $rule->id()) {
                    throw new LogicException(sprintf('Rule %s reported a finding as %s.', $rule->id(), $finding->ruleId));
                }
                $findings[] = $finding;
            }
        }
        usort($findings, self::compare(...));

        return $findings;
    }

    /**
     * File, line and rule id, then what tells two findings of one rule on one
     * line apart, so no order is left to the rules' own.
     */
    private static function compare(TestFinding $a, TestFinding $b): int
    {
        return strcmp($a->evidence->relativePath, $b->evidence->relativePath)
            ?: $a->evidence->startLine <=> $b->evidence->startLine
            ?: strcmp($a->ruleId, $b->ruleId)
            ?: strcmp($a->doubleOrCaseReference, $b->doubleOrCaseReference)
            ?: strcmp($a->subRule, $b->subRule)
            ?: strcmp($a->message, $b->message);
    }
}
