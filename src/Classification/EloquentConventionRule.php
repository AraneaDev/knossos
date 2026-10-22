<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

/**
 * Eloquent calls some methods by name: nothing in the project references them.
 *
 * A legacy accessor or mutator (`getFullNameAttribute`) runs when someone
 * reads `$user->full_name`, and an observer's `created` runs when a model is
 * created. Both read as dead code to a graph that only follows references.
 * The accessors are recognised on a class whose chain leaves the project
 * through an Eloquent base, so a `getFooAttribute` on any other class stays
 * reportable; observers are the classes some model registers with
 * `#[ObservedBy]` or `Model::observe()`, wherever they live.
 */
final readonly class EloquentConventionRule implements ClassificationRule
{
    /** The classes that make a class an Eloquent model, however many project bases sit between. */
    private const MODEL_BASES = [
        'Illuminate\\Database\\Eloquent\\Model',
        'Illuminate\\Foundation\\Auth\\User',
        'Illuminate\\Database\\Eloquent\\Relations\\Pivot',
        'Illuminate\\Database\\Eloquent\\Relations\\MorphPivot',
    ];

    /** @var array<string, true> */
    private array $observers;

    public function __construct(private PhpClassHierarchy $hierarchy)
    {
        $this->observers = array_fill_keys($hierarchy->observers(), true);
    }

    /** {@inheritDoc} */
    public function id(): string
    {
        return 'laravel.eloquent.conventions.v1';
    }

    /** {@inheritDoc} */
    public function classify(NodeFact $node): array
    {
        if ($node->kind === 'class') {
            return $this->observerClass($node);
        }
        if ($node->kind !== 'method') {
            return [];
        }
        $separator = strrpos($node->canonicalName, '::');
        if ($separator === false) {
            return [];
        }
        $owner = 'php:class:' . substr($node->canonicalName, 0, $separator);
        $name = $node->displayName;
        if (isset($this->observers[$owner]) && in_array($name, LaravelPathRoleRule::OBSERVER_METHODS, true)) {
            return $this->observerClaimedByPath($node) ? [] : [$this->entryMethod($node, 'laravel.observer', 'event')];
        }
        if (preg_match('/^(?:get|set)[A-Z]\w*Attribute$/', $name) === 1
            && $this->hierarchy->extendsExternal($owner, self::MODEL_BASES)) {
            return [$this->entryMethod($node, 'laravel.model', 'accessor')];
        }

        return [];
    }

    /**
     * The observer role for a class some model registers, unless its directory already gives it.
     *
     * @return list<ClassificationFact>
     */
    private function observerClass(NodeFact $node): array
    {
        if (!isset($this->observers[$node->localId]) || $this->observerClaimedByPath($node)) {
            return [];
        }

        return [new ClassificationFact(
            $node->localId,
            'laravel.observer',
            $this->id(),
            Origin::FrameworkConvention,
            Confidence::Probable,
            $node->evidence,
            ['reason' => 'registered_observer'],
        )];
    }

    /** The entry-method fact for a method Eloquent calls by name, saying which kind of class it belongs to. */
    private function entryMethod(NodeFact $node, string $classRole, string $reason): ClassificationFact
    {
        return new ClassificationFact(
            $node->localId,
            'laravel.entry_method',
            $this->id(),
            Origin::FrameworkConvention,
            Confidence::Probable,
            $node->evidence,
            ['class_role' => $classRole, 'reason' => $reason],
        );
    }

    /**
     * Whether the directory convention already gives this node its observer role.
     *
     * Two rules giving one node the same role would list it twice; the path
     * rule keeps it, and this one answers only for observers elsewhere.
     */
    private function observerClaimedByPath(NodeFact $node): bool
    {
        return str_contains('/' . ltrim($node->evidence->relativePath, '/'), LaravelPathRoleRule::OBSERVER_FRAGMENT);
    }
}
