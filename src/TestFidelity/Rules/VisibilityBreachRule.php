<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

use Knossos\TestFidelity\{FindingCategory, FindingConfidence, TestFidelityGraph, TestFinding};

/**
 * A double replaces a member that is not part of its class's public interface.
 *
 * An ECMAScript private member (`#name`, one the target's `private_names`
 * lists) is no property at all, so no double can replace it: definite. A
 * member named by the string `'#name'` is an ordinary public one. A member declared `private` or `protected` is
 * hidden at compile time only, and `vi.spyOn(s as any, 'name')` does replace
 * it at run time, so the double works but reaches past the public interface:
 * a warning. A worker marks only those two keywords; a member with neither
 * and no `#` is public and is never reported.
 *
 * PHP visibility holds at run time: a double cannot replace a `private`
 * method, which for a PHP target is definite. A `protected` one is definite
 * only where the double cannot reach it: under PHPUnit's `createMock()` or
 * `createStub()` for a method that is not abstract, or under Mockery, Pest or
 * Prophecy without `shouldAllowMockingProtectedMethods()`. PHPUnit's other
 * doubles (`createPartialMock()`, `getMockBuilder()`, the abstract and trait
 * doubles) reach protected methods, so they are silent. A visibility a trait
 * use changes (`use T { load as public; }`) is unknown, and silent.
 *
 * Python's worker marks `_x` protected and `__x` (not a dunder) private. A
 * single underscore is a convention only, so it is a warning. A `__x` member
 * is stored name-mangled (`_C__x`), so a double that replaces it under the
 * spelling `__x` raises `AttributeError`: definite, where the target's whole
 * lineage is in the scan with complete name lists. An ancestor outside the
 * project or an open list (a metaclass `__getattr__`) may answer the name, so
 * then it is a warning. The mangled spelling `_C__x` names no member node, so
 * it is never reported.
 */
final readonly class VisibilityBreachRule extends DriftRule
{
    public const ID = 'test.fidelity.visibility_breach.v1';

    /** Reports a stub whose member is `#private`, private or protected. */
    protected function judge(DriftPair $pair, TestFidelityGraph $graph): ?TestFinding
    {
        $name = DriftText::clean($pair->stub->name);
        if (self::isHashPrivate($pair)) {
            return $pair->finding(FindingCategory::VisibilityBreach, FindingConfidence::Definite, self::ID, 'visibility', sprintf('%s is private; a double cannot replace it', $name), ['visibility' => 'private']);
        }
        $visibility = $pair->member?->attributes['visibility'] ?? null;
        if ($visibility !== 'private' && $visibility !== 'protected') {
            return null;
        }
        if (TestFidelityGraph::isPython($pair->target)) {
            return self::python($pair, $graph, $visibility);
        }
        $php = TestFidelityGraph::isPhp($pair->target);
        if ($php && (($visibility === 'protected' && self::reachesProtected($pair)) || $graph->visibilityChanged($pair->target, $pair->stub->name))) {
            return null;
        }

        return $pair->finding(
            FindingCategory::VisibilityBreach,
            $php ? FindingConfidence::Definite : FindingConfidence::Warning,
            self::ID,
            'visibility',
            sprintf('%s is %s; the double bypasses the public interface', $name, $visibility),
            ['visibility' => $visibility],
        );
    }

    /** The finding on a Python `_x` (a warning) or `__x` (definite where nothing unseen may answer the name). */
    private static function python(DriftPair $pair, TestFidelityGraph $graph, string $visibility): ?TestFinding
    {
        $name = DriftText::clean($pair->stub->name);
        if ($visibility === 'protected') {
            return $pair->finding(FindingCategory::VisibilityBreach, FindingConfidence::Warning, self::ID, 'visibility', sprintf('%s is protected; the double bypasses the public interface', $name), ['visibility' => $visibility]);
        }

        return $pair->finding(
            FindingCategory::VisibilityBreach,
            $graph->hasClosedLineage($pair->target) ? FindingConfidence::Definite : FindingConfidence::Warning,
            self::ID,
            'visibility',
            sprintf('%s is name-mangled; a double cannot replace it under that name', $name),
            ['visibility' => $visibility],
        );
    }

    /** Whether a PHP double can replace a protected method at run time: it opted in, or PHPUnit made it any way but a plain mock or stub of a concrete method. */
    private static function reachesProtected(DriftPair $pair): bool
    {
        $double = $pair->double;
        if ($double->allowsProtected) {
            return true;
        }

        return $double->framework === 'phpunit' && (!in_array($double->pattern, ['createMock', 'createStub'], true) || ($pair->member->attributes['abstract'] ?? false) === true);
    }

    /**
     * Whether the stub names an ECMAScript private member (`#name`) of the
     * target: one its `private_names` lists. A member named by the string
     * `'#name'` is spelled the same but is public, so the spelling alone says
     * nothing, and a target that lists no private names has none (G1-27).
     */
    private static function isHashPrivate(DriftPair $pair): bool
    {
        $names = $pair->target->attributes['private_names'] ?? null;

        return str_starts_with($pair->stub->name, '#') && is_array($names) && in_array($pair->stub->name, $names, true);
    }
}
