<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

use Knossos\TestFidelity\Facts\{TestCaseFact, TestDouble};
use Knossos\TestFidelity\TestFidelityGraph;

/**
 * The tests a double answers to: the one it was made in, or, for a double
 * made outside any test (at the top of the file, in a setup), every test of
 * its file.
 */
final class DoubleTests
{
    private function __construct() {}

    /**
     * The double's tests, in the graph's test order.
     *
     * @return list<TestCaseFact>
     */
    public static function of(TestDouble $double, TestFidelityGraph $graph): array
    {
        $file = self::file($double->canonicalName);
        $tests = [];
        foreach ($graph->testCases() as $case) {
            if ($double->testCase === null ? self::file($case->canonicalName) === $file : $case->canonicalName === $double->testCase) {
                $tests[] = $case;
            }
        }

        return $tests;
    }

    /** The file a test fact's canonical name is in: what comes before its `#`. */
    public static function file(string $canonicalName): string
    {
        return explode('#', $canonicalName)[0];
    }
}
