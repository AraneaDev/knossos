<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

use Knossos\TestFidelity\{FindingCategory, FindingConfidence, TestFidelityGraph, TestFinding};

/**
 * A double replaces a member declared `private` or `protected`.
 *
 * A worker marks only those two; a member with no visibility is public and
 * is never reported.
 */
final readonly class VisibilityBreachRule extends DriftRule
{
    public const ID = 'test.fidelity.visibility_breach.v1';

    /** Reports a stub whose member is private or protected. */
    protected function judge(DriftPair $pair, TestFidelityGraph $graph): ?TestFinding
    {
        $visibility = $pair->member?->attributes['visibility'] ?? null;
        if ($visibility !== 'private' && $visibility !== 'protected') {
            return null;
        }

        return $pair->finding(
            FindingCategory::VisibilityBreach,
            FindingConfidence::Definite,
            self::ID,
            'visibility',
            sprintf('%s is %s; a double cannot replace it', DriftText::clean($pair->stub->name), $visibility),
            ['visibility' => $visibility],
        );
    }
}
