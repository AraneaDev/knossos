<?php

declare(strict_types=1);

namespace Knossos\Configuration;

use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\IgnoreMatcher;
use Knossos\Discovery\JsonConfig;
use Knossos\Filesystem\RelativePathRule;
use Knossos\Roots\AllowedRoots;
use Knossos\Roots\RootGuard;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;

/**
 * Loads and validates a project's configuration.
 *
 * Resolves the root through the allow-list first, so configuration is only ever
 * read from a tree this installation is permitted to read. Accepts `knossos.json`
 * or `knossos.jsonc` but refuses both at once rather than picking one silently.
 */
final class ProjectConfigurationLoader
{
    /**
     * Names a policy gives targets of their own: `@unassigned` is project code
     * in no boundary, `@external` vendor code and builtins. No boundary may
     * take one, or a policy naming it would mean something else.
     */
    public const RESERVED_BOUNDARY_NAMES = ['@unassigned', '@external'];

    /** The framework hints knossos.json accepts; the schema's `frameworks` enum must list the same. */
    public const FRAMEWORKS = ['laravel', 'symfony', 'django', 'fastapi', 'flask', 'nextjs', 'nestjs', 'react', 'vue', 'axum', 'actix', 'rocket'];

    public const ROOT_KEYS = ['$schema', 'version', 'ignores', 'limits', 'boundaries', 'frameworks', 'snapshot_retention', 'policies', 'quality_budgets', 'dead_code_suppressions', 'web_roots', 'enrichers'];
    public const BUDGET_KEYS = ['new_cycles', 'boundary_violations', 'error_diagnostics', 'warning_diagnostics', 'hub_degree_growth', 'unreferenced_candidates', 'public_surface_changes'];

    /**
     * Keys each nested object accepts, named so the published schema can be
     * checked against them.
     *
     * `worker_memory_mb` was accepted here and absent from
     * `schemas/project-config-v1.schema.json`, so an editor validating a
     * `knossos.json` against the published schema reported the one key that
     * raises a worker's heap as invalid. Nothing compared the two.
     * {@see \Knossos\Tests\Phpunit\Configuration\ProjectConfigurationSchemaAgreementTest} now does.
     */
    public const LIMIT_KEYS = ['max_files', 'max_file_bytes', 'worker_timeout_ms', 'worker_memory_mb', 'watch_scan_timeout_ms', 'refresh_budget_ms'];
    public const BOUNDARY_KEYS = ['name', 'path_prefix', 'namespace_prefix'];
    public const POLICY_KEYS = ['id', 'from_boundary', 'allow_targets', 'deny_targets', 'edge_kinds'];

    private function __construct() {}

    /**
     * Resolve the root through the allow-list, then read and validate its configuration.
     *
     * @param \Knossos\Roots\AllowedRoots|list<string> $allowedRoots
     */
    public static function load(string $requestedRoot, AllowedRoots|array $allowedRoots): ProjectConfiguration
    {
        $root = (new RootGuard($allowedRoots))->resolve($requestedRoot);
        $file = self::readConfigFile($root);
        if ($file === null) {
            return new ProjectConfiguration();
        }
        [$path, $data] = $file;
        self::knownKeys($data, self::ROOT_KEYS, 'project configuration');
        if (($data['version'] ?? null) !== 1) {
            throw new DiscoveryException('PROJECT_CONFIG_VERSION_UNSUPPORTED: version must be 1.');
        }
        $ignores = self::ignores($data['ignores'] ?? []);
        $limits = self::limits($data['limits'] ?? []);
        $retention = self::optionalInteger($data, 'snapshot_retention', 0, 20);
        $frameworks = self::stringList($data['frameworks'] ?? [], 'frameworks', 20);
        foreach ($frameworks as $framework) {
            if (!in_array($framework, self::FRAMEWORKS, true)) {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: unsupported framework hint ' . $framework . '.');
            }
        }
        $boundaries = self::boundaries($data['boundaries'] ?? []);
        $policies = self::policies($data['policies'] ?? []);
        $budgets = self::budgets($data['quality_budgets'] ?? []);
        $suppressions = self::stringList($data['dead_code_suppressions'] ?? [], 'dead_code_suppressions', 200);
        $webRoots = self::webRoots($data['web_roots'] ?? []);
        $enrichers = self::enrichers($data['enrichers'] ?? []);

        return new ProjectConfiguration(
            path: basename($path),
            ignores: $ignores,
            maxFiles: $limits['max_files'],
            maxFileBytes: $limits['max_file_bytes'],
            workerTimeoutMs: $limits['worker_timeout_ms'],
            workerMemoryMb: $limits['worker_memory_mb'],
            boundaries: $boundaries,
            frameworks: array_values(array_unique($frameworks)),
            snapshotRetention: $retention,
            policies: $policies,
            qualityBudgets: $budgets,
            deadCodeSuppressions: $suppressions,
            watchScanTimeoutMs: $limits['watch_scan_timeout_ms'],
            refreshBudgetMs: $limits['refresh_budget_ms'],
            webRoots: $webRoots,
            enrichers: $enrichers,
        );
    }

