<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

use Knossos\TestFidelity\TestFidelityGraph;

/**
 * The stubbed members the drift rules can judge: those of a double whose
 * target is a module, class or interface the scan holds inside the project.
 *
 * Where a double replaces something else (a global, an unknown, a function, a
 * package) there is no member list to compare against, so nothing is yielded.
 */
final class DriftScope
{
    /** Target kinds whose contained nodes are the members a double can stub. */
    private const OWNER_KINDS = ['module', 'class', 'interface'];

    /** Names a module double carries that are not exports. */
    private const NOT_MEMBERS = ['default', '__esModule'];

    private function __construct() {}

    /**
     * Every stubbed member of every judgeable double.
     *
     * @return iterable<DriftPair> in the graph's double order, then the double's own member order
     */
    public static function pairs(TestFidelityGraph $graph): iterable
    {
        foreach ($graph->doubles() as $double) {
            $target = $graph->target($double);
            if ($target === null || $graph->isExternal($double) || !in_array($target->kind, self::OWNER_KINDS, true)) {
                continue;
            }
            $node = $graph->nodeOf($double);
            foreach ($double->members as $stub) {
                if (in_array($stub->name, self::NOT_MEMBERS, true)) {
                    continue;
                }
                yield new DriftPair($double, $node, $target, $stub, $graph->member($target, $stub->name));
            }
        }
    }
}
