<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

use Knossos\TestFidelity\FindingCategory;
use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\TestFidelity\TestFinding;

/**
 * A double's replacement function declares more parameters than the member it
 * replaces takes: the extra ones are handed nothing on every call.
 *
 * Fewer parameters is ordinary and is not reported. Silent for a cut-short
 * signature, a rest parameter on the production side, or a member without one.
 * Definite when every parameter on both sides is typed; a warning otherwise.
 */
final readonly class ArityMismatchRule extends DriftRule
{
    public const ID = 'test.fidelity.arity_mismatch.v1';

    /** Reports a stub that declares more parameters than its member takes. */
    protected function judge(DriftPair $pair, TestFidelityGraph $graph): ?TestFinding
    {
        $actual = SignatureShape::ofParams($pair->stub->params);
        $declared = $pair->member === null ? null : SignatureShape::ofMember($pair->member->attributes['signature'] ?? null);
        if ($actual === null || $declared === null || $declared->isVariadic() || $actual->fixedCount() <= count($declared->params)) {
            return null;
        }

        return $pair->finding(
            FindingCategory::ArityMismatch,
            $actual->isTyped() && $declared->isTyped() ? FindingConfidence::Definite : FindingConfidence::Warning,
            self::ID,
            'signature',
            sprintf('%s takes %d argument(s); the double passes %d', DriftText::clean($pair->stub->name), count($declared->params), $actual->fixedCount()),
            ['expected' => count($declared->params), 'actual' => $actual->fixedCount()],
        );
    }
}
