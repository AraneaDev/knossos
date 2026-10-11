<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

/**
 * The full URL templates each route answers, with how sure its prefix is:
 * declared by the framework code the scan read, applied by convention, or
 * unknown because a mount or include the scan cannot see adds to it.
 */
final readonly class RouteTemplates
{
    private const MAX_MOUNT_DEPTH = 8;

    /**
     * The full URL templates each route answers, with the methods, name and framework family.
     *
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, array<string, mixed>> $edges
     * @param array<string, string> $evidencePaths node id to evidence path, for records that no longer carry one
     * @return array<string, array{methods: list<string>, name: ?string, family: ?string, templates: list<array{template: UrlTemplate, prefix: string, mounts: list<string>}>}>
     */
    public static function forRoutes(array $nodes, array $edges, array $evidencePaths = []): array
    {
        $byLocal = [];
        $rootUrlconfs = [];
        foreach ($nodes as $id => $node) {
            $local = $node['attributes']['scanner_local_id'] ?? null;
            if (is_string($local)) {
                $byLocal[$local] = $id;
            }
            if ($node['kind'] === 'setting' && str_ends_with((string) ($node['canonical_name'] ?? ''), '.ROOT_URLCONF')
                && is_string($node['attributes']['value'] ?? null)) {
                foreach (self::urlconfNames((string) $node['canonical_name'], $node['attributes']['value']) as $name) {
                    $rootUrlconfs[$name] = true;
                }
            }
        }
        $mountsInto = [];
        foreach ($edges as $edgeId => $edge) {
            if ($edge['kind'] === 'mounts') {
                $mountsInto[$edge['target_id']][] = ['id' => $edgeId, 'prefix' => (string) ($edge['attributes']['prefix'] ?? ''), 'onto' => $edge['attributes']['onto'] ?? null];
            }
        }
        $routes = [];
        foreach ($nodes as $id => $node) {
            if ($node['kind'] !== 'route') {
                continue;
            }
            $attributes = $node['attributes'];
            $path = $attributes['uri'] ?? $attributes['path'] ?? null;
            if (!is_string($path)) {
                continue;
            }
            $own = UrlTemplate::parse($path);
            $templates = [];
            foreach (self::prefixes($node, $evidencePaths[$id] ?? (string) ($node['evidence_path'] ?? ''), $byLocal, $mountsInto, $rootUrlconfs) as [$prefix, $status, $mounts]) {
                foreach ($own as $template) {
                    $templates[] = ['template' => $prefix === null ? $template : $template->prepend($prefix), 'prefix' => $status, 'mounts' => $mounts];
                }
            }
            $routes[$id] = [
                'methods' => self::methods($attributes),
                'name' => is_string($attributes['name'] ?? null) && $attributes['name'] !== '' ? $attributes['name'] : null,
                'family' => is_string($attributes['framework'] ?? null) ? $attributes['framework'] : null,
                'templates' => $templates,
            ];
        }

        return $routes;
    }

    /**
     * The upper-case methods a route answers, or ANY when it names none.
     *
     * @param array<string, mixed> $attributes
     * @return list<string>
     */
    private static function methods(array $attributes): array
    {
        $methods = $attributes['methods'] ?? $attributes['http_methods'] ?? ['ANY'];
        $methods = is_array($methods) && $methods !== [] ? $methods : ['ANY'];

        return array_values(array_unique(array_map(
            static fn(mixed $method): string => ($upper = strtoupper((string) $method)) === 'ALL' ? 'ANY' : $upper,
            $methods,
        )));
    }

    /**
     * The module names a ROOT_URLCONF value can have in the graph. A settings module nested below the
     * scanned root (`backend.app.settings`) names its urlconf from the package root (`app.urls`), so the
     * packages in front of the value's first part are tried as a prefix too.
     *
     * @return list<string>
     */
    private static function urlconfNames(string $settingName, string $value): array
    {
        $names = [$value];
        $module = explode('.', substr($settingName, 0, -strlen('.ROOT_URLCONF')));
        $first = explode('.', $value)[0];
        foreach ($module as $index => $part) {
            if ($index > 0 && $part === $first) {
                $names[] = implode('.', array_slice($module, 0, $index)) . '.' . $value;
            }
        }

        return $names;
    }

    /**
     * The prefixes in front of a route's own path, each with its status and the mount edges it came through.
     *
     * @param array<string, mixed> $node
     * @param array<string, string> $byLocal
     * @param array<string, list<array{id: string, prefix: string, onto: mixed}>> $mountsInto
     * @param array<string, true> $rootUrlconfs
     * @return list<array{0: ?UrlTemplate, 1: string, 2: list<string>}>
     */
    private static function prefixes(array $node, string $evidencePath, array $byLocal, array $mountsInto, array $rootUrlconfs): array
    {
        $attributes = $node['attributes'];
        $framework = $attributes['framework'] ?? null;
        if ($framework === 'laravel' && str_ends_with($evidencePath, 'routes/api.php')) {
            return [[UrlTemplate::parse('/api')[0], 'convention', []]];
        }
        if ($framework === 'django') {
            $module = $attributes['module'] ?? null;

            return [[null, is_string($module) && isset($rootUrlconfs[$module]) ? 'declared' : 'unknown', []]];
        }
        $router = $attributes['router'] ?? null;
        if (!is_string($router)) {
            return [[null, 'declared', []]];
        }

        return self::mountChains($byLocal[$router] ?? null, $byLocal, $mountsInto, []);
    }

    /**
     * The prefixes in front of a router, one per chain of mounts, each with its status.
     *
     * @param array<string, string> $byLocal
     * @param array<string, list<array{id: string, prefix: string, onto: mixed}>> $mountsInto
     * @param array<string, true> $seen routers already on this chain, so a loop of mounts ends it
     * @return list<array{0: ?UrlTemplate, 1: string, 2: list<string>}>
     */
    private static function mountChains(?string $routerId, array $byLocal, array $mountsInto, array $seen): array
    {
        $mounts = $routerId === null ? [] : ($mountsInto[$routerId] ?? []);
        if ($mounts === [] || isset($seen[$routerId]) || count($seen) >= self::MAX_MOUNT_DEPTH) {
            return [[null, 'unknown', []]];
        }
        $chains = [];
        foreach ($mounts as $mount) {
            $here = UrlTemplate::parse($mount['prefix'])[0] ?? UrlTemplate::parse('/')[0];
            if ($mount['onto'] === 'app') {
                $chains[] = [$here, 'declared', [$mount['id']]];
                continue;
            }
            $parent = is_string($mount['onto']) ? ($byLocal[$mount['onto']] ?? null) : null;
            foreach (self::mountChains($parent, $byLocal, $mountsInto, $seen + [$routerId => true]) as [$outer, $status, $through]) {
                $chains[] = [$outer === null ? $here : $here->prepend($outer), $status, [...$through, $mount['id']]];
            }
        }

        return $chains;
    }
}
