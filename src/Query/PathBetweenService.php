<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Store\ProjectRepository;
use PDO;

/**
 * The architecture pane's path explorer: the routes by which one component
 * reaches another, as {@see FlowQuery::explainFlow()} finds
 * them with the tool's own bounds, each hop with its kind, its step
 * (`identity` for an enricher's `represents` link, `relation` otherwise), its
 * confidence, its origin, whether it is derived (an interface method to the
 * one implementing it) and where it is written.
 *
 * Asked for both directions: a person who picked the two in the wrong order
 * still sees how they are joined. `from` and `to` stay as asked, and
 * `reversed` says the routes run from `to` to `from`.
 * Read-only, never scans.
 */
final readonly class PathBetweenService
{
    /** The longest route looked for, in hops. */
    public const DEPTH = 6;

    /** The most routes listed, the strongest first. */
    public const ROUTES = 5;

    /** How long each search may take: explain_flow's own default. */
    private const TIMEOUT_MS = FlowQuery::DEFAULT_TIMEOUT_MS;

    /**
     * @param PDO $pdo an existing, migrated graph database
     * @param QueryServices|null $services the query services to read through; null wires them against `$pdo` per call
     */
    public function __construct(private PDO $pdo, private ?QueryServices $services = null) {}

    /**
     * The routes from `$from` to `$to` in the project owning `$path`;
     * `unscanned`, or `not-found` / `ambiguous` with the end that failed.
     *
     * @return array<string, mixed>
     */
    public function routes(string $path, string $from, string $to): array
    {
        $absolute = realpath($path) ?: $path;
        $envelope = ['path' => $absolute, 'project_id' => null, 'snapshot_id' => null, 'from' => null, 'to' => null, 'reversed' => false, 'routes' => [], 'truncated' => false, 'truncation_reasons' => []];
        $project = (new ProjectRepository($this->pdo))->resolvePath($absolute);
        if ($project === null || !is_string($project['active_scan_id']) || $project['active_scan_id'] === '') {
            return ['status' => 'unscanned'] + $envelope;
        }
        $id = (string) $project['id'];
        $envelope = ['project_id' => $id, 'snapshot_id' => $project['active_scan_id']] + $envelope;
        $flows = ($this->services ?? QueryServices::wire($this->pdo))->flowQueries;
        $flow = (new AnnotationNotes($this->pdo))->attach($flows->explainFlow($id, $from, $to, self::DEPTH, self::ROUTES, timeoutMs: self::TIMEOUT_MS, bothDirections: true));
        foreach (['from' => $from, 'to' => $to] as $end => $asked) {
            $candidates = $flow->data[$end]['candidates'] ?? null;
            if (is_array($candidates) && count($candidates) !== 1) {
                return ['status' => $candidates === [] ? 'not-found' : 'ambiguous', 'unresolved' => $end, 'asked' => $asked] + $envelope;
            }
        }
        $reversed = (bool) $flow->data['reversed'];
        $ids = [];
        foreach ($flow->data['paths'] as $route) {
            foreach ($route['nodes'] as $node) {
                $ids[] = (string) $node['id'];
            }
        }
        $ids = [...$ids, (string) $flow->data['from']['id'], (string) $flow->data['to']['id']];
        $labels = BoundaryLabels::load($this->pdo, $id)->forNodes($ids);
        $named = static fn(array $node): array => ['name' => (string) $node['display_name'], 'canonical_name' => (string) $node['canonical_name'], 'kind' => (string) $node['kind'], 'boundary' => $labels[(string) $node['id']] ?? null];
        $ends = $reversed ? ['from' => $flow->data['to'], 'to' => $flow->data['from']] : ['from' => $flow->data['from'], 'to' => $flow->data['to']];

        return [
            'status' => 'ok',
            'from' => $named($ends['from']),
            'to' => $named($ends['to']),
            'reversed' => $reversed,
            'routes' => array_map(static fn(array $route): array => [
                'nodes' => array_map($named, $route['nodes']),
                'hops' => array_map(static fn(array $hop): array => [
                    'kind' => (string) $hop['kind'],
                    'step' => (string) $hop['step'],
                    'confidence' => (string) $hop['confidence'],
                    'origin' => (string) $hop['origin'],
                    'derived' => $hop['origin'] === 'derived',
                    'explanation' => (string) $hop['explanation'],
                    'path' => $hop['evidence']['path'] ?? null,
                    'line' => isset($hop['evidence']['start_line']) ? (int) $hop['evidence']['start_line'] : null,
                ], $route['hops']),
            ], $flow->data['paths']),
            'truncated' => $flow->truncated,
            'truncation_reasons' => $flow->data['bounds']['truncation_reasons'],
        ] + $envelope;
    }
}
