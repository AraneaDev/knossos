<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

/**
 * One check over a scan's test doubles and test cases.
 *
 * A rule reads facts only, never the project's files, and reports nothing
 * where the facts run out: an unknown type or an unresolvable external target
 * is silence, not a finding.
 */
interface TestFidelityRule
{
    /** The id every finding of this rule carries: `test.fidelity.<category in lowercase>.v<N>`. */
    public function id(): string;

    /**
     * What this rule finds in the scan's graph, in any order.
     *
     * @return iterable<TestFinding>
     */
    public function findings(TestFidelityGraph $graph): iterable;
}
