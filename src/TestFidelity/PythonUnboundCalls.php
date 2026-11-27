<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

use Knossos\Classification\TestModuleRule;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;

/**
 * The Python methods production calls through their class (G4-10), marked
 * `called_unbound` in the graph's view of them.
 *
 * `Repo.save(repo, x)` hands the receiver to `self` as an argument, so a
 * double that replaces `save` on the class records it: a matcher or a
 * `side_effect` written for that call lists one argument more than the
 * signature, which states none for `self`. The worker marks such a `calls`
 * edge `unbound`. Only production counts, as for `PhpCallableReach`: an edge
 * whose source is test code, or a node the contribution does not hold, is
 * left out.
 */
final class PythonUnboundCalls
{
    private function __construct() {}

    /**
     * The nodes with every Python method production calls unbound marked so.
     *
     * @param list<ScanContribution> $contributions
     * @param array<string, NodeFact> $nodes every node by reference
     * @return array<string, NodeFact>
     */
    public static function applied(array $contributions, array $nodes): array
    {
        $tests = new TestModuleRule();
        foreach ($contributions as $contribution) {
            $sources = [];
            foreach ($contribution->nodes as $node) {
                $sources[$node->localId] ??= $node;
            }
            foreach ($contribution->edges as $edge) {
                $source = $sources[$edge->sourceReference] ?? null;
                $method = $nodes[$edge->targetReference] ?? null;
                if ($edge->kind !== 'calls' || ($edge->attributes['unbound'] ?? false) !== true || $source === null || $method === null || $method->kind !== 'method' || !TestFidelityGraph::isPython($method) || $tests->classify($source) !== []) {
                    continue;
                }
                $nodes[$method->localId] = new NodeFact($method->localId, $method->kind, $method->canonicalName, $method->displayName, $method->origin, $method->confidence, $method->evidence, [...$method->attributes, 'called_unbound' => true]);
            }
        }

        return $nodes;
    }
}