    /**
     * The configuration file's path and decoded contents, or null when the
     * project has none. `knossos.json` and `knossos.jsonc` side by side are
     * refused rather than one picked silently.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private static function readConfigFile(string $root): ?array
    {
        $json = $root . '/knossos.json';
        $jsonc = $root . '/knossos.jsonc';
        if (is_file($json) && is_file($jsonc)) {
            throw new DiscoveryException('PROJECT_CONFIG_AMBIGUOUS: keep only knossos.json or knossos.jsonc.');
        }
        $path = is_file($jsonc) ? $jsonc : (is_file($json) ? $json : null);
        if ($path === null) {
            return null;
        }
        $size = filesize($path);
        if (!is_int($size) || $size > 1_000_000) {
            throw new DiscoveryException('PROJECT_CONFIG_UNSAFE: project configuration exceeds 1000000 bytes.');
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new DiscoveryException('PROJECT_CONFIG_UNREADABLE: unable to read project configuration.');
        }

        return [$path, JsonConfig::decode($contents, str_ends_with($path, '.jsonc'))];
    }

    /**
     * The ignore patterns, each bounded and project-relative, and compiled now
     * so an invalid pattern is attributed to the configuration file rather
     * than surfacing mid-discovery on an arbitrary path.
     *
     * @return list<string>
     */
    private static function ignores(mixed $value): array
    {
        $ignores = self::stringList($value, 'ignores', 100);
        foreach ($ignores as $pattern) {
            if (strlen($pattern) > 500) {
                throw new DiscoveryException('PROJECT_CONFIG_UNSAFE: ignore patterns must be at most 500 bytes.');
            }
            if (!RelativePathRule::isContained($pattern)) {
                throw new DiscoveryException('PROJECT_CONFIG_UNSAFE: ignore patterns must be relative and may not contain parent traversal.');
            }
        }
        new IgnoreMatcher($ignores);

        return $ignores;
    }

    /**
     * The `limits` object, every setting bounded and null when absent.
     *
     * @return array{max_files: ?int, max_file_bytes: ?int, worker_timeout_ms: ?int, worker_memory_mb: ?int, watch_scan_timeout_ms: ?int, refresh_budget_ms: ?int}
     */
    private static function limits(mixed $value): array
    {
        $limits = self::object($value, 'limits');
        self::knownKeys($limits, self::LIMIT_KEYS, 'limits');

        return [
            'max_files' => self::optionalInteger($limits, 'max_files', 1, 100_000),
            'max_file_bytes' => self::optionalInteger($limits, 'max_file_bytes', 1, 100_000_000),
            'worker_timeout_ms' => self::optionalInteger(
                $limits,
                'worker_timeout_ms',
                WorkerExecutionPolicy::MIN_REQUEST_TIMEOUT_MS,
                WorkerExecutionPolicy::MAX_REQUEST_TIMEOUT_MS,
            ),
            'worker_memory_mb' => self::optionalInteger($limits, 'worker_memory_mb', 64, 65536),
            // How long one scan of the shared watcher may run: a project key, because the plugin's hooks start that watcher.
            'watch_scan_timeout_ms' => self::optionalInteger($limits, 'watch_scan_timeout_ms', 10_000, 3_600_000),
            // How long refresh_if_stale may rescan inside a query; read when the query runs, not by the scan.
            'refresh_budget_ms' => self::optionalInteger($limits, 'refresh_budget_ms', 0, 600_000),
        ];
    }

