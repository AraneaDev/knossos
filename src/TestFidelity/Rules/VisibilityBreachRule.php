<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

use Knossos\TestFidelity\{FindingCategory, FindingConfidence, TestFidelityGraph, TestFinding};

/**
 * A double replaces a member that is not part of its class's public interface.
 *
 * An ECMAScript private member (`#name`) is no property at all, so no double
 * can replace it: definite. A member declared `private` or `protected` is
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
     * Whether the stub names a `#name` member the target declares: a node of
     * that name, or the name in the target's member list (a worker emits no
     * node of its own for an ECMAScript private member).
     */
    private static function isHashPrivate(DriftPair $pair): bool
    {
        if (!str_starts_with($pair->stub->name, '#')) {
            return false;
        }
        $names = $pair->target->attributes['member_names'] ?? null;

        return $pair->member !== null || (is_array($names) && in_array($pair->stub->name, $names, true));
    }
}
