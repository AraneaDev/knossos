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

    private const ACCESSOR = '/^(?:get|set)[A-Z]\w*Attribute$/';

    /** @var array<string, true> observer class references */
    private array $observers;

    /** @var array<string, string> method local id => the reason Eloquent calls it by name ('accessor' or 'event') */
    private array $named;

    public function __construct(PhpClassHierarchy $hierarchy)
    {
        $this->observers = array_fill_keys($hierarchy->observers(), true);
        $named = [];
        // The methods a class gets from a trait or a project base class are
        // declared elsewhere, so they are found from the class that has them.
        foreach ($hierarchy->classes() as $class) {
            if ($hierarchy->extendsExternal($class, self::MODEL_BASES)) {
                foreach ($hierarchy->methods($class) as $name => $id) {
                    if (preg_match(self::ACCESSOR, $name) === 1) {
                        $named[$id] ??= 'accessor';
                    }
                }
            }
        }
        foreach (array_keys($this->observers) as $observer) {
            foreach ($hierarchy->methods($observer) as $name => $id) {
                if (in_array($name, LaravelPathRoleRule::OBSERVER_METHODS, true)) {
                    $named[$id] = 'event';
                }
            }
        }
        $this->named = $named;
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
        $reason = $node->kind === 'method' ? ($this->named[$node->localId] ?? null) : null;
        if ($reason === 'event') {
            return $this->claimedByPath($node) ? [] : [$this->entryMethod($node, 'laravel.observer', 'event')];
        }

        return $reason === 'accessor' ? [$this->entryMethod($node, 'laravel.model', 'accessor')] : [];
    }

    /**
     * The observer role for a class some model registers, unless its directory already gives it.
     *
     * @return list<ClassificationFact>
     */
    private function observerClass(NodeFact $node): array
    {
        if (!isset($this->observers[$node->localId]) || $this->claimedByPath($node)) {
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
     * rule keeps it, and this one answers only for observers elsewhere. The
     * path rule takes the first fragment that matches, so an observer under
     * `app/Models/Observers/` is a model to it and is still ours to classify.
     */
    private function claimedByPath(NodeFact $node): bool
    {
        return LaravelPathRoleRule::roleForPath($node->evidence->relativePath) === 'laravel.observer';
    }
}