    /**
     * The boundaries, each uniquely and not reservedly named, with exactly one
     * of a project-relative `path_prefix` or a `namespace_prefix`.
     *
     * @return list<array<string, mixed>>
     */
    private static function boundaries(mixed $value): array
    {
        $boundaries = self::objectList($value, 'boundaries', 50);
        $seenBoundaryNames = [];
        foreach ($boundaries as $boundary) {
            self::knownKeys($boundary, self::BOUNDARY_KEYS, 'boundary');
            if (!is_string($boundary['name'] ?? null) || $boundary['name'] === '') {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: boundary name must be non-empty.');
            }
            if (in_array($boundary['name'], self::RESERVED_BOUNDARY_NAMES, true)) {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: boundary name ' . $boundary['name'] . ' is reserved for policy targets.');
            }
            if (isset($seenBoundaryNames[$boundary['name']])) {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: duplicate boundary name ' . $boundary['name'] . '.');
            }
            $seenBoundaryNames[$boundary['name']] = true;
            if (!isset($boundary['path_prefix']) && !isset($boundary['namespace_prefix'])) {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: boundary must declare path_prefix or namespace_prefix.');
            }
            if (isset($boundary['path_prefix']) && isset($boundary['namespace_prefix'])) {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: boundary must declare either path_prefix or namespace_prefix, not both.');
            }
            $pathPrefix = $boundary['path_prefix'] ?? null;
            if ($pathPrefix !== null && (!is_string($pathPrefix) || !RelativePathRule::isContained($pathPrefix))) {
                throw new DiscoveryException('PROJECT_CONFIG_UNSAFE: boundary path_prefix must be project-relative.');
            }
            if (isset($boundary['namespace_prefix']) && !is_string($boundary['namespace_prefix'])) {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: boundary namespace_prefix must be a string.');
            }
        }

        return $boundaries;
    }

    /**
     * The policies, each with an id, a source boundary and at least one
     * target list, every list bounded.
     *
     * @return list<array<string, mixed>>
     */
    private static function policies(mixed $value): array
    {
        $policies = self::objectList($value, 'policies', 50);
        foreach ($policies as $policy) {
            self::knownKeys($policy, self::POLICY_KEYS, 'policy');
            if (!is_string($policy['id'] ?? null) || $policy['id'] === '' || !is_string($policy['from_boundary'] ?? null) || $policy['from_boundary'] === '') {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: policies require non-empty id and from_boundary.');
            }
            if (!isset($policy['allow_targets']) && !isset($policy['deny_targets'])) {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: policies require allow_targets or deny_targets.');
            }
            foreach (['allow_targets', 'deny_targets', 'edge_kinds'] as $list) {
                if (isset($policy[$list])) {
                    self::stringList($policy[$list], 'policy ' . $list, 100);
                }
            }
        }

        return $policies;
    }

    /**
     * The `quality_budgets` object, each budget a known key with a bounded count.
     *
     * @return array<string, int>
     */
    private static function budgets(mixed $value): array
    {
        $budgets = self::object($value, 'quality_budgets');
        self::knownKeys($budgets, self::BUDGET_KEYS, 'quality_budgets');
        $typedBudgets = [];
        foreach ($budgets as $key => $budget) {
            if (!is_int($budget) || $budget < 0 || $budget > 100_000) {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: quality budget ' . $key . ' must be between 0 and 100000.');
            }
            $typedBudgets[$key] = $budget;
        }

        return $typedBudgets;
    }

