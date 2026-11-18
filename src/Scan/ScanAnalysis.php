<?php

declare(strict_types=1);

namespace Knossos\Scan;

/** Classification, boundary and test-fidelity facts derived from a scanned graph. */
final readonly class ScanAnalysis
{
    /**
     * @param list<object> $classifications
     * @param list<object> $boundaries
     * @param list<string> $warnings what the rules could not resolve, for the scan result to show
     * @param list<\Knossos\TestFidelity\TestFinding> $testFindings what the test-fidelity rules found, sorted
     */
    public function __construct(public array $classifications, public array $boundaries, public array $warnings = [], public array $testFindings = []) {}
}
