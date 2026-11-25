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
 *
 * A Python base outside the project is an unseen ancestor, whose names are
 * unknown, except `abc.ABC`, `typing.Protocol` and `typing.Generic`, which
 * are seen and add only their metaclass's names (`knownBaseNames()`).
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
     * The Python bases outside the project that add no member a double may
     * stub but these (their metaclass's own on 3.14, the one version
     * checked, with the pure-Python `_py_abc` caches added; a lookup on the
     * class reaches them, so `patch.object(C, "register")` replaces one,
     * though `dir(C)` and a `spec=C` list none),
     * so a class inheriting from one keeps a complete name list:
     * `abc.ABC`, `typing.Protocol` and `typing.Generic`, by qualified name.
     * Every other base outside the project (`enum.Enum`, `typing.NamedTuple`,
     * `typing.TypedDict`, `Exception`) is unseen, its names unknown.
     */
    private const PYTHON_KNOWN_BASES = [
        'abc.ABC' => self::ABC_NAMES,
        'typing.Protocol' => [...self::ABC_NAMES, '_is_protocol', '_is_runtime_protocol'],
        'typing.Generic' => ['_is_protocol'],
    ];

    /** What `abc.ABCMeta` gives each class it makes, the pure-Python `_py_abc`'s caches included. */
    private const ABC_NAMES = ['_abc_cache', '_abc_caches_clear', '_abc_impl', '_abc_negative_cache', '_abc_negative_cache_version', '_abc_registry', '_abc_registry_clear', '_dump_registry', 'register'];

    /** A Python reference to a class outside the project: `py:external_symbol:<name>` (`from abc import ABC`) or `py:class:<name>` (`abc.ABC`), the name captured. */
    private const PYTHON_BASE_REFERENCE = '/^py:(?:external_symbol|class):(.+)$/sD';

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

    /** Whether a node came from the Python worker. */
    public static function isPython(NodeFact $node): bool
    {
        return str_starts_with($node->localId, 'py:');
    }

    /** Whether a node came from the Rust worker. */
    public static function isRust(NodeFact $node): bool
    {
        return str_starts_with($node->localId, 'rust:');
    }

    /** The Python class the scan holds under a qualified name (`app.models.Invoice`), or null when it holds none. */
    public function pythonClass(string $qualifiedName): ?NodeFact
    {
        return $this->nodes['py:class:' . $qualifiedName] ?? null;
    }

    /**
     * The names the known Python bases outside the project (`abc.ABC`,
     * `typing.Protocol`, `typing.Generic`) give `$owner` through its lineage,
     * sorted; empty when it inherits from none.
     *
     * @return list<string>
     */
    public function knownBaseNames(NodeFact $owner): array
    {
        $names = [];
        foreach ($this->reachable($owner)[0] as $type) {
            foreach ($this->parents[$type] ?? [] as $parent) {
                $names = [...$names, ...($this->knownBase($parent) ?? [])];
            }
        }
        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The names a known Python base outside the project gives, when a parent
     * reference names one (Ruling P8: `py:external_symbol:abc.ABC` and
     * `py:class:abc.ABC` alike) and no contribution emits a node under it;
     * null for any other reference.
     *
     * @return list<string>|null
     */
    private function knownBase(string $reference): ?array
    {
        if (isset($this->nodes[$reference]) || preg_match(self::PYTHON_BASE_REFERENCE, $reference, $match) !== 1) {
            return null;
        }

        return self::PYTHON_KNOWN_BASES[$match[1]] ?? null;
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
     * hierarchy (which a malformed contribution can carry) terminates. That
     * is not Python's lookup order: `answersCertainly()` says whether the
     * node found is the one Python answers with.
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
     * Whether `$member`, the node `member()` found on `$owner`, is for certain
     * the one a lookup of its name answers with (Ruling P24). Always for a
     * TypeScript or PHP owner. For a Python one, Python's lookup (the C3 MRO,
     * a class-body or `self.` binding) decides, which a breadth-first walk
     * does not model, so only when no other type of the lineage lists the
     * name in `member_names` and the def is the owner's own or the owner has
     * no unseen ancestor, which may come first in the MRO.
     */
    public function answersCertainly(NodeFact $owner, NodeFact $member): bool
    {
        if (!self::isPython($owner)) {
            return true;
        }
        $declarer = null;
        foreach ($this->lineage($owner) as $type) {
            if ($declarer === null && in_array($member->localId, $this->children[$type->localId] ?? [], true)) {
                $declarer = $type;
            } elseif (self::listsMember($type, $member->displayName)) {
                return false;
            }
        }

        return $declarer !== null && ($declarer->localId === $owner->localId || !$this->hasUnseenAncestor($owner));
    }

    /** Whether a type's `member_names` list holds `$name`. */
    private static function listsMember(NodeFact $type, string $name): bool
    {
        $names = $type->attributes['member_names'] ?? null;

        return is_array($names) && in_array($name, $names, true);
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
     * `$owner` and every type the scan holds that it inherits from, through
     * `uses_trait`, `extends`, `implements` and `re_exports`, itself first
     * and nearest first. An ancestor the scan does not hold is left out;
     * `hasUnseenAncestor()` says whether there is one.
     *
     * @return list<NodeFact>
     */
    public function lineage(NodeFact $owner): array
    {
        return array_values(array_filter(array_map(fn(string $reference): ?NodeFact => $this->nodes[$reference] ?? null, $this->reachable($owner)[0])));
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
     * Whether only the scan's own nodes can answer a name on `$owner`: it has
     * no unseen ancestor, and it and every ancestor send a `member_names`
     * list that is not marked open (a `__getattr__`, a metaclass, a builtin
     * base).
     */
    public function hasClosedLineage(NodeFact $owner): bool
    {
        if ($this->hasUnseenAncestor($owner)) {
            return false;
        }
        foreach ($this->lineage($owner) as $type) {
            if (!is_array($type->attributes['member_names'] ?? null) || ($type->attributes['member_names_open'] ?? false) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether something may have rewritten the methods `$owner` declares or
     * inherits (Ruling P21): it or an ancestor the scan holds carries
     * `methods_rewritable` (a project metaclass, an `__init_subclass__`, a
     * class decorator or call that may replace methods). A base outside the
     * project is not taken to rewrite: a residual (G4-12), since its metaclass
     * or hook cannot be read.
     */
    public function hasRewritableLineage(NodeFact $owner): bool
    {
        foreach ($this->lineage($owner) as $type) {
            if (($type->attributes['methods_rewritable'] ?? false) === true) {
                return true;
            }
        }

        return false;
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
                // A known Python base outside the project is seen, and adds no ancestor (`knownBaseNames()` gives its names).
                if (($follows !== null && !$follows($parent)) || $this->knownBase($parent) !== null) {
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

    /**
     * Whether a node stands for something outside the project: marked
     * external, an `external_` kind, declared under `vendor/`, or for a
     * Python node, installed under a `site-packages/` directory (a virtual
     * environment inside the project).
     */
    private static function isOutside(NodeFact $node): bool
    {
        $path = $node->evidence->relativePath;

        return ($node->attributes['external'] ?? false) === true || str_starts_with($node->kind, 'external_') || str_starts_with($path, 'vendor/')
            || (self::isPython($node) && preg_match('#(?:^|/)site-packages/#', $path) === 1);
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
     * no contribution emits (a lib global such as `console`), a PHP class
     * the scan does not hold (one in `vendor/` or PHP's own) or any Python
     * reference the scan does not hold, or when the double has
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
        // An owner no contribution emits and a worker named as external (a lib global such as `console` or `Date`), or a PHP class or a Python module or class the project does not declare.
        if (preg_match('/^(?:[^:]+:external_|php:|py:)/', $this->targets[$this->nodeOf($double)->localId] ?? '') === 1) {
            return true;
        }
        $specifier = $double->target['specifier'] ?? '';

        return $specifier !== '' && preg_match('#^(?:\.|/|@/|~/|\#)#', $specifier) !== 1;
    }
}
