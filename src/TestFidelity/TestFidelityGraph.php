<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\TestFidelity\Facts\TestCaseFact;
use Knossos\TestFidelity\Facts\TestDouble;

/**
 * The part of a scan's facts the test-fidelity rules read: every test double
 * and test case, the production node each double replaces, and what that node
 * contains.
 *
 * Built once per scan from the whole set of contributions, cached ones
 * included, so a double in a test file the scan did not reparse is judged
 * against its target as the target is now. Edges are matched by the node
 * references the worker emitted, before reconciliation resolves them: a
 * `doubles` edge whose target is not among the contributions has no target
 * here.
 */
final readonly class TestFidelityGraph
{
    /**
     * @param array<string, NodeFact> $nodes every node by reference; the first contribution to emit one wins
     * @param array<string, NodeFact> $factNodes the test double and test case nodes, by canonical name
     * @param list<TestDouble> $doubles
     * @param list<TestCaseFact> $testCases
     * @param array<string, string> $targets double reference => target reference
     * @param array<string, list<string>> $children container reference => contained references
     */
    private function __construct(
        private array $nodes,
        private array $factNodes,
        private array $doubles,
        private array $testCases,
        private array $targets,
        private array $children,
    ) {}

    /**
     * Index a scan's contributions.
     *
     * A malformed test fact is left out rather than thrown on: one worker's
     * bad fact must not fail a scan.
     *
     * @param list<ScanContribution> $contributions
     */
    public static function fromContributions(array $contributions): self
    {
        $nodes = $factNodes = $doubles = $testCases = $targets = $children = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                if (isset($nodes[$node->localId])) {
                    continue;
                }
                $nodes[$node->localId] = $node;
                if (isset($factNodes[$node->canonicalName])) {
                    continue;
                }
                if (($double = TestDouble::fromNode($node)) !== null) {
                    $doubles[$node->canonicalName] = $double;
                    $factNodes[$node->canonicalName] = $node;
                } elseif (($case = TestCaseFact::fromNode($node)) !== null) {
                    $testCases[$node->canonicalName] = $case;
                    $factNodes[$node->canonicalName] = $node;
                }
            }
            foreach ($contribution->edges as $edge) {
                if ($edge->kind === 'doubles') {
                    $targets[$edge->sourceReference] ??= $edge->targetReference;
                } elseif ($edge->kind === 'contains') {
                    $children[$edge->sourceReference][] = $edge->targetReference;
                }
            }
        }
        ksort($doubles, SORT_STRING);
        ksort($testCases, SORT_STRING);

        return new self($nodes, $factNodes, array_values($doubles), array_values($testCases), $targets, $children);
    }

    /**
     * Every test double, by canonical name.
     *
     * @return list<TestDouble>
     */
    public function doubles(): array
    {
        return $this->doubles;
    }

    /**
     * Every test case, by canonical name.
     *
     * @return list<TestCaseFact>
     */
    public function testCases(): array
    {
        return $this->testCases;
    }

    /** The node a double or test case was read from: its reference, display name and evidence. */
    public function nodeOf(TestDouble|TestCaseFact $fact): NodeFact
    {
        return $this->factNodes[$fact->canonicalName];
    }

    /** The production node a double's `doubles` edge points at, or null when it has none in this scan. */
    public function target(TestDouble $double): ?NodeFact
    {
        $reference = $this->targets[$this->nodeOf($double)->localId] ?? null;

        return $reference === null ? null : ($this->nodes[$reference] ?? null);
    }

    /**
     * The node `$owner` contains under `$name`: a class's method or property,
     * a module's declaration. Declared members only; inherited ones are not
     * followed.
     */
    public function member(NodeFact $owner, string $name): ?NodeFact
    {
        foreach ($this->children[$owner->localId] ?? [] as $reference) {
            $child = $this->nodes[$reference] ?? null;
            if ($child !== null && $child->displayName === $name) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Whether what a double replaces lies outside the project, where its
     * members cannot be checked.
     *
     * True when the target node is marked external, or when the double has
     * no target and names a bare package specifier (`axios`, `@scope/pkg`,
     * `node:fs`). A relative or absolute path, or a path alias (`@/…`, `~/…`,
     * `#…`), is inside the project even when it does not resolve, which is
     * what makes an unresolved one a ghost rather than a package.
     */
    public function isExternal(TestDouble $double): bool
    {
        $target = $this->target($double);
        if ($target !== null) {
            return ($target->attributes['external'] ?? false) === true || str_starts_with($target->kind, 'external_');
        }
        $specifier = $double->target['specifier'] ?? '';

        return $specifier !== '' && preg_match('#^(?:\.|/|@/|~|\#)#', $specifier) !== 1;
    }
}
