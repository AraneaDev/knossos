<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

use Knossos\TestFidelity\Facts\PinnedValue;
use Knossos\TestFidelity\FindingCategory;
use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\TestFidelity\TestFinding;

/**
 * A double pins a value the replaced member's return type does not admit.
 *
 * The worker judges assignability; this rule only reads its verdict. Silent
 * for `true` and for `"unknown"`. A `false` the worker reached with both
 * types declared is definite, one resting on an inferred or untyped side a warning.
 * Silent for an overloaded member, whose node holds only one of its signatures.
 */
final readonly class ReturnDriftRule extends DriftRule
{
    public const ID = 'test.fidelity.return_drift.v1';

    /** Reports a stub that pins a value the member's return type refuses. */
    protected function judge(DriftPair $pair, TestFidelityGraph $graph): ?TestFinding
    {
        $pinned = self::worst($pair->stub->returns);
        if ($pair->member === null || $pinned === null || ($pair->member->attributes['overloaded'] ?? false) === true) {
            return null;
        }
        $declared = SignatureShape::ofMember($pair->member->attributes)?->returns;
        $declared ??= 'an inferred type';

        return $pair->finding(
            FindingCategory::ReturnDrift,
            $pinned->typed ? FindingConfidence::Definite : FindingConfidence::Warning,
            self::ID,
            'return_type',
            sprintf('%s returns %s; the double pins a value it does not admit (%s)', DriftText::clean($pair->stub->name), DriftText::clean($declared), DriftText::clean($pinned->api)),
            ['declared' => DriftText::clean($declared), 'api' => DriftText::clean($pinned->api)],
            $pinned->line,
        );
    }

    /**
     * The value that is surely wrong: the first typed one the worker refuses, else the first it refuses.
     *
     * @param list<PinnedValue> $returns
     */
    private static function worst(array $returns): ?PinnedValue
    {
        $refused = array_values(array_filter($returns, static fn(PinnedValue $value): bool => $value->assignable === false));
        foreach ($refused as $value) {
            if ($value->typed) {
                return $value;
            }
        }

        return $refused[0] ?? null;
    }
}
