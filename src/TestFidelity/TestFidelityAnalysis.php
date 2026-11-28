<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

use InvalidArgumentException;
use Knossos\Scanner\Protocol\ScanContribution;
use LogicException;
use Throwable;

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
     * The ids of the registered rules, sorted: what a stored graph's findings
     * were judged by, so a scan can tell when the rule set changed.
     *
     * @return list<string>
     */
    public function ruleIds(): array
    {
        $ids = array_map(static fn(TestFidelityRule $rule): string => $rule->id(), $this->rules);
        sort($ids, SORT_STRING);

        return $ids;
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
        return $this->report($contributions)->findings;
    }

    /**
     * The findings, less what names nodes no contribution emitted.
     *
     * A finding about a double or test case the scan does not hold is
     * dropped; one whose target the scan does not hold is kept without it.
     * Either is counted in a warning rather than thrown: findings are derived
     * data, and one bad fact must not fail a scan.
     *
     * A rule that throws on a scan's facts loses its findings for that scan and
     * is named in a warning, whatever it threw except a core logic error.
     *
     * @param list<ScanContribution> $contributions
     * @throws LogicException when a rule reports a finding under another rule's id
     */
    public function report(array $contributions): TestFidelityReport
    {
        // Most scans hold no test fact at all: no rule then has anything to read.
        if ($this->rules === [] || !self::holdsTestFacts($contributions)) {
            return new TestFidelityReport([]);
        }
        $graph = TestFidelityGraph::fromContributions($contributions);
        $findings = $dropped = $untargeted = $failed = [];
        foreach ($this->rules as $rule) {
            try {
                $produced = [...$rule->findings($graph)];
            } catch (LogicException $error) {
                // A programming error in the core surfaces; only a bad fact a rule trips over (InvalidArgumentException) is contained.
                if (!$error instanceof InvalidArgumentException) {
                    throw $error;
                }
                $failed[$rule->id()] = 1;
                continue;
            } catch (Throwable) {
                $failed[$rule->id()] = 1;
                continue;
            }
            foreach ($produced as $finding) {
                if ($finding->ruleId !== $rule->id()) {
                    throw new LogicException(sprintf('Rule %s reported a finding as %s.', $rule->id(), $finding->ruleId));
                }
                if (!$graph->has($finding->doubleOrCaseReference)) {
                    $dropped[$finding->ruleId] = ($dropped[$finding->ruleId] ?? 0) + 1;
                    continue;
                }
                if ($finding->targetReference !== null && !$graph->has($finding->targetReference)) {
                    $untargeted[$finding->ruleId] = ($untargeted[$finding->ruleId] ?? 0) + 1;
                    $finding = new TestFinding($finding->doubleOrCaseReference, null, $finding->category, $finding->confidence, $finding->ruleId, $finding->subRule, $finding->message, $finding->evidence, $finding->attributes);
                }
                $findings[] = $finding;
            }
        }
        ksort($failed, SORT_STRING);
        usort($findings, self::compare(...));

        return new TestFidelityReport($findings, [
            ...self::warning('TEST_FINDING_DROPPED', $dropped, 'named a test double or test case not in the scan and were dropped'),
            ...($failed === [] ? [] : [sprintf('TEST_FINDING_RULE_FAILED: %d test-fidelity rule(s) failed on this scan\'s facts and their findings were dropped (%s).', count($failed), implode(', ', array_keys($failed)))]),
            ...self::warning('TEST_FINDING_TARGET_UNRESOLVED', $untargeted, 'named a target not in the scan and were kept without one'),
        ]);
    }

    /**
     * Whether any contribution emits a test double or a test case node,
     * well formed or not.
     *
     * @param list<ScanContribution> $contributions
     */
    private static function holdsTestFacts(array $contributions): bool
    {
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                if ($node->kind === 'test_double' || $node->kind === 'test_case') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * One warning line for a tally of findings by rule id, or none.
     *
     * @param array<string, int> $byRule
     * @return list<string>
     */
    private static function warning(string $code, array $byRule, string $what): array
    {
        if ($byRule === []) {
            return [];
        }
        ksort($byRule, SORT_STRING);

        return [sprintf('%s: %d test-fidelity finding(s) %s (%s).', $code, array_sum($byRule), $what, implode(', ', array_keys($byRule)))];
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
