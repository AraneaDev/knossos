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
 *
 * A call matcher that lists more arguments than the member takes, or, where the
 * matcher is exact, fewer than it requires, is a `call_arity` finding. The count
 * is syntax on both sides, so it is definite; a spread matcher is silent. A
 * PHP method production hands to a call as a callable argument
 * (`array_walk($xs, [$repo, 'save'])`, a `calls` edge marked `callable`
 * whose source is no test code, `PhpCallableReach`) through the target or a type it inherits
 * from may be called with more arguments than it takes, so neither more
 * matcher arguments nor more replacement parameters are reported for it,
 * though too few still are (G3-31). A
 * Python matcher is bound by keyword name and parameter kind as well
 * (`PythonCallBinding`), definite with or without `autospec` (the user's
 * decision), and silent for a decorated member, whose signature a decorator
 * may have replaced. Accepted residual: a Python call made unbound,
 * `Repo.save(repo, x)`, records the receiver, so a matcher written for it
 * reads as one argument too many. So is a rebind from another file
 * (`Repo.save = wrap(Repo.save)` in another module), which no worker sees: the
 * def's signature is then judged for a name that holds another (the residual
 * GHOST_MEMBER records for cross-file writes), and so is a package rebinding a
 * submodule's function (`m.f = partial(m.f, 1)` in `pkg/__init__.py`). Silent
 * for a Python class whose lineage carries `methods_rewritable` (a project
 * metaclass, an `__init_subclass__`, a class decorator or call that may
 * replace its methods; Ruling P21). Residual (G4-12): a base outside the
 * project is never taken to rewrite, though its metaclass may. Silent, too,
 * where Python's lookup may answer the name with another binding than the
 * def found (Ruling P24, `TestFidelityGraph::answersCertainly()`): another
 * class of the lineage lists the name, or the def is inherited past a base
 * outside the project.
 */
final readonly class ArityMismatchRule extends DriftRule
{
    public const ID = 'test.fidelity.arity_mismatch.v1';

    /** Reports a stub that declares more parameters than its member takes, or a matcher that lists a count it cannot meet. */
    protected function judge(DriftPair $pair, TestFidelityGraph $graph): ?TestFinding
    {
        $declared = $pair->member === null ? null : SignatureShape::ofMember($pair->member->attributes);
        // Ruling P21: a Python class whose lineage may rewrite its methods states no signature a call must meet;
        // Ruling P24: nor does a def another binding of the lineage, or a base outside the project, may come before.
        if (TestFidelityGraph::isPython($pair->target) && $graph->hasRewritableLineage($pair->target)) {
            return null;
        }
        if ($pair->member !== null && !$graph->answersCertainly($pair->target, $pair->member)) {
            return null;
        }
        if ($declared === null) {
            return null;
        }
        $takesMore = self::reachedAsCallable($pair, $graph);

        return $this->signature($pair, $declared, $takesMore) ?? $this->calls($pair, $declared, $takesMore);
    }

    /** Whether production hands the stubbed PHP method on as a callable value through the target or a type it inherits from (G3-31). */
    private static function reachedAsCallable(DriftPair $pair, TestFidelityGraph $graph): bool
    {
        if (!TestFidelityGraph::isPhp($pair->target)) {
            return false;
        }
        $classes = $graph->callableClasses($pair->stub->name);
        foreach ($graph->lineage($pair->target) as $type) {
            if (in_array(strtolower(ltrim($type->canonicalName, '\\')), $classes, true)) {
                return true;
            }
        }

        return false;
    }

    /** A call matcher on the member lists a count the member cannot meet. */
    private function calls(DriftPair $pair, SignatureShape $declared, bool $takesMore): ?TestFinding
    {
        $violation = CallArity::violation($pair->stub->calledWith, $declared, DriftText::clean($pair->stub->name), $pair->double->framework, TestFidelityGraph::isPython($pair->target), $takesMore);

        return $violation === null ? null : $pair->finding(
            FindingCategory::ArityMismatch,
            FindingConfidence::Definite,
            self::ID,
            'call_arity',
            $violation['message'],
            ['expected' => $violation['expected'], 'actual' => $violation['matcher']->count, 'matcher' => $violation['matcher']->api],
        );
    }

    /** The double declares more required parameters than the member takes. */
    private function signature(DriftPair $pair, SignatureShape $declared, bool $takesMore): ?TestFinding
    {
        $actual = SignatureShape::ofParams($pair->stub->params);
        if ($takesMore || $actual === null || $declared->isVariadic() || $actual->requiredCount() <= count($declared->params)) {
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
