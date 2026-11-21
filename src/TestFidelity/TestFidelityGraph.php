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
 *
 * A PHP worker cannot know across files whether a name is a class, an
 * interface, a trait or an enum, so it names every double's target
 * `php:class:<FQCN>`; such a reference, and any PHP type reference, resolves
 * to the class-like node of that name, in any case, as PHP resolves names.
 */
final readonly class TestFidelityGraph
{
    /** Edge kinds that put another node's members in scope on the source: a type's parents, and what a module re-exports. */
    private const INHERITANCE_KINDS = ['uses_trait', 'extends', 'implements', 're_exports'];

    /** The node kinds a PHP type reference may name. */
    private const PHP_TYPE_KINDS = ['class', 'interface', 'trait', 'enum'];

    /** A PHP type reference: `php:<kind>:<FQCN>`, the name captured. */
    private const PHP_TYPE_REFERENCE = '/^php:(?:class|interface|trait|enum):\\\\?(.+)$/sD';

    /**
     * @param array<string, NodeFact> $nodes every node by reference; the first contribution to emit one wins
     * @param array<string, NodeFact> $factNodes the test double and test case nodes, by canonical name
     * @param list<TestDouble> $doubles
     * @param array<string, TestCaseFact> $testCases by canonical name, in canonical-name order
     * @param array<string, list<TestCaseFact>> $testCasesByFile the same, by the file each is in
     * @param array<string, string> $targets double reference => target reference
     * @param array<string, list<string>> $children container reference => contained references
     * @param array<string, list<string>> $parents type reference => the types it extends, implements or uses, in edge order
     * @param array<string, string> $phpTypes lower-case FQCN => the reference of the PHP class-like node of that name
     * @param array<string, array<string, true>> $visibilityChanged type reference => lower-case names of the methods whose visibility a trait use there changes
     */
    private function __construct(
        private array $nodes,
        private array $factNodes,
        private array $doubles,
        private array $testCases,
        private array $testCasesByFile,
        private array $targets,
        private array $children,
        private array $parents,
        private array $phpTypes,
        private array $visibilityChanged,
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
        $nodes = $factNodes = $doubles = $testCases = $targets = $children = $parents = $phpTypes = $visibilityChanged = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                if (isset($nodes[$node->localId])) {
                    continue;
                }
                $nodes[$node->localId] = $node;
                if (self::isPhp($node) && in_array($node->kind, self::PHP_TYPE_KINDS, true)) {
                    $phpTypes[strtolower(ltrim($node->canonicalName, '\\'))] ??= $node->localId;
                }
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
                } elseif (in_array($edge->kind, self::INHERITANCE_KINDS, true)) {
                    $parents[$edge->sourceReference][] = $edge->targetReference;
                    foreach (self::changedNames($edge->attributes) as $name) {
                        $visibilityChanged[$edge->sourceReference][$name] = true;
                    }
                }
            }
        }
        ksort($doubles, SORT_STRING);
        ksort($testCases, SORT_STRING);
        $byFile = [];
        foreach ($testCases as $case) {
            $byFile[self::fileOf($case->canonicalName)][] = $case;
        }

        return new self($nodes, $factNodes, array_values($doubles), $testCases, $byFile, $targets, $children, $parents, $phpTypes, $visibilityChanged);
    }

    /**
     * The lower-case method names a `uses_trait` edge's `visibility_changed` attribute lists.
     *
     * @param array<string, mixed> $attributes
     * @return list<string>
     */
    private static function changedNames(array $attributes): array
    {
        $names = $attributes['visibility_changed'] ?? null;

        return is_array($names) ? array_values(array_map(strtolower(...), array_filter($names, is_string(...)))) : [];
    }

    /** Whether a trait use in `$owner` or a type it inherits from changes the visibility of the method `$name`, in any case. */
    public function visibilityChanged(NodeFact $owner, string $name): bool
    {
        foreach ($this->reachable($owner)[0] as $type) {
            if (isset($this->visibilityChanged[$type][strtolower($name)])) {
                return true;
            }
        }

        return false;
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
        return array_values($this->testCases);
    }

    /** The test case of that canonical name, or null when the scan holds none. */
    public function testCase(string $canonicalName): ?TestCaseFact
    {
        return $this->testCases[$canonicalName] ?? null;
    }

    /**
     * Every test case of one file, by canonical name.
     *
     * @return list<TestCaseFact>
     */
    public function testCasesIn(string $file): array
    {
        return $this->testCasesByFile[$file] ?? [];
    }

    /** The file a test fact's canonical name is in: what comes before its `#`. */
    public static function fileOf(string $canonicalName): string
    {
        return explode('#', $canonicalName)[0];
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

        return $reference === null ? null : $this->resolve($reference);
    }

    /** The node a reference names: the node emitted under it, else for a PHP type reference the class-like of that name in any case. */
    private function resolve(string $reference): ?NodeFact
    {
        if (isset($this->nodes[$reference])) {
            return $this->nodes[$reference];
        }

        return preg_match(self::PHP_TYPE_REFERENCE, $reference, $match) === 1 ? $this->phpType($match[1]) : null;
    }

    /** The PHP class, interface, trait or enum the scan holds under a fully qualified name, in any case; null when it holds none. */
    public function phpType(string $name): ?NodeFact
    {
        $reference = $this->phpTypes[strtolower(ltrim($name, '\\'))] ?? null;

        return $reference === null ? null : $this->nodes[$reference];
    }

    /** Whether a node came from the PHP worker, whose class and member names PHP matches in any case. */
    public static function isPhp(NodeFact $node): bool
    {
        return str_starts_with($node->localId, 'php:');
    }

    /**
     * Whether PHP type `$class` is `$type`: itself, or a type it reaches
     * through `extends` and `implements` (a trait it uses is no type it is).
     * Null when that cannot be shown either way: `$class` or `$type` is not
     * in the project, or an ancestor of `$class` lies outside it.
     */
    public function phpIsA(string $class, string $type): ?bool
    {
        $wanted = strtolower(ltrim($type, '\\'));
        if (strtolower(ltrim($class, '\\')) === $wanted) {
            return true;
        }
        $start = $this->phpType($class);
        $target = $this->phpType($type);
        if ($start === null || self::isOutside($start)) {
            return null;
        }
        [$reached, $unseen] = $this->reachable($start, static fn(string $reference): bool => !str_starts_with($reference, 'php:trait:'));
        foreach ($reached as $reference) {
            if (strtolower($this->nodes[$reference]->canonicalName) === $wanted) {
                return true;
            }
        }

        return $unseen || $target === null || self::isOutside($target) ? null : false;
    }

    /** Whether the double has a `doubles` edge at all, to a node the scan holds or not. */
    public function hasTarget(TestDouble $double): bool
    {
        return isset($this->targets[$this->nodeOf($double)->localId]);
    }

    /**
     * The node `$owner` declares or inherits under `$name`: a class's method
     * or property, an interface's, a module's declaration.
     *
     * Walks `uses_trait`, `extends` and `implements` edges breadth first, so
     * the nearest declaration wins, and visits each type once, so a cyclic
     * hierarchy (which a malformed contribution can carry) terminates.
     */
    public function member(NodeFact $owner, string $name): ?NodeFact
    {
        $anyCase = self::isPhp($owner);
        foreach ($this->reachable($owner)[0] as $type) {
            foreach ($this->children[$type] ?? [] as $reference) {
                $child = $this->nodes[$reference] ?? null;
                if ($child !== null && ($anyCase ? strcasecmp($child->displayName, $name) === 0 : $child->displayName === $name)) {
                    return $child;
                }
            }
        }

        return null;
    }

    /**
     * Every name `$owner` declares or inherits, sorted, for a suggestion.
     *
     * @return list<string>
     */
    public function memberNames(NodeFact $owner): array
    {
        $names = [];
        foreach ($this->reachable($owner)[0] as $type) {
            foreach ($this->children[$type] ?? [] as $reference) {
                if (isset($this->nodes[$reference])) {
                    $names[$this->nodes[$reference]->displayName] = true;
                }
            }
        }
        $names = array_map(strval(...), array_keys($names));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Whether `$owner` has an ancestor, or a re-exported module, that the scan
     * does not hold or that lies outside the project: a name it lacks may
     * still be inherited from there, so its absence proves nothing.
     */
    public function hasUnseenAncestor(NodeFact $owner): bool
    {
        return $this->reachable($owner)[1];
    }

    /**
     * The references reachable from `$owner` through inheritance edges, itself
     * first and nearest first (breadth first, each once, so a cyclic
     * hierarchy terminates), and whether one of them is unseen or external.
     *
     * @param (\Closure(string): bool)|null $follows which parent references to walk; all by default
     * @return array{list<string>, bool}
     */
    private function reachable(NodeFact $owner, ?\Closure $follows = null): array
    {
        $queue = [$owner->localId];
        $visited = [$owner->localId => true];
        $unseen = false;
        for ($i = 0; $i < count($queue); $i++) {
            foreach ($this->parents[$queue[$i]] ?? [] as $parent) {
                if ($follows !== null && !$follows($parent)) {
                    continue;
                }
                $node = $this->resolve($parent);
                $unseen = $unseen || $node === null || self::isOutside($node);
                if ($node !== null && !isset($visited[$node->localId])) {
                    $visited[$node->localId] = true;
                    $queue[] = $node->localId;
                }
            }
        }

        return [$queue, $unseen];
    }

    /** Whether a node stands for something outside the project: marked external, an `external_` kind, or declared under `vendor/`. */
    private static function isOutside(NodeFact $node): bool
    {
        return ($node->attributes['external'] ?? false) === true || str_starts_with($node->kind, 'external_') || str_starts_with($node->evidence->relativePath, 'vendor/');
    }

    /** Whether a node reference names a node some contribution of the scan emitted. */
    public function has(string $reference): bool
    {
        return isset($this->nodes[$reference]);
    }

    /**
     * Whether what a double replaces lies outside the project, where its
     * members cannot be checked.
     *
     * True when the target node is marked external or declared in a `.d.ts`
     * file or under `vendor/`, when the double's edge names an external node
     * no contribution emits (a lib global such as `console`) or a PHP class
     * the scan does not hold (one in `vendor/` or PHP's own), or when the double has
     * no target and names a bare package specifier (`axios`, `@scope/pkg`,
     * `node:fs`). A relative or absolute path, or a path alias (`@/…`, `~/…`,
     * `#…`), is inside the project even when it does not resolve, which is
     * what makes an unresolved one a ghost rather than a package.
     */
    public function isExternal(TestDouble $double): bool
    {
        $target = $this->target($double);
        if ($target !== null) {
            return self::isOutside($target) || str_ends_with($target->evidence->relativePath, '.d.ts');
        }
        // An owner no contribution emits and a worker named as external (a lib global such as `console` or `Date`), or a PHP class the project does not declare.
        if (preg_match('/^(?:[^:]+:external_|php:)/', $this->targets[$this->nodeOf($double)->localId] ?? '') === 1) {
            return true;
        }
        $specifier = $double->target['specifier'] ?? '';

        return $specifier !== '' && preg_match('#^(?:\.|/|@/|~/|\#)#', $specifier) !== 1;
    }
}
