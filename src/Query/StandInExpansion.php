<?php

declare(strict_types=1);

namespace Knossos\Query;

use PDO;

/**
 * The nodes a dependant walk reads through in place of the node it reached.
 *
 * Some dependencies are recorded against another node than the one they
 * reach. A call through an interface or a parent goes to the supertype's
 * method, so what calls that may run this one. Nothing points at a property:
 * its class's methods read it, so what uses the class may use it. Constructing
 * a class runs its constructor, and the graph records that against the class.
 * Impact analysis and the test reach search both read dependants through these
 * stand-ins.
 */
final class StandInExpansion
{
    /**
     * The stand-ins of a set of nodes, each paired with the node it stands in for.
     *
     * `through` holds the supertype methods, then the property owners: their
     * dependants are read with the walk's own edge kinds. `constructed` holds
     * the classes whose construction runs a constructor, and only when the walk
     * reads construction edges at all: through them only those edges count, as
     * a type in a signature constructs nothing.
     *
     * @param list<string> $ids
     * @param list<string> $edgeKinds the walk's edge kinds
     * @return array{through: list<array{0: string, 1: string}>, constructed: list<array{0: string, 1: string}>}
     *         each entry is [stand-in id, id it stands in for]
     */
    public static function of(PDO $pdo, string $projectId, array $ids, array $edgeKinds): array
    {
        $through = [];
        foreach (SupertypeMethods::of($pdo, $projectId, $ids) as $method => $supertypes) {
            foreach ($supertypes as $supertype) {
                $through[] = [$supertype, (string) $method];
            }
        }
        foreach (PropertyOwners::of($pdo, $projectId, $ids) as $property => $class) {
            $through[] = [$class, (string) $property];
        }
        $constructed = [];
        if (in_array(ConstructedClasses::EDGE_KIND, $edgeKinds, true)) {
            foreach (ConstructedClasses::of($pdo, $projectId, $ids) as $constructor => $classes) {
                foreach ($classes as $class) {
                    $constructed[] = [$class, (string) $constructor];
                }
            }
        }

        return ['through' => $through, 'constructed' => $constructed];
    }

    /**
     * An edge read through a stand-in, probable at best: which implementation
     * runs, or which use touches a property, is a guess.
     *
     * @param array<string, mixed> $edge
     * @return array<string, mixed>
     */
    public static function downgradeCertain(array $edge): array
    {
        return $edge['confidence'] === 'certain' ? ['confidence' => 'probable'] + $edge : $edge;
    }
}
