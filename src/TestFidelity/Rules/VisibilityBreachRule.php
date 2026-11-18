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

        return $pair->finding(
            FindingCategory::VisibilityBreach,
            FindingConfidence::Warning,
            self::ID,
            'visibility',
            sprintf('%s is %s; the double bypasses the public interface', $name, $visibility),
            ['visibility' => $visibility],
        );
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
