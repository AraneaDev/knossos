<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;

/**
 * The Python lists another file writes to (G4-03), applied to the graph's
 * view of the nodes they belong to.
 *
 * The Python worker reads one file at a time, so a write from outside a
 * target's own file (`config.DEBUG = True`, `Settings.verbose = True`) adds a
 * name that target's `exports` or `member_names` never holds. The writer's
 * module node lists each in `foreign_writes`, with the name written, or none
 * for `setattr` with a name no literal spells. Any write to a target opens
 * its list, as a write in its own file does: `exports_open` on a module,
 * `member_names_open` on a class, so a reader judges no name on it absent,
 * and a subclass inherits the open list. The def a written name holds may be
 * replaced, so it is read as `decorated`, its signature unknown; a name no
 * literal spells may replace any of them, which marks a class
 * `methods_rewritable` and every function of a module `decorated`.
 *
 * The writer's contribution names the target's file among its reads, so a
 * change to either file is judged again: the graph is built from every
 * contribution, cached ones included. A malformed entry is ignored.
 */
final class PythonForeignWrites
{
    private function __construct() {}

    /**
     * The nodes with every Python target another file writes to opened.
     *
     * @param list<ScanContribution> $contributions
     * @param array<string, NodeFact> $nodes every node by reference
     * @param array<string, list<string>> $children container reference => contained references
     * @return array<string, NodeFact>
     */
    public static function applied(array $contributions, array $nodes, array $children): array
    {
        foreach (self::writes($contributions) as $target => $names) {
            $node = $nodes[$target] ?? null;
            if ($node === null || !TestFidelityGraph::isPython($node) || !in_array($node->kind, ['module', 'class'], true)) {
                continue;
            }
            $module = $node->kind === 'module';
            $nodes[$target] = self::with($node, [$module ? 'exports_open' : 'member_names_open' => true, ...(!$module && isset($names['']) ? ['methods_rewritable' => true] : [])]);
            foreach ($children[$target] ?? [] as $reference) {
                $child = $nodes[$reference] ?? null;
                $written = $child !== null && (isset($names[$child->displayName]) || ($module && isset($names[''])));
                if ($child !== null && $written && in_array($child->kind, ['function', 'method'], true)) {
                    $nodes[$reference] = self::with($child, ['decorated' => true]);
                }
            }
        }

        return $nodes;
    }

    /**
     * Every target another file writes to, with the names written; `''` for a name no literal spells.
     *
     * @param list<ScanContribution> $contributions
     * @return array<string, array<string, true>>
     */
    private static function writes(array $contributions): array
    {
        $writes = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                $listed = $node->attributes['foreign_writes'] ?? null;
                if ($node->kind !== 'module' || !TestFidelityGraph::isPython($node) || !is_array($listed)) {
                    continue;
                }
                foreach ($listed as $entry) {
                    $target = is_array($entry) ? $entry['target'] ?? null : null;
                    $name = is_array($entry) ? $entry['name'] ?? '' : null;
                    if (is_string($target) && str_starts_with($target, 'py:') && is_string($name)) {
                        $writes[$target][$name] = true;
                    }
                }
            }
        }

        return $writes;
    }

    /** @param array<string, mixed> $attributes */
    private static function with(NodeFact $node, array $attributes): NodeFact
    {
        return new NodeFact($node->localId, $node->kind, $node->canonicalName, $node->displayName, $node->origin, $node->confidence, $node->evidence, [...$node->attributes, ...$attributes]);
    }
}
