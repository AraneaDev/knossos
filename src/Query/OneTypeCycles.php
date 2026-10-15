<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * Whether a cycle is recursion inside one type rather than a tangle between
 * parts of the architecture: every member is a member of the same class,
 * interface, trait or enum. A tree walk whose methods call each other is the
 * usual case. Both cycle definitions ({@see DependencyCycleQuery} and
 * {@see SnapshotMetrics}) leave such a cycle out, so dependency_cycles and the
 * quality gate agree.
 */
final class OneTypeCycles
{
    /** The kinds whose members a cycle may stay inside. */
    public const TYPE_KINDS = ['class', 'interface', 'trait', 'enum'];

    private function __construct() {}

    /**
     * Whether every member of a cycle of two or more belongs to the same type;
     * a member no type contains (a free function) makes it a real cycle.
     *
     * @param list<string> $members a strongly connected component's member ids
     * @param array<string, string> $declaringType member id => the id of the type that contains it
     */
    public static function withinOneType(array $members, array $declaringType): bool
    {
        if (count($members) < 2) {
            return false;
        }
        $types = [];
        foreach ($members as $member) {
            $type = $declaringType[$member] ?? null;
            if ($type === null) {
                return false;
            }
            $types[$type] = true;
        }

        return count($types) === 1;
    }
}
