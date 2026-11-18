<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

use Knossos\TestFidelity\FindingCategory;
use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\TestFidelity\TestFinding;

/**
 * A double's replacement function requires more parameters than the member it
 * replaces takes: the extra ones are handed nothing on every call.
 *
 * Fewer parameters is ordinary and is not reported, and an optional, defaulted or
 * rest parameter of the double is not required, so it is not counted. Silent for a cut-short
 * signature, an overloaded member, a rest parameter on the production side, or a
 * member without a signature.
 * Definite when every parameter on both sides is typed; a warning otherwise.
 */
final readonly class ArityMismatchRule extends DriftRule
{
    public const ID = 'test.fidelity.arity_mismatch.v1';

    /** Reports a stub that declares more parameters than its member takes. */
    protected function judge(DriftPair $pair, TestFidelityGraph $graph): ?TestFinding
    {
        $actual = SignatureShape::ofParams($pair->stub->params);
        $declared = $pair->member === null ? null : SignatureShape::ofMember($pair->member->attributes);
        if ($actual === null || $declared === null || $declared->isVariadic() || $actual->requiredCount() <= count($declared->params)) {
            return null;
        }

        return $pair->finding(
            FindingCategory::ArityMismatch,
            $actual->isTyped() && $declared->isTyped() ? FindingConfidence::Definite : FindingConfidence::Warning,
            self::ID,
            'signature',
            sprintf('%s takes %d argument(s); the double passes %d', DriftText::clean($pair->stub->name), count($declared->params), $actual->requiredCount()),
            ['expected' => count($declared->params), 'actual' => $actual->requiredCount()],
        );
    }
}
