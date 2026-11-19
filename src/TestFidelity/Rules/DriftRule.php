<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\TestFidelity\TestFidelityRule;
use Knossos\TestFidelity\TestFinding;

/**
 * A rule that judges each member a double stubs, one finding at most for each.
 *
 * A subclass names its id in `ID` and says what is wrong with one pair, or
 * nothing when the facts run out.
 */
abstract readonly class DriftRule implements TestFidelityRule
{
    /** The id every finding of the rule carries; each rule gives its own. */
    public const ID = '';

    /** The id every finding of this rule carries. */
    final public function id(): string
    {
        return static::ID;
    }

    /**
     * What this rule finds in the scan's graph: its verdict on every stubbed member in scope.
     *
     * @return iterable<TestFinding>
     */
    public function findings(TestFidelityGraph $graph): iterable
    {
        foreach (DriftScope::pairs($graph) as $pair) {
            $finding = $this->judge($pair, $graph);
            if ($finding !== null) {
                yield $finding;
            }
        }
    }

    /** What is wrong with one stubbed member, or null when nothing is or the evidence runs out. */
    abstract protected function judge(DriftPair $pair, TestFidelityGraph $graph): ?TestFinding;
}
