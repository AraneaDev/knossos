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

    public function __construct(private string $projectId) {}

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, array<string, mixed>> $edges
     */
    public function bind(array $nodes, array $edges): EndpointRouteBinding
    {
        $routes = RouteTemplates::forRoutes($nodes, $edges);
        $bound = [];
        $warnings = [];
        foreach ($nodes as $endpoint) {
            if ($endpoint['kind'] !== 'endpoint' || ($endpoint['attributes']['external'] ?? false) === true || $endpoint['file_id'] === null) {
                continue;
            }
            $candidates = $this->candidates($endpoint['attributes'], $routes);
            if ($candidates === []) {
                if ($routes !== []) {
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
     * The routes left after keeping the best grade and, within it, the most literal segments.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, array{methods: list<string>, name: ?string, family: ?string, templates: list<array{template: UrlTemplate, prefix: string, mounts: list<string>}>}> $routes
     * @return array<string, array{confidence: string, match: string, mounts: list<string>, literals: int}>
     */
    private function candidates(array $attributes, array $routes): array
    {
        $found = [];
        $name = $attributes['route_name'] ?? null;
        if (is_string($name)) {
            foreach ($routes as $id => $route) {
                if ($route['name'] === $name && $route['family'] === ($attributes['framework'] ?? null)) {
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
        foreach ($routes as $id => $route) {
            if (!self::methodFits($method, $route['methods'])) {
                continue;
            }
            foreach ($route['templates'] as $candidate) {
                $grade = self::grade($template, $callerKnown, $candidate);
                if ($grade === null) {
                    continue;
                }
                $best = $found[$id] ?? null;
                if ($best === null || self::RANK[$grade[0]] > self::RANK[$best['confidence']]) {
                    $found[$id] = ['confidence' => $grade[0], 'match' => $grade[1], 'mounts' => $candidate['mounts'], 'literals' => $candidate['template']->literalCount()];
                }
            }
        }
        if ($found === []) {
            return [];
        }
        $top = max(array_map(static fn(array $match): int => self::RANK[$match['confidence']], $found));
        $found = array_filter($found, static fn(array $match): bool => self::RANK[$match['confidence']] === $top);
        $most = max(array_column($found, 'literals'));

        return array_filter($found, static fn(array $match): bool => $match['literals'] === $most);
    }

    /**
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

    /** @param list<string> $routeMethods */
    private static function methodFits(string $method, array $routeMethods): bool
    {
        return $method === 'ANY'
            || in_array('ANY', $routeMethods, true)
            || in_array($method, $routeMethods, true)
            || ($method === 'HEAD' && in_array('GET', $routeMethods, true));
    }

    /** @param array<string, mixed> $endpoint */
    private static function describe(array $endpoint): string
    {
        return (string) ($endpoint['canonical_name'] ?? $endpoint['id']);
    }

    /**
     * @param array<string, mixed> $endpoint
     * @return array<string, string|int>
     */
    private function warning(array $endpoint, string $code, string $severity, string $message): array
    {
        return [
            'owner' => $endpoint['owner_key'], 'code' => $code, 'message' => $message, 'path' => $endpoint['evidence_path'],
            'severity' => $severity, 'start_line' => $endpoint['start_line'], 'end_line' => $endpoint['end_line'],
        ];
    }
}