    /**
     * The enricher names the project requests, each once. A project names an
     * installed enricher and never carries a command.
     *
     * @return list<string>
     */
    private static function enrichers(mixed $value): array
    {
        $enrichers = self::stringList($value, 'enrichers', 32);
        foreach ($enrichers as $enricher) {
            if (preg_match(EnricherDefinition::NAME_PATTERN, $enricher) !== 1) {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: enricher ' . $enricher . ' is not a name. A project requests an installed enricher by name and never carries a command.');
            }
        }

        return array_values(array_unique($enrichers));
    }

    /**
     * The URL prefixes a web server maps to project folders, which decide
     * the file a root-absolute script URL in an HTML page names.
     *
     * A prefix is a URL path that starts and ends with `/` (`/static/`); a
     * folder is project-relative and ends with `/` (`web/public/`), so a
     * prefix and its folder join without guessing where a slash belongs.
     *
     * @return array<string, string>
     */
    private static function webRoots(mixed $value): array
    {
        $roots = self::object($value, 'web_roots');
        if (count($roots) > 50) {
            throw new DiscoveryException('PROJECT_CONFIG_INVALID: web_roots may hold at most 50 entries.');
        }
        foreach ($roots as $prefix => $folder) {
            // A JSON key of digits arrives as an integer, and is no prefix either.
            if (!is_string($prefix) || !str_starts_with($prefix, '/') || !str_ends_with($prefix, '/')) {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: web_roots keys must be URL path prefixes that start and end with /.');
            }
            if (!is_string($folder) || $folder === '') {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: web_roots values must be non-empty strings.');
            }
            if (!str_ends_with($folder, '/') || !RelativePathRule::isContained($folder)) {
                throw new DiscoveryException('PROJECT_CONFIG_UNSAFE: web_roots folders must be project-relative, end with /, and may not contain parent traversal.');
            }
        }

        return $roots;
    }

    /**
     * Reject unrecognised configuration keys, so a typo is reported rather than ignored.
     *
     * @param array<string, mixed> $data @param list<string> $allowed
     */
    private static function knownKeys(array $data, array $allowed, string $scope): void
    {
        $unknown = array_values(array_diff(array_keys($data), $allowed));
        if ($unknown !== []) {
            throw new DiscoveryException('PROJECT_CONFIG_UNKNOWN_KEY: unknown ' . $scope . ' key(s): ' . implode(', ', $unknown) . '.');
        }
    }

    /**
     * A configuration value required to be an object.
     *
     * @return array<string, mixed>
     */
    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new DiscoveryException('PROJECT_CONFIG_INVALID: ' . $field . ' must be an object.');
        }
        return $value;
    }

    /**
     * A configuration value required to be a list of strings.
     *
     * @return list<string>
     */
    private static function stringList(mixed $value, string $field, int $limit): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $limit) {
            throw new DiscoveryException('PROJECT_CONFIG_INVALID: ' . $field . ' must be a bounded list.');
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: ' . $field . ' must contain non-empty strings.');
            }
        }
        return $value;
    }

    /**
     * A configuration value required to be a list of objects, such as boundary definitions.
     *
     * @return list<array<string, mixed>>
     */
    private static function objectList(mixed $value, string $field, int $limit): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $limit) {
            throw new DiscoveryException('PROJECT_CONFIG_INVALID: ' . $field . ' must be a bounded list.');
        }
        foreach ($value as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: ' . $field . ' entries must be objects.');
            }
        }
        return $value;
    }

    /**
     * An optional integer setting, validated within bounds when present.
     *
     * @param array<string, mixed> $data
     */
    private static function optionalInteger(array $data, string $key, int $minimum, int $maximum): ?int
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }
        $value = $data[$key];
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new DiscoveryException(sprintf('PROJECT_CONFIG_INVALID: %s must be between %d and %d.', $key, $minimum, $maximum));
        }
        return $value;
    }
}
