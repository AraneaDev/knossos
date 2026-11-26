<?php

declare(strict_types=1);

namespace Knossos\Query\TestFidelity;

use Knossos\TestFidelity\ActiveTestFindings;
use Knossos\TestFidelity\TestRuleCatalog;

/**
 * Stored findings as the items `audit_tests` and `verify_test_doubles` list:
 * one shape, so a finding reads alike in both.
 *
 * @phpstan-import-type Finding from ActiveTestFindings
 * @phpstan-type Item array{id: string, key: string, category: string, confidence: string, rule_id: string, sub_rule: string, message: string, file: ?string, line: ?int, double: ?string, target: ?string, tier: string}
 */
final class ScanTierItems
{
    private function __construct() {}

    /**
     * Each stored finding as an item of the scan tier, in the order given.
     *
     * @param list<Finding> $findings
     * @return list<Item>
     */
    public static function of(array $findings): array
    {
        return array_map(static fn(array $finding): array => [
            'id' => $finding['id'],
            'key' => $finding['key'],
            'category' => $finding['category'],
            'confidence' => $finding['confidence'],
            'rule_id' => $finding['rule_id'],
            'sub_rule' => $finding['sub_rule'],
            'message' => $finding['message'],
            'file' => $finding['file'],
            'line' => $finding['line'],
            'double' => $finding['double_name'],
            'target' => $finding['target_name'],
            'tier' => TestRuleCatalog::SCAN,
        ], $findings);
    }
}
