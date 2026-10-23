<?php

declare(strict_types=1);

namespace Knossos\Classification;

/**
 * Finds the Blade views a Livewire component renders and what they call on it.
 *
 * A view names methods (`wire:click="save"`, `{{ $this->posts }}`), and the
 * worker records them per file. Which component a name reaches is decided
 * here: only the components whose own view graph, the view and every view it
 * includes, contains the file. A name is never matched across components.
 */
final readonly class LivewireViews
{
    private const DEFAULT_CLASS_NAMESPACE = 'App\\Livewire';
    private const DEFAULT_VIEW_PATH = 'resources/views/livewire';
    private const VIEW_EXTENSION = '.blade.php';

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(private PhpClassHierarchy $hierarchy)
    {
        $config = $hierarchy->moduleAttributes('config/livewire.php')['livewire_config'] ?? [];
        $this->config = is_array($config) ? $config : [];
    }

    /**
     * Where a component's view should be: its literal `render()` view, or the file its name implies when it has no `render()` at all.
     *
     * @return ?array{path: string, name: string} the project-relative path and the view name; null when neither can be told
     */
    public function rootOf(string $classReference): ?array
    {
        $node = $this->hierarchy->node($classReference);
        if ($node === null) {
            return null;
        }
        $path = $node->evidence->relativePath;
        if (str_starts_with($node->canonicalName, '{anonymous}')) {
            // A single-file component is its own view; a multi-file one keeps
            // the view beside the class under the same name.
            $view = str_ends_with($path, self::VIEW_EXTENSION) ? $path : preg_replace('/\.php$/', self::VIEW_EXTENSION, $path);

            return ['path' => (string) $view, 'name' => (string) $view];
        }
        $render = $this->renderOf($classReference);
        if ($render !== null) {
            $resolved = $render === '' ? null : $this->resolve($render);

            return $resolved === null ? null : ['path' => $resolved, 'name' => $render];
        }
        $relative = $this->relativeName($node->canonicalName);
        if ($relative === null) {
            return null;
        }
        $segments = array_map(static fn(string $part): string => strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $part)), explode('\\', $relative));
        $directory = is_string($this->config['view_path'] ?? null) ? $this->config['view_path'] : self::DEFAULT_VIEW_PATH;

        return ['path' => rtrim($directory, '/') . '/' . implode('/', $segments) . self::VIEW_EXTENSION, 'name' => 'livewire.' . implode('.', $segments)];
    }

    /**
     * What the nearest `render()` of a component says about its view.
     *
     * The class and then its project ancestors are asked in turn, so a
     * component without a `render()` of its own joins the view a project base
     * class returns. A `render()` that returns no single literal view (a
     * computed name, an inline template) leaves the component without a
     * view to join: the file its name implies is not known to be the one it
     * renders, so it is not guessed.
     *
     * @return ?string the literal view name; '' for a `render()` without one; null when no `render()` exists at all
     */
    private function renderOf(string $classReference): ?string
    {
        foreach ([$classReference, ...array_filter($this->hierarchy->ancestorsLeaving($classReference), static fn(string $entry): bool => str_starts_with($entry, 'php:'))] as $owner) {
            $attributes = $this->hierarchy->node($owner)->attributes ?? [];
            $rendered = $attributes['livewire_render_view'] ?? null;
            if (is_string($rendered) && $rendered !== '') {
                return $rendered;
            }
            if (($attributes['livewire_render_view_dynamic'] ?? false) === true) {
                return '';
            }
        }

        // A `render()` the worker did not record, such as one a trait supplies.
        return isset($this->hierarchy->methods($classReference)['render']) ? '' : null;
    }

    /** Whether the scan holds the file as a module. */
    public function scanned(string $path): bool
    {
        return $this->hierarchy->node('php:module:' . $path) !== null;
    }

    /**
     * The first use of each method name across a view and everything it includes.
     *
     * Includes are followed with a visited set, so two views that include
     * each other contribute both of their names and the walk ends.
     *
     * @return array<string, array{view: string, line: int, via: string}> method name => where a view first uses it
     */
    public function usedFrom(string $rootPath): array
    {
        $used = [];
        $visited = [];
        $pending = [$rootPath];
        while ($pending !== []) {
            $path = array_shift($pending);
            if (isset($visited[$path])) {
                continue;
            }
            $visited[$path] = true;
            $facts = $this->hierarchy->moduleAttributes($path)['livewire_view'] ?? null;
            if (!is_array($facts)) {
                continue;
            }
            foreach (is_array($facts['used'] ?? null) ? $facts['used'] : [] as $use) {
                if (is_array($use) && is_string($use['name'] ?? null)) {
                    $used[$use['name']] ??= ['view' => $path, 'line' => (int) ($use['line'] ?? 1), 'via' => (string) ($use['via'] ?? 'action')];
                }
            }
            foreach (is_array($facts['includes'] ?? null) ? $facts['includes'] : [] as $include) {
                $target = is_array($include) && is_string($include['view'] ?? null) ? $this->resolve($include['view']) : null;
                if ($target !== null) {
                    $pending[] = $target;
                }
            }
        }

        return $used;
    }

    /** The project-relative file a view name points at: `a.b` under `resources/views`, `ns::a.b` under that namespace's directory. */
    private function resolve(string $name): ?string
    {
        $directory = 'resources/views';
        if (str_contains($name, '::')) {
            [$namespace, $name] = explode('::', $name, 2);
            // An empty map is stored as an empty list: no namespaces.
            $namespaces = $this->config['component_namespaces'] ?? [];
            $directory = is_array($namespaces) && is_string($namespaces[$namespace] ?? null) ? $namespaces[$namespace] : null;
            if ($directory === null) {
                return null;
            }
        }

        return rtrim($directory, '/') . '/' . str_replace('.', '/', $name) . self::VIEW_EXTENSION;
    }

    /** The class name below the configured component namespace, or null for a class outside it. */
    private function relativeName(string $canonicalName): ?string
    {
        $namespace = is_string($this->config['class_namespace'] ?? null) ? trim($this->config['class_namespace'], '\\') : self::DEFAULT_CLASS_NAMESPACE;
        $prefix = $namespace . '\\';

        return str_starts_with($canonicalName, $prefix) ? substr($canonicalName, strlen($prefix)) : null;
    }
}
