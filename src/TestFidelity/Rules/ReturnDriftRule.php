<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

use Knossos\TestFidelity\Facts\PinnedValue;
use Knossos\TestFidelity\FindingCategory;
use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\PhpTypeFit;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\TestFidelity\TestFinding;

/**
 * A double pins a value the replaced member's return type does not admit.
 *
 * The worker judges assignability; this rule only reads its verdict. Silent
 * for `true` and for `"unknown"`. A `false` the worker reached with both
 * types declared is definite, one resting on an inferred or untyped side a warning.
 * Silent for a member with no signature, a cut-short one, or an overloaded one,
 * whose node holds only one of its signatures. Only a whole signature that
 * declares no return type is called inferred.
 *
 * The PHP worker has no type checker: it leaves every pin `"unknown"` with
 * the pinned value's own `type`, and `PhpTypeFit` judges the fit here, for a
 * PHP target only. A refusal it can show on both sides is definite, one that
 * rests on a relation outside the project a warning; a PHP method with no
 * declared return type is never judged. Only a PHPUnit double returns its
 * value under strict types; Mockery, Pest and Prophecy let PHP coerce it.
 * `self` and `static` name the declaring class, or the doubled class when a
 * trait declares the method. The double itself pinned on a `void` method
 * (`willReturnSelf()`) breaks nothing at run time, so it is a warning.
 */
final readonly class ReturnDriftRule extends DriftRule
{
    public const ID = 'test.fidelity.return_drift.v1';

    /** The PHP type kinds `self` and `static` may name. */
    private const CLASS_KINDS = ['class', 'interface'];

    /** The PHP apis that pin the double itself as a member's return. */
    private const SELF_APIS = ['willReturnSelf', 'andReturnSelf'];

    /** Reports a stub that pins a value the member's return type refuses. */
    protected function judge(DriftPair $pair, TestFidelityGraph $graph): ?TestFinding
    {
        // A missing, truncated or overloaded signature says nothing reliable about the return type.
        $signature = $pair->member === null ? null : SignatureShape::ofMember($pair->member->attributes);
        if ($signature === null || $pair->member === null) {
            return null;
        }
        [$pinned, $typed] = self::worst(self::refused($pair->stub->returns, $signature->returns, $pair, $graph));
        if ($pinned === null) {
            return null;
        }
        $declared = $signature->returns ?? 'an inferred type';

        return $pair->finding(
            FindingCategory::ReturnDrift,
            $typed ? FindingConfidence::Definite : FindingConfidence::Warning,
            self::ID,
            'return_type',
            sprintf('%s returns %s; the double pins a value it does not admit (%s)', DriftText::clean($pair->stub->name), DriftText::clean($declared), DriftText::clean($pinned->api)),
            ['declared' => DriftText::clean($declared), 'api' => DriftText::clean($pinned->api)],
            $pinned->line,
        );
    }

    /**
     * The pinned values the member's return type refuses, each with whether
     * the refusal rests on declared types: the worker's verdict, or for a PHP
     * target whose worker left it unknown, `PhpTypeFit`'s.
     *
     * @param list<PinnedValue> $returns
     * @return list<array{PinnedValue, bool}>
     */
    private static function refused(array $returns, ?string $declared, DriftPair $pair, TestFidelityGraph $graph): array
    {
        $php = TestFidelityGraph::isPhp($pair->target);
        $refused = [];
        foreach ($returns as $value) {
            if ($value->assignable === false) {
                $refused[] = [$value, $value->typed];
            } elseif ($php && $value->assignable === 'unknown' && $value->type !== null) {
                $fit = PhpTypeFit::judge($value->type, $declared, $graph, self::selfClass($pair, $graph), $pair->double->framework === 'phpunit');
                if ($fit['fits'] === false) {
                    $refused[] = [$value, $fit['typed'] && !self::isSelfOnVoid($value, $declared)];
                }
            }
        }

        return $refused;
    }

    /**
     * Whether a pin makes a `void` method return the double itself
     * (`willReturnSelf()`, `andReturnSelf()`): no framework fails on it, as
     * the method's generated body returns nothing, so it is only a warning
     * (Ruling R18).
     */
    private static function isSelfOnVoid(PinnedValue $value, ?string $declared): bool
    {
        return in_array($value->api, self::SELF_APIS, true) && strtolower(ltrim((string) $declared, '\\')) === 'void';
    }

    /**
     * The class `self` and `static` name in a PHP method: the class or
     * interface declaring it (its canonical name up to `::`). A trait, or an
     * owner the scan does not hold, is no class: then the doubled class, which
     * uses it, and null when the double replaces no class or interface.
     */
    private static function selfClass(DriftPair $pair, TestFidelityGraph $graph): ?string
    {
        $owner = strstr($pair->member->canonicalName ?? '', '::', true);
        $declaring = $owner === false || $owner === '' ? null : $graph->phpType($owner);
        if ($declaring !== null && in_array($declaring->kind, self::CLASS_KINDS, true)) {
            return $declaring->canonicalName;
        }

        return in_array($pair->target->kind, self::CLASS_KINDS, true) ? $pair->target->canonicalName : null;
    }

    /**
     * The value that is surely wrong: the first refused on declared types, else the first refused.
     *
     * @param list<array{PinnedValue, bool}> $refused
     * @return array{PinnedValue|null, bool}
     */
    private static function worst(array $refused): array
    {
        foreach ($refused as [$value, $typed]) {
            if ($typed) {
                return [$value, true];
            }
        }

        return [$refused[0][0] ?? null, false];
    }
}
