<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;

/**
 * Which declaration of a PHP class, interface, trait or enum the
 * test-fidelity rules read when more than one file declares its FQCN
 * (G3-11): the PHP worker names every one `php:<kind>:<FQCN>`, so without a
 * choice the first file in scan order would win and the members of every
 * copy would merge under it.
 *
 * The project's own declaration wins over one under `vendor/`. Two project
 * declarations that differ in more than where they stand leave the type
 * unknown, and every declaration is set aside, so nothing is judged against
 * it; alike, the first is read. Only `vendor/` copies leave the first read,
 * as before.
 */
final class PhpTypeDeclarations
{
    /** The node kinds a PHP type declaration has. */
    private const KINDS = ['class', 'interface', 'trait', 'enum'];

    private function __construct() {}

    /**
     * The contributions with every declaration that is not read set aside:
     * its type node, the nodes it contains, and the edges from them.
     *
     * @param list<ScanContribution> $contributions
     * @return list<ScanContribution>
     */
    public static function preferred(array $contributions): array
    {
        $declarations = [];
        foreach ($contributions as $index => $contribution) {
            foreach ($contribution->nodes as $node) {
                if (TestFidelityGraph::isPhp($node) && in_array($node->kind, self::KINDS, true)) {
                    $declarations[strtolower(ltrim($node->canonicalName, '\\'))][$index] = $node;
                }
            }
        }
        $setAside = [];
        foreach ($declarations as $byContribution) {
            if (count($byContribution) < 2) {
                continue;
            }
            $read = self::read($byContribution, $contributions);
            foreach ($byContribution as $index => $node) {
                if ($index !== $read) {
                    $setAside[$index][] = $node->localId;
                }
            }
        }
        foreach ($setAside as $index => $types) {
            $contributions[$index] = self::without($contributions[$index], $types);
        }

        return $contributions;
    }

    /**
     * The contribution whose declaration is read, or null when the type is unknown.
     *
     * @param non-empty-array<int, NodeFact> $byContribution
     * @param list<ScanContribution> $contributions
     */
    private static function read(array $byContribution, array $contributions): ?int
    {
        $own = array_filter($byContribution, static fn(NodeFact $node): bool => !str_starts_with($node->evidence->relativePath, 'vendor/'));
        if ($own === []) {
            return array_key_first($byContribution);
        }
        $shapes = [];
        foreach ($own as $index => $node) {
            $shapes[self::shape($contributions[$index], $node)] = true;
        }

        return count($shapes) === 1 ? array_key_first($own) : null;
    }

    /** What a declaration says, its lines aside: its kind and attributes, those of each node it contains, and its other edges. */
    private static function shape(ScanContribution $contribution, NodeFact $type): string
    {
        $contained = self::contained($contribution, [$type->localId]);
        $members = [];
        foreach ($contribution->nodes as $node) {
            if (isset($contained[$node->localId]) && $node->localId !== $type->localId) {
                $members[$node->localId] = [$node->kind, self::withoutLines($node->attributes)];
            }
        }
        ksort($members, SORT_STRING);
        $relations = [];
        foreach ($contribution->edges as $edge) {
            if ($edge->sourceReference === $type->localId && $edge->kind !== 'contains') {
                $relations[] = $edge->kind . ' ' . $edge->targetReference;
            }
        }
        sort($relations, SORT_STRING);

        return serialize([$type->kind, self::withoutLines($type->attributes), $members, $relations]);
    }

    /**
     * The contribution without the given type nodes, the nodes they contain and the edges from any of them.
     *
     * @param list<string> $types
     */
    private static function without(ScanContribution $contribution, array $types): ScanContribution
    {
        $gone = self::contained($contribution, $types);

        return new ScanContribution(
            $contribution->ownerKey,
            array_values(array_filter($contribution->nodes, static fn(NodeFact $node): bool => !isset($gone[$node->localId]))),
            array_values(array_filter($contribution->edges, static fn($edge): bool => !isset($gone[$edge->sourceReference]))),
            $contribution->diagnostics,
            $contribution->contentHash,
            $contribution->reads,
            $contribution->program,
            $contribution->environment,
            $contribution->listed,
            $contribution->readsPartial,
        );
    }

    /**
     * The types and the references their `contains` edges in the contribution name.
     *
     * @param list<string> $types
     * @return array<string, true>
     */
    private static function contained(ScanContribution $contribution, array $types): array
    {
        $references = array_fill_keys($types, true);
        foreach ($contribution->edges as $edge) {
            if ($edge->kind === 'contains' && in_array($edge->sourceReference, $types, true)) {
                $references[$edge->targetReference] = true;
            }
        }

        return $references;
    }

    /**
     * Attributes with every `line` key dropped, at any depth: where a field stands is no part of what it is.
     *
     * @param array<array-key, mixed> $attributes
     * @return array<array-key, mixed>
     */
    private static function withoutLines(array $attributes): array
    {
        unset($attributes['line']);
        foreach ($attributes as $key => $value) {
            if (is_array($value)) {
                $attributes[$key] = self::withoutLines($value);
            }
        }

        return $attributes;
    }
}
