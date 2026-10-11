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
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, array<string, mixed>> $edges
     * @return array<string, array{methods: list<string>, name: ?string, family: ?string, templates: list<array{template: UrlTemplate, prefix: string, mounts: list<string>}>}>
     */
    public static function forRoutes(array $nodes, array $edges): array
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
                $rootUrlconfs[$node['attributes']['value']] = true;
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
            foreach (self::prefixes($node, $byLocal, $mountsInto, $rootUrlconfs) as [$prefix, $status, $mounts]) {
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
     * The prefixes in front of a route's own path, each with its status and the mount edges it came through.
     *
     * @param array<string, mixed> $node
     * @param array<string, string> $byLocal
     * @param array<string, list<array{id: string, prefix: string, onto: mixed}>> $mountsInto
     * @param array<string, true> $rootUrlconfs
     * @return list<array{0: ?UrlTemplate, 1: string, 2: list<string>}>
     */
    private static function prefixes(array $node, array $byLocal, array $mountsInto, array $rootUrlconfs): array
    {
        $attributes = $node['attributes'];
        $framework = $attributes['framework'] ?? null;
        if ($framework === 'laravel' && str_ends_with((string) $node['evidence_path'], 'routes/api.php')) {
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

        return self::mountChains($byLocal[$router] ?? null, $byLocal, $mountsInto, 0);
    }

    /**
     * @param array<string, string> $byLocal
     * @param array<string, list<array{id: string, prefix: string, onto: mixed}>> $mountsInto
     * @return list<array{0: ?UrlTemplate, 1: string, 2: list<string>}>
     */
    private static function mountChains(?string $routerId, array $byLocal, array $mountsInto, int $depth): array
    {
        $mounts = $routerId === null ? [] : ($mountsInto[$routerId] ?? []);
        if ($mounts === [] || $depth >= self::MAX_MOUNT_DEPTH) {
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
            foreach (self::mountChains($parent, $byLocal, $mountsInto, $depth + 1) as [$outer, $status, $through]) {
                $chains[] = [$outer === null ? $here : $here->prepend($outer), $status, [...$through, $mount['id']]];
            }
        }

        return $chains;
    }
}
