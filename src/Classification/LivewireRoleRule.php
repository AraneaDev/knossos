<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

/**
 * Livewire components, and the methods Livewire or a Blade view calls on them.
 *
 * A component is a class whose chain leaves the project through
 * `Livewire\Component`, however many project base classes and traits sit in
 * between. Its methods are reached by name from outside the graph: lifecycle
 * hooks by Livewire itself, attributed ones by events and computed
 * properties, the rest by the `wire:click` and `{{ $this->x }}` of the
 * component's own view, which {@see LivewireViews} joins to it. A name used
 * by one component's view is never given to another component's method.
 */
final class LivewireRoleRule implements ClassificationRule
{
    public const COMPONENT_ROLE = 'livewire.component';
    public const ENTRY_METHOD_ROLE = 'livewire.entry_method';

    /** Methods Livewire calls on a component as it moves through a request. */
    private const HOOKS = [
        'mount', 'boot', 'booted', 'hydrate', 'dehydrate', 'updating', 'updated', 'rendering',
        'rendered', 'render', 'exception', 'placeholder', 'rules', 'messages', 'validationAttributes',
    ];

    /** Hook families that also come with a property name: `updatedTitle`, `hydrateItems`. */
    private const PROPERTY_HOOK = '/^(?:updated|updating|hydrate|dehydrate)[A-Z]/';

    /** Attributes that make Livewire or the browser call a method by name. */
    private const CALLED_ATTRIBUTES = ['Livewire\\Attributes\\On', 'Livewire\\Attributes\\Computed', 'Livewire\\Attributes\\Js'];

    /** What outranks what when several components reach one method, such as a shared trait. */
    private const RANK = ['hook' => 3, 'attribute' => 2, 'view_action' => 1, 'view_expression' => 1];

    /** @var array<string, true> component class references */
    private array $components = [];

    /** @var array<string, array<string, mixed>> method local id => fact attributes */
    private array $entryMethods = [];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(private readonly PhpClassHierarchy $hierarchy)
    {
        $views = new LivewireViews($hierarchy);
        $components = array_values(array_filter(
            $hierarchy->classes(),
            fn(string $class): bool => $hierarchy->extendsExternal($class, ['Livewire\\Component']),
        ));
        sort($components, SORT_STRING);
        foreach ($components as $component) {
            $this->components[$component] = true;
            $root = $views->rootOf($component);
            $this->warnUnresolved($component, $root, $views);
            $used = $root === null ? [] : $views->usedFrom($root['path']);
            $this->collectEntryMethods($component, $used);
        }
    }

    /** {@inheritDoc} */
    public function id(): string
    {
        return 'livewire.conventions.v1';
    }

    /**
     * One message per class-based component whose view is not among the scanned files.
     *
     * Its view actions then cannot be counted, so methods only the view calls
     * would read as unused; the message says where the view was looked for.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** {@inheritDoc} */
    public function classify(NodeFact $node): array
    {
        if ($node->kind === 'class' && isset($this->components[$node->localId])) {
            return [$this->fact($node, self::COMPONENT_ROLE, ['reason' => 'extends_component'])];
        }
        if ($node->kind === 'method' && isset($this->entryMethods[$node->localId])) {
            return [$this->fact($node, self::ENTRY_METHOD_ROLE, $this->entryMethods[$node->localId])];
        }

        return [];
    }

    /** @param ?array{path: string, name: string} $root */
    private function warnUnresolved(string $component, ?array $root, LivewireViews $views): void
    {
        $node = $this->hierarchy->node($component);
        if ($root === null || $node === null || str_starts_with($node->canonicalName, '{anonymous}')
            || ($node->attributes['abstract'] ?? false) === true || $views->scanned($root['path'])) {
            return;
        }
        $this->warnings[] = sprintf(
            'LIVEWIRE_VIEW_UNRESOLVED: %s renders %s, expected at %s, which is not in the scan; its view actions are not counted.',
            $node->canonicalName,
            $root['name'],
            $root['path'],
        );
    }

    /** @param array<string, array{view: string, line: int, via: string}> $used */
    private function collectEntryMethods(string $component, array $used): void
    {
        $traits = [];
        foreach ($this->hierarchy->traitsOf($component) as $trait) {
            $traits[$trait] = substr($trait, (int) strrpos($trait, '\\') + 1);
        }
        foreach ($this->hierarchy->methods($component) as $name => $id) {
            $reason = $this->hookReason($name, $id, $traits) ?? $this->attributeReason($id) ?? $this->viewReason($name, $used);
            if ($reason === null) {
                continue;
            }
            $current = $this->entryMethods[$id]['reason'] ?? null;
            if ($current === null || self::RANK[$reason['reason']] > self::RANK[$current]) {
                $this->entryMethods[$id] = $reason;
            }
        }
    }

    /**
     * @param array<string, string> $traits trait reference => short name, for the traits the component uses
     * @return ?array<string, string>
     */
    private function hookReason(string $name, string $id, array $traits): ?array
    {
        if (in_array($name, self::HOOKS, true) || preg_match(self::PROPERTY_HOOK, $name) === 1) {
            return ['reason' => 'hook'];
        }
        foreach ($traits as $trait => $short) {
            foreach (self::HOOKS as $hook) {
                // A trait's own lifecycle hook is `mount` plus the trait's name.
                if ($name === $hook . $short && ($this->hierarchy->methods($trait)[$name] ?? null) === $id) {
                    return ['reason' => 'hook'];
                }
            }
        }

        return null;
    }

    /** @return ?array<string, string> */
    private function attributeReason(string $id): ?array
    {
        $attributes = $this->hierarchy->node($id)?->attributes['php_attributes'] ?? [];
        foreach (is_array($attributes) ? $attributes : [] as $attribute) {
            if (is_string($attribute) && in_array(ltrim($attribute, '\\'), self::CALLED_ATTRIBUTES, true)) {
                return ['reason' => 'attribute', 'attribute' => ltrim($attribute, '\\')];
            }
        }

        return null;
    }

    /**
     * @param array<string, array{view: string, line: int, via: string}> $used
     * @return ?array<string, mixed>
     */
    private function viewReason(string $name, array $used): ?array
    {
        if (!isset($used[$name])) {
            return null;
        }

        return [
            'reason' => $used[$name]['via'] === 'expression' ? 'view_expression' : 'view_action',
            'view' => $used[$name]['view'],
            'line' => $used[$name]['line'],
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function fact(NodeFact $node, string $role, array $attributes): ClassificationFact
    {
        return new ClassificationFact(
            $node->localId,
            $role,
            $this->id(),
            Origin::FrameworkConvention,
            Confidence::Probable,
            $node->evidence,
            $attributes,
        );
    }
}
