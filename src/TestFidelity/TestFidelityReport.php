<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

/** What the test-fidelity rules found in one scan, and what they reported that could not be kept. */
final readonly class TestFidelityReport
{
    /**
     * @param list<TestFinding> $findings sorted by file, line, then rule id
     * @param list<string> $warnings findings dropped or kept without a target, for the scan result to show
     */
    public function __construct(public array $findings, public array $warnings = []) {}
}
