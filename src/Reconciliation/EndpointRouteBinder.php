<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

use Knossos\Store\StableId;

/**
 * Joins each endpoint a caller requests to the route that serves it.
 *
 * Runs over the whole scan's nodes and edges, so a route in a file the scan
 * took from its cache still answers a call in a file it re-read. Only the
 * best grade a URL reaches counts, and within it the most specific route; a
 * tie is reported rather than guessed.
 */
final readonly class EndpointRouteBinder
{
    private const RANK = ['certain' => 3, 'probable' => 2, 'possible' => 1];

    /** The owner of the diagnostics: the join is made by the core, not by the scanner that read the endpoint. */
    public const OWNER = 'knossos.reconciliation:endpoint-routes';

    public function __construct(private string $projectId) {}

    /**
     * Joins each endpoint to the route that serves it, and reports the ones it cannot settle.
     *
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, array<string, mixed>> $edges
     * @param array<string, string> $evidencePaths node id to evidence path, for records that no longer carry one
     */
    public function bind(array $nodes, array $edges, array $evidencePaths = []): EndpointRouteBinding
    {
        $routes = RouteTemplates::forRoutes($nodes, $edges, $evidencePaths);
        $index = self::index($routes);
        $hasRoutes = false;
        foreach ($nodes as $node) {
            if ($node['kind'] === 'route') {
                $hasRoutes = true;
                break;
            }
        }
        $bound = [];
        $warnings = [];
        foreach ($nodes as $endpoint) {
            if ($endpoint['kind'] !== 'endpoint' || ($endpoint['attributes']['external'] ?? false) === true || $endpoint['file_id'] === null) {
                continue;
            }
            $endpoint['evidence_path'] = $evidencePaths[$endpoint['id']] ?? $endpoint['evidence_path'] ?? '';
            $candidates = $this->candidates($endpoint['attributes'], $routes, $index);
            if ($candidates === []) {
                if ($hasRoutes) {
                    $warnings[] = $this->warning($endpoint, 'ENDPOINT_ROUTE_UNMATCHED', 'info', sprintf('%s matches no route of this project', self::describe($endpoint)));
                }
                continue;
            }
            if (count($candidates) > 1) {
                $names = array_map(static fn(string $id): string => (string) ($nodes[$id]['canonical_name'] ?? $id), array_keys($candidates));
                $warnings[] = $this->warning($endpoint, 'ENDPOINT_ROUTE_AMBIGUOUS', 'warning', sprintf('%s matches %d routes equally: %s', self::describe($endpoint), count($candidates), implode(', ', $names)));
                continue;
            }
            $routeId = array_key_first($candidates);
            $match = $candidates[$routeId];
            $id = StableId::edge($this->projectId, 'resolves_to', $endpoint['id'], $routeId, 'binder:' . $endpoint['id']);
            $bound[$id] = [
                'id' => $id, 'kind' => 'resolves_to', 'source_id' => $endpoint['id'], 'target_id' => $routeId,
                'file_id' => $endpoint['file_id'], 'start_line' => $endpoint['start_line'], 'end_line' => $endpoint['end_line'],
                'origin' => 'derived', 'confidence' => $match['confidence'],
                'attributes' => ['match' => $match['match'], 'prefix_from' => $match['mounts']],
                'owner_key' => $endpoint['owner_key'],
            ];
        }

        return new EndpointRouteBinding($bound, $warnings);
    }

    /**
     * The route templates indexed once per scan: per method, a trie of each template's segments
     * read from the end (a parameter being a wildcard child), so a caller's URL reaches only the
     * templates it can fit, whole or as a suffix; and routes by name.
     *
     * @param array<string, array{methods: list<string>, name: ?string, family: ?string, templates: list<array{template: UrlTemplate, prefix: string, mounts: list<string>}>}> $routes
     * @return array{tries: array<string, array<string, mixed>>, names: array<string, list<string>>, order: array<string, int>}
     */
    private static function index(array $routes): array
    {
        $tries = [];
        $names = [];
        $order = [];
        foreach ($routes as $id => $route) {
            $order[$id] = count($order);
            if ($route['name'] !== null && $route['family'] !== null) {
                $names[$route['name']][] = $id;
            }
            foreach ($route['templates'] as $slot => $candidate) {
                $entry = ['route' => $id, 'slot' => $slot] + $candidate;
                foreach ($route['methods'] as $method) {
                    $node = &$tries[$method];
                    $node ??= ['children' => [], 'any' => null, 'entries' => []];
                    foreach (array_reverse($candidate['template']->segments) as $segment) {
                        if ($segment === null) {
                            $node['any'] ??= ['children' => [], 'any' => null, 'entries' => []];
                            $next = &$node['any'];
                        } else {
                            $node['children'][$segment] ??= ['children' => [], 'any' => null, 'entries' => []];
                            $next = &$node['children'][$segment];
                        }
                        unset($node);
                        $node = &$next;
                        unset($next);
                    }
                    $node['entries'][] = $entry;
                    unset($node);
                }
            }
        }

        return ['tries' => $tries, 'names' => $names, 'order' => $order];
    }

    /**
     * Every template entry whose segments, read from the end, fit the caller's: the ones as long as
     * the caller and the shorter ones that could be a suffix of it.
     *
     * @param array<string, mixed> $node
     * @param list<?string> $reversed
     * @param list<mixed> $entries
     */
    private static function walk(array $node, array $reversed, int $depth, array &$entries): void
    {
        foreach ($node['entries'] as $entry) {
            $entries[] = $entry;
        }
        if ($depth >= count($reversed)) {
            return;
        }
        $segment = $reversed[$depth];
        if ($segment !== null && isset($node['children'][$segment])) {
            self::walk($node['children'][$segment], $reversed, $depth + 1, $entries);
        }
        if ($node['any'] !== null) {
            self::walk($node['any'], $reversed, $depth + 1, $entries);
        }
    }

    /**
     * The routes left after keeping the best grade and, within it, the most literal segments.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, array{methods: list<string>, name: ?string, family: ?string, templates: list<array{template: UrlTemplate, prefix: string, mounts: list<string>}>}> $routes
     * @param array{tries: array<string, array<string, mixed>>, names: array<string, list<string>>, order: array<string, int>} $index
     * @return array<string, array{confidence: string, match: string, mounts: list<string>, literals: int}>
     */
    private function candidates(array $attributes, array $routes, array $index): array
    {
        $found = [];
        $name = $attributes['route_name'] ?? null;
        if (is_string($name)) {
            $family = $attributes['framework'] ?? null;
            foreach ($index['names'][$name] ?? [] as $id) {
                if ($family !== null && $routes[$id]['family'] === $family) {
                    $found[$id] = ['confidence' => 'certain', 'match' => 'name', 'mounts' => [], 'literals' => 0];
                }
            }

            return $found;
        }
        $template = is_string($attributes['template'] ?? null)
            ? (UrlTemplate::parse($attributes['template'])[0] ?? null)
            : (UrlTemplate::parse((string) ($attributes['uri'] ?? ''))[0] ?? null);
        if ($template === null) {
            return [];
        }
        $method = strtoupper((string) ($attributes['method'] ?? 'ANY'));
        $callerKnown = ($attributes['prefix'] ?? 'known') !== 'unknown';
        $reversed = array_reverse($template->segments);
        $methods = $method === 'ANY' ? array_keys($index['tries']) : [$method, 'ANY', ...($method === 'HEAD' ? ['GET'] : [])];
        $entries = [];
        foreach ($methods as $key) {
            if (isset($index['tries'][$key])) {
                self::walk($index['tries'][$key], $reversed, 0, $entries);
            }
        }
        foreach ($entries as $entry) {
            $grade = self::grade($template, $callerKnown, $entry);
            if ($grade === null) {
                continue;
            }
            $id = $entry['route'];
            $literals = $entry['template']->literalCount();
            $best = $found[$id] ?? null;
            if ($best === null
                || self::RANK[$grade[0]] > self::RANK[$best['confidence']]
                || (self::RANK[$grade[0]] === self::RANK[$best['confidence']] && ($literals > $best['literals']
                    || ($literals === $best['literals'] && $entry['slot'] < $best['slot'])))) {
                $found[$id] = ['confidence' => $grade[0], 'match' => $grade[1], 'mounts' => $entry['mounts'], 'literals' => $literals, 'slot' => $entry['slot']];
            }
        }
        if ($found === []) {
            return [];
        }
        $top = max(array_map(static fn(array $match): int => self::RANK[$match['confidence']], $found));
        $found = array_filter($found, static fn(array $match): bool => self::RANK[$match['confidence']] === $top);
        $most = max(array_column($found, 'literals'));
        $found = array_filter($found, static fn(array $match): bool => $match['literals'] === $most);
        uksort($found, static fn(string $left, string $right): int => $index['order'][$left] <=> $index['order'][$right]);

        return array_map(static fn(array $match): array => [
            'confidence' => $match['confidence'], 'match' => $match['match'], 'mounts' => $match['mounts'], 'literals' => $match['literals'],
        ], $found);
    }

    /**
     * The grade and match a route candidate earns for a caller, or null when it does not fit.
     *
     * @param array{template: UrlTemplate, prefix: string, mounts: list<string>} $candidate
     * @return ?array{0: string, 1: string}
     */
    private static function grade(UrlTemplate $caller, bool $callerKnown, array $candidate): ?array
    {
        $route = $candidate['template'];
        $routeKnown = $candidate['prefix'] !== 'unknown';
        if ($callerKnown && $routeKnown && $caller->equalsExactly($route)) {
            return [$candidate['prefix'] === 'declared' ? 'certain' : 'probable', 'exact'];
        }
        if ($callerKnown && $routeKnown && $caller->matchesWithParameters($route)) {
            return ['probable', 'parameter'];
        }
        if (!$route->isAllParameters() && $caller->endsWith($route)) {
            return ['possible', 'suffix'];
        }

        return null;
    }

    /**
     * The name an endpoint goes by in a diagnostic message.
     *
     * @param array<string, mixed> $endpoint
     */
    private static function describe(array $endpoint): string
    {
        return (string) ($endpoint['canonical_name'] ?? $endpoint['id']);
    }

    /**
     * One diagnostic record anchored to an endpoint.
     *
     * @param array<string, mixed> $endpoint
     * @return array<string, string|int>
     */
    private function warning(array $endpoint, string $code, string $severity, string $message): array
    {
        return [
            'owner' => self::OWNER, 'code' => $code, 'message' => $message, 'path' => $endpoint['evidence_path'],
            'severity' => $severity, 'start_line' => $endpoint['start_line'], 'end_line' => $endpoint['end_line'],
        ];
    }
}
