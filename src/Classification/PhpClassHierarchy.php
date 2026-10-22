<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\NodeFact;

/**
 * The PHP classes of a whole scan, with the inheritance, traits and methods
 * that connect them.
 *
 * A worker sees one file, so it can say what a class extends and which traits
 * it uses but not what those are. A role rule that has to ask "is this a
 * Livewire component" or "which methods can a view call" needs the chain
 * across files, and builds it here from every contribution, cached ones
 * included. Only `php:` nodes and edges are read.
 */
final readonly class PhpClassHierarchy
{
    /**
     * @param array<string, NodeFact> $nodes every php node by local id
     * @param array<string, string> $parents class reference => parent class name, as written in the `extends`
     * @param array<string, list<string>> $traits class or trait reference => the trait references it uses
     * @param array<string, array<string, string>> $declared class or trait reference => method name => method local id
     * @param array<string, list<string>> $classesByPath relative path => class references declared there
     * @param list<string> $observed observer class references, targets of `observes` edges
     */
    private function __construct(
        private array $nodes,
        private array $parents,
        private array $traits,
        private array $declared,
        private array $classesByPath,
        private array $observed,
    ) {}

    /**
     * Index every PHP class, trait and method the contributions carry.
     *
     * @param list<\Knossos\Scanner\Protocol\ScanContribution> $contributions
     */
    public static function fromContributions(array $contributions): self
    {
        $nodes = [];
        $parents = [];
        $classesByPath = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                if (!str_starts_with($node->localId, 'php:')) {
                    continue;
                }
                $nodes[$node->localId] = $node;
                if ($node->kind === 'class' || $node->kind === 'trait') {
                    $classesByPath[$node->evidence->relativePath][] = $node->localId;
                }
                $parent = $node->attributes['extends'] ?? null;
                if ($node->kind === 'class' && is_string($parent) && $parent !== '') {
                    $parents[$node->localId] = ltrim($parent, '\\');
                }
            }
        }
        $traits = [];
        $declared = [];
        $observed = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if (!str_starts_with($edge->sourceReference, 'php:')) {
                    continue;
                }
                if ($edge->kind === 'uses_trait') {
                    $traits[$edge->sourceReference][] = $edge->targetReference;
                } elseif ($edge->kind === 'observes') {
                    $observed[$edge->targetReference] = true;
                } elseif ($edge->kind === 'contains' && ($nodes[$edge->targetReference]->kind ?? null) === 'method') {
                    $declared[$edge->sourceReference][$nodes[$edge->targetReference]->displayName] = $edge->targetReference;
                }
            }
        }

        return new self($nodes, $parents, $traits, $declared, $classesByPath, array_map(strval(...), array_keys($observed)));
    }

    /**
     * The project classes above a class, nearest first, then the first name the scan has no class for.
     *
     * Stops at the first external name, so `Livewire\Component` ends the
     * chain of every component and an unreadable base is reported rather than
     * walked past. A cycle ends the walk instead of repeating it.
     *
     * @return list<string>
     */
    public function ancestorsLeaving(string $classReference): array
    {
        $chain = [];
        $seen = [$classReference => true];
        $current = $classReference;
        while (isset($this->parents[$current])) {
            $parent = 'php:class:' . $this->parents[$current];
            if (!isset($this->nodes[$parent])) {
                $chain[] = $this->parents[$current];
                break;
            }
            if (isset($seen[$parent])) {
                break;
            }
            $seen[$parent] = true;
            $chain[] = $parent;
            $current = $parent;
        }

        return $chain;
    }

    /**
     * Whether the chain above a class leaves the project through one of the given names.
     *
     * @param list<string> $externalNames
     */
    public function extendsExternal(string $classReference, array $externalNames): bool
    {
        $chain = $this->ancestorsLeaving($classReference);
        $last = end($chain);

        return $last !== false && !str_starts_with($last, 'php:') && in_array($last, $externalNames, true);
    }

    /**
     * Every method a class has, own then used traits then the parent's, nearest declaration winning.
     *
     * @return array<string, string> method name => method local id
     */
    public function methods(string $classReference): array
    {
        $methods = [];
        foreach ([$classReference, ...array_filter($this->ancestorsLeaving($classReference), $this->isProject(...))] as $owner) {
            foreach ([$owner, ...$this->traitsUsedBy($owner)] as $source) {
                foreach ($this->declared[$source] ?? [] as $name => $id) {
                    $methods[$name] ??= $id;
                }
            }
        }

        return $methods;
    }

    /**
     * The traits a class gets, through its parents and through traits that use traits.
     *
     * @return list<string> trait references
     */
    public function traitsOf(string $classReference): array
    {
        $found = [];
        foreach ([$classReference, ...array_filter($this->ancestorsLeaving($classReference), $this->isProject(...))] as $owner) {
            foreach ($this->traitsUsedBy($owner) as $trait) {
                $found[$trait] = true;
            }
        }

        return array_map(strval(...), array_keys($found));
    }

    /**
     * Classes and traits declared in a file, anonymous classes included.
     *
     * @return list<string>
     */
    public function classesIn(string $relativePath): array
    {
        return $this->classesByPath[$relativePath] ?? [];
    }

    /** The node behind a local id, or null when the scan has none. */
    public function node(string $reference): ?NodeFact
    {
        return $this->nodes[$reference] ?? null;
    }

    /**
     * The attributes of a file's module node; empty when the file has none.
     *
     * @return array<string, mixed>
     */
    public function moduleAttributes(string $relativePath): array
    {
        return $this->nodes['php:module:' . $relativePath]->attributes ?? [];
    }

    /**
     * Classes some model registers as its observer.
     *
     * @return list<string> class references
     */
    public function observers(): array
    {
        return $this->observed;
    }

    /** Whether a chain entry is a project class rather than the external name that ends the chain. */
    private function isProject(string $entry): bool
    {
        return str_starts_with($entry, 'php:');
    }

    /**
     * Traits one owner uses, followed through the traits those use.
     *
     * @return list<string>
     */
    private function traitsUsedBy(string $owner): array
    {
        $found = [];
        $pending = $this->traits[$owner] ?? [];
        while ($pending !== []) {
            $trait = array_shift($pending);
            if (isset($found[$trait])) {
                continue;
            }
            $found[$trait] = true;
            array_push($pending, ...($this->traits[$trait] ?? []));
        }

        return array_map(strval(...), array_keys($found));
    }
}
