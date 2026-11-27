<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

use Knossos\Scanner\Protocol\NodeFact;
use Knossos\TestFidelity\Facts\Assertion;

/**
 * The user's decision U1 (2026-11-27): a Python finding that rests on a write
 * the worker sees but does not follow, or on a base it cannot read, is a
 * warning, never a definite, and names the pattern as its `residual`.
 *
 * - A ghost or an arity mismatch on a target whose lineage (a module: itself)
 *   lists `unfollowed_writes` (G4-02, G4-05): an alias of the receiver, an
 *   instance written outside the class, a module that writes itself, a nested
 *   class written, a class held as a parameter default, a call named like a
 *   read-only builtin, `builtins.exec`, an assigned frame hack. The graph's
 *   view adds `instance_write` for an instance another file writes.
 * - An arity mismatch on a class with a base the scan does not hold, other than
 *   a builtin, `abc.ABC`, `typing.Protocol`, `typing.Generic`, `enum`'s or
 *   Django's model base (`PythonBases::keepsSignatures()`): its metaclass or
 *   `__init_subclass__` may rewrite the signature (G4-12), as `external_base`.
 * - A `constant` that a local holds, in a scan where a production module reads
 *   a frame's `f_locals` and may so write a caller's locals (G4-08), as
 *   `frame_write`.
 *
 * Anything else keeps its confidence: the downgrade is narrow, by the pattern.
 */
final class PythonResiduals
{
    /** The residual G4-12 names. */
    public const EXTERNAL_BASE = 'external_base';

    /** The residual G4-08 names. */
    public const FRAME_WRITE = 'frame_write';

    private function __construct() {}

    /** The first pattern `$target`'s lineage, or a module itself, lists in `unfollowed_writes`; null when none does. */
    public static function unfollowed(NodeFact $target, TestFidelityGraph $graph): ?string
    {
        foreach ($target->kind === 'module' ? [$target] : $graph->lineage($target) as $type) {
            $reasons = $type->attributes['unfollowed_writes'] ?? null;
            foreach (is_array($reasons) ? $reasons : [] as $reason) {
                if (is_string($reason) && $reason !== '') {
                    return $reason;
                }
            }
        }

        return null;
    }

    /** Whether a class has a base the scan does not hold that may rewrite its methods' signatures (G4-12). */
    public static function rewritingBase(NodeFact $target, TestFidelityGraph $graph): bool
    {
        if ($target->kind !== 'class') {
            return false;
        }
        $found = false;
        $graph->lineageThrough($target, static function (string $parent) use (&$found, $graph): bool {
            $found = $found || (!$graph->has($parent) && !PythonBases::keepsSignatures($parent));

            return true;
        });

        return $found;
    }

    /** Whether one of an assertion's operands is a literal a local holds: its text is not the value it holds. */
    public static function readsLocal(Assertion $assertion): bool
    {
        foreach ($assertion->operands as $operand) {
            if ($operand->source === 'literal' && $operand->value !== null && $operand->value !== $operand->text) {
                return true;
            }
        }

        return false;
    }

    /** A definite finding as a warning that names the residual it rests on; any other finding as it is. */
    public static function downgraded(?TestFinding $finding, ?string $residual): ?TestFinding
    {
        if ($finding === null || $residual === null || $finding->confidence !== FindingConfidence::Definite) {
            return $finding;
        }

        return new TestFinding($finding->doubleOrCaseReference, $finding->targetReference, $finding->category, FindingConfidence::Warning, $finding->ruleId, $finding->subRule, $finding->message, $finding->evidence, [...$finding->attributes, 'residual' => $residual]);
    }
}
