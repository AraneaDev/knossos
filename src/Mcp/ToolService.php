<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use InvalidArgumentException;
use Knossos\Cancellation\CancellationToken;
use Knossos\Discovery\RootGuard;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\Protocol\ProtocolNegotiator;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Result\ResultEnvelope;
use Knossos\Runtime\ServerEnvironment;
use Knossos\Scan\ProjectScanner;
use Knossos\Scan\ScanOptions;

/**
 * The MCP tool surface: schemas, validation, and dispatch.
 *
 * Definitions are declared as data so the same source produces the advertised
 * schemas, the argument validation, and the generated reference — three things
 * that drift apart when maintained separately. Arguments are rejected rather than
 * coerced, and unknown keys are an error, because a silently ignored parameter
 * reads to a caller as a parameter that had no effect.
 */
final readonly class ToolService
{
    /**
     * Tools whose answer reads the project's files or runs git in its root.
     * The database is shared with the CLI and can hold projects from anywhere
     * on the machine, so these are confined to the server's allowed roots the
     * same way a scan is. Graph-only tools answer for every project.
     */
    private const DISK_TOOLS = ['file_context', 'change_impact', 'changed_files_impact', 'test_impact', 'review_diff'];

    /** What repairs a stale graph before a read tool answers. */
    private StaleScanRefresher $refresher;

    public function __construct(
        // The interface rather than ProjectScanService: scan() is the only
        // thing ever called on it, and WatchService and WatchScanAttempt
        // already take ProjectScanner, so this was the last consumer naming
        // the implementation for no reason. A test injecting a scanner that
        // fails a particular way follows from that; it is not the reason.
        private ProjectScanner $scanner,
        private ArchitectureQueryService $queries,
        private DatabaseMaintenanceService $maintenance,
        private ResultEnricher $enricher,
        // Optional so the many fixtures that build a ToolService directly need
        // no runtime wiring; the server binaries always supply one.
        private ?ServerEnvironment $environment = null,
    ) {
        $this->refresher = new StaleScanRefresher($scanner, $queries, $environment);
    }

    /**
     * The advertised tool list, filtered to what this wiring can actually answer.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return ToolCatalog::definitions($this->environment !== null);
    }

    /**
     * Validate a tool call, apply the shared read options, and dispatch it.
     *
     * @param array<string, mixed> $arguments
     */
    public function call(string $name, array $arguments, ?CancellationToken $cancellation = null): ResultEnvelope
    {
        $schema = ToolCatalog::schemaFor($name);
        if ($schema === null) {
            throw new ToolInputException(sprintf('Unknown tool: %s', $name));
        }
        $declared = $schema['properties'];

        // Common options are honoured centrally only for the tools whose schema
        // actually declares them; passing one to a tool that does not (e.g.
        // refresh_if_stale to maintain_database) is left in $arguments so the
        // key validation below rejects it as unknown.
        [$response, $arguments] = ResponseOptions::take($name, $declared, $arguments);
        // On by default: an agent that has to ask for a fresh graph pays two
        // round trips to discover it needed one. RefreshPolicy is what makes
        // that safe, by skipping any refresh that would cost more than the
        // budget. KNOSSOS_AUTO_REFRESH=0 restores the previous behaviour.
        //
        // Only the literal string '0' disables it: 'false', 'off', 'no' and
        // even 'FALSE' all still read as enabled, the same idiom
        // ProtocolNegotiator::legacyEnabled() uses for KNOSSOS_LEGACY_PROTOCOL.
        // Consistency between the two operator switches is worth more than
        // accommodating spellings nobody has asked for; if that ever changes,
        // change both switches together, not just one.
        $refreshRequested = getenv('KNOSSOS_AUTO_REFRESH') !== '0';
        if (in_array('refresh_if_stale', $declared, true) && array_key_exists('refresh_if_stale', $arguments)) {
            $refresh = $arguments['refresh_if_stale'];
            unset($arguments['refresh_if_stale']);
            if (!is_bool($refresh)) {
                throw new ToolInputException('refresh_if_stale must be a boolean.');
            }
            $refreshRequested = $refresh;
        }

        // Validate the request's keys before any (potentially expensive) rescan
        // so a malformed request cannot trigger a refresh it will never use.
        self::validateKeys($arguments, $schema);
        $this->assertWithinRoots($name, $arguments);
        // Every value is validated here too, before the refresh below, so a
        // call that is going to be refused costs no rescan.
        $run = $this->prepare($name, $arguments, $cancellation);

        $refreshWarnings = [];
        $probed = null;
        // Gated on the tool's own schema, not on an exclude-list: declaring
        // refresh_if_stale is exactly what marks a tool as one whose answer
        // may be repaired by a rescan first. A tool that does not declare it
        // (every graph-mutating tool, plus server_info/diagnose_runtime) must
        // never trigger one as a side effect of being called — remove_project
        // triggering a full rescan of the very project it is about to delete
        // is the case this guards against.
        if ($refreshRequested && in_array('refresh_if_stale', $declared, true)) {
            [$refreshWarnings, $probed] = $this->refresher->refreshIfStale($arguments, $cancellation);
        }
        $envelope = $run();
        if ($refreshWarnings !== []) {
            $envelope = $envelope->withWarnings($refreshWarnings);
        }
        // The verdict this call already reached is handed to the enricher
        // rather than derived a second time. It is withheld whenever a rescan
        // ran, because a repaired graph is no longer the graph that was probed.
        return $this->enricher->enrich($envelope, $name, $response->verbosity, $response->maxChars, $probed);
    }

    /**
     * The only top-level key check: every remaining argument must be declared
     * and every non-common required key present. Driven by ToolCatalog, so the
     * advertised schema and the accepted arguments cannot drift apart, and run
     * before refresh_if_stale so an invalid request triggers no rescan.
     *
     * @param array<string, mixed> $arguments
     * @param array{properties: list<string>, required: list<string>} $schema
     */
    private static function validateKeys(array $arguments, array $schema): void
    {
        $required = array_diff($schema['required'], ['verbosity', 'max_chars', 'refresh_if_stale']);
        foreach ($required as $key) {
            if (!array_key_exists($key, $arguments)) {
                throw new ToolInputException(sprintf('Missing required argument: %s', $key));
            }
        }
        $unknown = array_diff(array_keys($arguments), $schema['properties']);
        if ($unknown !== []) {
            throw new ToolInputException(sprintf('Unknown argument: %s', reset($unknown)));
        }
    }

    /**
     * Refuses a tool that reads disk or git when its project lies outside the allowed roots.
     *
     * @param array<string, mixed> $arguments
     */
    private function assertWithinRoots(string $name, array $arguments): void
    {
        if ($this->environment === null) {
            return;
        }
        $readsDisk = in_array($name, self::DISK_TOOLS, true)
            || ($name === 'architecture_context' && ($arguments['include_source'] ?? false) === true);
        $projectId = ToolArguments::normalized($arguments['project_id'] ?? null);
        if (!$readsDisk || $projectId === '') {
            return;
        }
        $root = $this->queries->projectRoot($projectId);
        // A missing root is the tool's own answer to give; reporting it as
        // "outside the roots" would send the caller to fix the wrong thing.
        if ($root === null || !RootGuard::exists($root)) {
            return;
        }
        (new RootGuard($this->environment->roots))->resolve($root);
    }

    /**
     * Route a call to its handler, which validates every argument now and
     * returns the work as a closure. Parsing eagerly is the point: call() runs
     * this before refresh_if_stale, so a call that is going to be refused
     * fails before any rescan. A handler's closure only calls the service.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function prepare(string $name, array $arguments, ?CancellationToken $cancellation): \Closure
    {
        $spec = ToolArgumentSpec::for($name);
        $run = match ($name) {
            // Nothing to parse: the whole handler is the work.
            'server_info' => fn(): ResultEnvelope => $this->serverInfo($arguments),
            'diagnose_runtime' => fn(): ResultEnvelope => $this->diagnoseRuntime($arguments),
            'list_projects' => $this->projects($arguments, $spec),
            'scan_project' => $this->scan($arguments, $cancellation),
            'list_snapshots' => $this->snapshots($arguments, $spec),
            'snapshot_diff' => $this->snapshotDiff($arguments, $spec),
            'quality_gate' => $this->qualityGate($arguments, $spec),
            'architecture_trends' => $this->architectureTrends($arguments, $spec),
            'find_component' => $this->find($arguments, $spec),
            'inspect_component' => $this->inspect($arguments, $spec),
            'list_usages' => $this->listUsages($arguments, $spec),
            'architecture_summary' => $this->summary($arguments, $spec),
            'export_agent_brief' => $this->exportAgentBrief($arguments, $spec),
            'file_metrics' => $this->fileMetrics($arguments, $spec),
            'file_context' => $this->fileContext($arguments),
            'list_diagnostics' => $this->listDiagnostics($arguments, $spec),
            'explain_flow' => $this->flow($arguments, $spec),
            'impact_analysis' => $this->impact($arguments, $spec),
            'dependency_cycles' => $this->cycles($arguments, $spec),
            'architecture_health' => $this->health($arguments, $spec),
            'check_architecture' => $this->check($arguments, $spec),
            'suggest_location' => $this->suggest($arguments, $spec),
            'change_impact' => $this->changeImpact($arguments, $spec),
            'changed_files_impact' => $this->changedFilesImpact($arguments, $spec),
            'test_impact' => $this->testImpact($arguments, $spec),
            'review_diff' => $this->reviewDiff($arguments, $spec),
            'architecture_context' => $this->architectureContext($arguments, $spec),
            'export_diagram' => $this->diagram($arguments, $spec),
            'list_boundaries' => $this->boundaries($arguments, $spec),
            'search_architecture' => $this->search($arguments, $spec),
            'list_annotations' => $this->listAnnotations($arguments, $spec),
            'annotate_component' => $this->annotateComponent($arguments, $spec),
            'remove_project' => $this->removeProject($arguments, $spec),
            'move_project' => $this->moveProject($arguments, $spec),
            'cleanup_stale_scans' => $this->cleanupStaleScans($arguments, $spec),
            'maintain_database' => $this->maintainDatabase($arguments, $spec),
            default => throw new InvalidArgumentException(sprintf('Unknown tool: %s', $name)),
        };
        // Enums, policies and budgets: the rules the services apply, run now so
        // a call that is going to be refused fails before any rescan.
        ToolArgumentPreflight::check($name, $arguments);

        return $run;
    }

    /**
     * Report the roots this server may read, the file to extend, and whether it is containerised.
     *
     * @param array<string, mixed> $arguments
     */
    private function serverInfo(array $arguments): ResultEnvelope
    {
        $environment = $this->requireEnvironment('server_info');
        $info = $environment->describe(ProtocolNegotiator::supported(), ProtocolNegotiator::legacyEnabled());
        /** @var list<array{path: string, source: string, exists: bool}> $roots */
        $roots = $info['allowed_roots'];
        /** @var list<string> $unreachable */
        $unreachable = $info['unreachable_roots'];

        $warnings = [];
        if ($roots === []) {
            $warnings[] = sprintf(
                'No roots are configured, so no project can be scanned. Create %s containing {"roots": ["/absolute/path"]}; it is re-read per request.',
                (string) ($info['roots_file'] ?? '<no roots file configured>'),
            );
        }
        if ($unreachable !== []) {
            // Almost always a host path handed to a containerised server, or a
            // root configured on another machine. Both look fine until a scan.
            $warnings[] = 'These configured roots do not exist on this server: ' . implode(', ', $unreachable);
        }

        return new ResultEnvelope(
            'server',
            '',
            sprintf('Knossos %s with %d allowed root(s).', (string) $info['version'], count($roots)),
            $info,
            warnings: $warnings,
        );
    }

    /**
     * Run the runtime and worker checks, summarising failures as warnings.
     *
     * @param array<string, mixed> $arguments
     */
    private function diagnoseRuntime(array $arguments): ResultEnvelope
    {
        $result = $this->requireEnvironment('diagnose_runtime')->doctor()->run();
        $failed = array_values(array_filter($result['checks'], static fn(array $check): bool => $check['status'] !== 'ok'));

        return new ResultEnvelope(
            'server',
            '',
            $result['ok']
                ? sprintf('All %d runtime checks passed%s.', count($result['checks']), $result['warnings'] > 0 ? sprintf(', %d with a warning', $result['warnings']) : '')
                : sprintf('%d of %d runtime checks failed.', count($failed), count($result['checks'])),
            $result,
            warnings: array_map(static fn(array $check): string => $check['name'] . ': ' . $check['detail'], $failed),
        );
    }
    /** The runtime environment, or a clear error when this server was built without one. */

    private function requireEnvironment(string $tool): ServerEnvironment
    {
        // Unreachable through a server binary, which always wires an
        // environment; reachable only if a caller builds a ToolService by hand.
        return $this->environment ?? throw new InvalidArgumentException(
            sprintf('%s is unavailable: this server was built without runtime environment wiring.', $tool),
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\AnnotationService::removeAnnotation()}
     * when `remove` is set, else to {@see \Knossos\Query\AnnotationService::upsertAnnotation()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function annotateComponent(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $projectId = ToolArguments::string($arguments, 'project_id');
        $component = ToolArguments::string($arguments, 'component');
        $kind = ToolArguments::string($arguments, 'kind');
        // Stored as given: a note's whitespace is content.
        $value = ToolArguments::text($arguments, 'value', 2000, allowEmpty: true, default: '', trim: false);
        $remove = $spec->boolean($arguments, 'remove');
        $execute = $spec->boolean($arguments, 'execute');
        $evidence = ToolArguments::strings($arguments, 'evidence_paths', 5);
        $replace = $spec->boolean($arguments, 'replace');

        return $remove
            ? fn(): ResultEnvelope => $this->queries->services()->annotationQueries->removeAnnotation($projectId, $component, $kind, $value, $execute)
            : fn(): ResultEnvelope => $this->queries->services()->annotationQueries->upsertAnnotation($projectId, $component, $kind, $value, $execute, 'model', $evidence, $replace);
    }

    /**
     * Validates the tool arguments and forwards to {@see DatabaseMaintenanceService::removeProject()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function removeProject(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            $spec->boolean($arguments, 'execute'),
        ];

        return fn(): ResultEnvelope => $this->maintenance->removeProject(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see DatabaseMaintenanceService::moveProject()}.
     *
     * Never passes a roots file: roots.json is this server's read boundary, and
     * a tool able to extend it would let a caller grant itself any directory. A
     * target outside it is refused with the command that grants it instead, and
     * so is a relative one, which would resolve against a working directory the
     * caller cannot see.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function moveProject(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $from = ToolArguments::string($arguments, 'from');
        $to = ToolArguments::string($arguments, 'to');
        $execute = $spec->boolean($arguments, 'execute');
        if (!str_starts_with($to, '/')) {
            throw new \InvalidArgumentException(sprintf('MOVE_TARGET_NOT_ABSOLUTE: %s must be an absolute path.', $to));
        }
        if ($this->environment !== null && !$this->environment->admitsRoot($to)) {
            throw new \InvalidArgumentException(sprintf(
                'MOVE_TARGET_NOT_ALLOWED: %1$s is not an allowed root. Run `knossos allow-root %1$s --execute`, or move from the shell with `knossos move-project %2$s %1$s --execute`.',
                $to,
                $from,
            ));
        }

        return fn(): ResultEnvelope => $this->maintenance->moveProject($from, $to, $execute, $this->scanner);
    }

    /**
     * Validates the tool arguments and forwards to {@see DatabaseMaintenanceService::cleanupStaleScans()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function cleanupStaleScans(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            $spec->integer($arguments, 'older_than_hours'),
            $spec->boolean($arguments, 'execute'),
        ];

        return fn(): ResultEnvelope => $this->maintenance->cleanupStaleScans(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see DatabaseMaintenanceService::maintain()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function maintainDatabase(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'action'),
            $spec->boolean($arguments, 'execute'),
            array_key_exists('backup_name', $arguments) ? ToolArguments::text($arguments, 'backup_name', 127) : null,
        ];

        return fn(): ResultEnvelope => $this->maintenance->maintain(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\ProjectCatalogQueryService::listProjects()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function projects(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            $spec->integer($arguments, 'limit'),
            $spec->integer($arguments, 'offset'),
            $spec->boolean($arguments, 'include_roots'),
        ];

        return fn(): ResultEnvelope => $this->queries->services()->catalogQueries->listProjects(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\ProjectCatalogQueryService::listSnapshots()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function snapshots(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            $spec->integer($arguments, 'limit'),
            $spec->integer($arguments, 'offset'),
        ];

        return fn(): ResultEnvelope => $this->queries->services()->catalogQueries->listSnapshots(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\SnapshotDiffQuery::snapshotDiff()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function snapshotDiff(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::string($arguments, 'from_snapshot'),
            $spec->string($arguments, 'to_snapshot'),
            $spec->integer($arguments, 'max_changes'),
        ];

        return fn(): ResultEnvelope => $this->queries->services()->diffQueries->snapshotDiff(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\QualityGateQueryService::qualityGate()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function qualityGate(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $budgets = $arguments['budgets'];
        $policies = $arguments['policies'] ?? [];
        // An empty JSON object decodes to []; accept it as "no budgets" rather
        // than mistaking it for a list.
        if (!is_array($budgets) || ($budgets !== [] && array_is_list($budgets)) || !is_array($policies) || !array_is_list($policies)) {
            throw new InvalidArgumentException('budgets must be an object and policies must be a list.');
        }
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::string($arguments, 'baseline_snapshot'),
            $budgets,
            $policies,
            $spec->boolean($arguments, 'sarif'),
            $spec->boolean($arguments, 'propose_baseline'),
        ];

        return fn(): ResultEnvelope => $this->queries->services()->gateQueries->qualityGate(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\QualityGateQueryService::architectureTrends()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function architectureTrends(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            $spec->integer($arguments, 'limit'),
            array_key_exists('release_from', $arguments) ? ToolArguments::string($arguments, 'release_from') : null,
        ];

        return fn(): ResultEnvelope => $this->queries->services()->gateQueries->architectureTrends(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ProjectScanService::scan()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function scan(array $arguments, ?CancellationToken $cancellation): \Closure
    {
        $path = ToolArguments::string($arguments, 'path');
        $name = array_key_exists('name', $arguments) ? ToolArguments::string($arguments, 'name') : null;
        $maxFiles = array_key_exists('max_files', $arguments) ? ToolArguments::integer($arguments, 'max_files', 100_000, 1, 100_000) : null;
        $maxBytes = array_key_exists('max_file_bytes', $arguments) ? ToolArguments::integer($arguments, 'max_file_bytes', 2_000_000, 1, 100_000_000) : null;

        // scan_project is an explicit scan: it runs the project's external enrichers.
        $options = new ScanOptions(
            $name,
            $maxFiles,
            $maxBytes,
            array_key_exists('boundaries', $arguments) ? ToolArguments::boundariesArgument($arguments) : null,
            array_key_exists('mode', $arguments) ? ToolArguments::string($arguments, 'mode') : null,
            $cancellation,
            array_key_exists('snapshot_retention', $arguments) ? ToolArguments::integer($arguments, 'snapshot_retention', 5, 0, 20) : null,
            array_key_exists('worker_timeout_ms', $arguments) ? ToolArguments::integer($arguments, 'worker_timeout_ms', 30_000, 1_000, 120_000) : null,
            runEnrichers: true,
        );

        return fn(): ResultEnvelope => $this->scanner->scan($path, $options);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::findComponent()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function find(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::string($arguments, 'name'),
            $spec->integer($arguments, 'limit'),
        ];

        return fn(): ResultEnvelope => $this->queries->findComponent(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::inspectComponent()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function inspect(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::string($arguments, 'component'),
            $spec->integer($arguments, 'max_relationships'),
            $spec->integer($arguments, 'max_children'),
            $spec->string($arguments, 'min_confidence'),
        ];

        return fn(): ResultEnvelope => $this->queries->inspectComponent(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::listUsages()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function listUsages(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::string($arguments, 'symbol'),
            ToolArguments::strings($arguments, 'edge_kinds'),
            $spec->string($arguments, 'min_confidence'),
            $spec->integer($arguments, 'limit'),
            $spec->boolean($arguments, 'exclude_tests'),
        ];

        return fn(): ResultEnvelope => $this->queries->listUsages(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\GraphSummaryQuery::architectureSummary()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function summary(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            $spec->integer($arguments, 'limit'),
        ];

        return fn(): ResultEnvelope => $this->queries->services()->summaryQueries->architectureSummary(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\AgentBriefService::exportAgentBrief()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function exportAgentBrief(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            $spec->integer($arguments, 'max_chars'),
        ];

        return fn(): ResultEnvelope => $this->queries->services()->briefQueries->exportAgentBrief(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\FileMetricsQueryService::fileMetrics()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function fileMetrics(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            array_key_exists('path_contains', $arguments) ? ToolArguments::text($arguments, 'path_contains', 1000) : null,
            array_key_exists('language', $arguments) ? ToolArguments::text($arguments, 'language', 100) : null,
            $spec->string($arguments, 'sort_by'),
            $spec->string($arguments, 'order'),
            $spec->integer($arguments, 'limit'),
            $spec->integer($arguments, 'offset'),
        ];

        return fn(): ResultEnvelope => $this->queries->services()->fileMetricsQueries->fileMetrics(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\DiagnosticsQueryService::listDiagnostics()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function listDiagnostics(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            array_key_exists('severity', $arguments) ? ToolArguments::string($arguments, 'severity') : null,
            array_key_exists('path_prefix', $arguments) ? ToolArguments::string($arguments, 'path_prefix') : null,
            $spec->integer($arguments, 'limit'),
            $spec->integer($arguments, 'offset'),
        ];

        return fn(): ResultEnvelope => $this->queries->services()->diagnosticsQueries->listDiagnostics(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::fileContext()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function fileContext(array $arguments): \Closure
    {
        $args = [ToolArguments::string($arguments, 'project_id'), ToolArguments::string($arguments, 'path')];

        return fn(): ResultEnvelope => $this->queries->fileContext(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::explainFlow()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function flow(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::string($arguments, 'from'),
            ToolArguments::string($arguments, 'to'),
            $spec->integer($arguments, 'max_depth'),
            $spec->integer($arguments, 'max_paths'),
            ToolArguments::strings($arguments, 'edge_kinds'),
            $spec->string($arguments, 'min_confidence'),
            $spec->integer($arguments, 'timeout_ms'),
            $spec->boolean($arguments, 'both_directions'),
            array_key_exists('profile', $arguments) ? ToolArguments::string($arguments, 'profile') : null,
        ];

        return fn(): ResultEnvelope => $this->queries->explainFlow(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::impactAnalysis()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function impact(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::string($arguments, 'symbol'),
            $spec->integer($arguments, 'max_depth'),
            $spec->integer($arguments, 'limit'),
            ToolArguments::strings($arguments, 'edge_kinds'),
            $spec->string($arguments, 'min_confidence'),
            $spec->integer($arguments, 'timeout_ms'),
            $spec->boolean($arguments, 'seed_members'),
            $spec->boolean($arguments, 'rings'),
        ];

        return fn(): ResultEnvelope => $this->queries->impactAnalysis(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\DependencyCycleQuery::dependencyCycles()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function cycles(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::strings($arguments, 'edge_kinds'),
            $spec->string($arguments, 'min_confidence'),
            $spec->integer($arguments, 'limit'),
            $spec->integer($arguments, 'max_nodes'),
            $spec->integer($arguments, 'max_edges'),
            $spec->integer($arguments, 'timeout_ms'),
            $spec->boolean($arguments, 'include_self_loops'),
        ];
        $withinType = $spec->boolean($arguments, 'within_type');

        return fn(): ResultEnvelope => $this->queries->services()->cycleQueries->dependencyCycles(...$args, withinType: $withinType);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\ArchitectureHealthQuery::architectureHealth()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function health(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::strings($arguments, 'edge_kinds'),
            $spec->string($arguments, 'min_confidence'),
            $spec->integer($arguments, 'limit'),
            $spec->integer($arguments, 'max_nodes'),
            $spec->integer($arguments, 'max_edges'),
            $spec->integer($arguments, 'timeout_ms'),
            $spec->boolean($arguments, 'include_external'),
            $spec->boolean($arguments, 'include_tests'),
            $spec->string($arguments, 'candidate_confidence'),
            $spec->integer($arguments, 'candidate_offset'),
            $spec->integer($arguments, 'candidate_timeout_ms'),
        ];

        return fn(): ResultEnvelope => $this->queries->services()->healthQueries->architectureHealth(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\ArchitecturePolicyQueryService::checkArchitecture()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function check(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        // Absent, the project's declared policies are checked.
        $policies = $arguments['policies'] ?? null;
        if ($policies !== null && (!is_array($policies) || !array_is_list($policies))) {
            throw new InvalidArgumentException('policies must be a list.');
        }
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            $policies,
            $spec->string($arguments, 'min_confidence'),
            $spec->integer($arguments, 'limit'),
            $spec->integer($arguments, 'max_edges'),
            $spec->integer($arguments, 'timeout_ms'),
        ];

        return fn(): ResultEnvelope => $this->queries->services()->policyQueries->checkArchitecture(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\LocationSuggestionService::suggestLocation()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function suggest(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::text($arguments, 'feature_description', 2000),
            $spec->integer($arguments, 'limit'),
            $spec->integer($arguments, 'max_members'),
            $spec->integer($arguments, 'max_edges'),
            $spec->integer($arguments, 'timeout_ms'),
            $spec->string($arguments, 'ranking_mode'),
        ];

        return fn(): ResultEnvelope => $this->queries->services()->locationQueries->suggestLocation(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\ChangeImpactQueryService::changeImpact()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function changeImpact(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::string($arguments, 'symbol'),
            $spec->integer($arguments, 'since_days'),
            $spec->integer($arguments, 'max_commits'),
            $spec->integer($arguments, 'max_depth'),
            $spec->integer($arguments, 'limit'),
            ToolArguments::strings($arguments, 'edge_kinds'),
            $spec->string($arguments, 'min_confidence'),
            $spec->integer($arguments, 'timeout_ms'),
        ];

        return fn(): ResultEnvelope => $this->queries->services()->changeQueries->changeImpact(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\ChangeImpactQueryService::changedFilesImpact()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function changedFilesImpact(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::strings($arguments, 'files', 50),
            $spec->boolean($arguments, 'working_tree'),
            array_key_exists('base_ref', $arguments) ? ToolArguments::text($arguments, 'base_ref', 200) : null,
            $spec->integer($arguments, 'max_depth'),
            $spec->integer($arguments, 'limit'),
            ToolArguments::strings($arguments, 'edge_kinds'),
            $spec->string($arguments, 'min_confidence'),
            $spec->integer($arguments, 'timeout_ms'),
        ];

        return fn(): ResultEnvelope => $this->queries->services()->changeQueries->changedFilesImpact(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\ChangeImpactQueryService::testImpact()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function testImpact(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::strings($arguments, 'files', 50),
            $spec->boolean($arguments, 'working_tree'),
            array_key_exists('base_ref', $arguments) ? ToolArguments::text($arguments, 'base_ref', 200) : null,
            $spec->integer($arguments, 'max_depth'),
            $spec->integer($arguments, 'limit'),
            ToolArguments::strings($arguments, 'edge_kinds'),
            $spec->string($arguments, 'min_confidence'),
            $spec->integer($arguments, 'timeout_ms'),
        ];

        return fn(): ResultEnvelope => $this->queries->services()->changeQueries->testImpact(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\ReviewDiffService::reviewDiff()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function reviewDiff(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $policies = $arguments['policies'] ?? null;
        if ($policies !== null && (!is_array($policies) || !array_is_list($policies))) {
            throw new InvalidArgumentException('policies must be a list.');
        }
        $budgets = $arguments['budgets'] ?? null;
        if ($budgets !== null && (!is_array($budgets) || array_is_list($budgets))) {
            throw new InvalidArgumentException('budgets must be an object.');
        }
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            array_key_exists('base_ref', $arguments) ? ToolArguments::text($arguments, 'base_ref', 200) : null,
            ToolArguments::strings($arguments, 'files', 50),
            $policies,
            $budgets,
            array_key_exists('baseline_snapshot', $arguments) ? ToolArguments::string($arguments, 'baseline_snapshot') : null,
            $spec->integer($arguments, 'max_depth'),
            $spec->integer($arguments, 'limit'),
            $spec->string($arguments, 'min_confidence'),
            $spec->integer($arguments, 'timeout_ms'),
        ];

        return fn(): ResultEnvelope => $this->queries->services()->reviewQueries->reviewDiff(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\ArchitectureContextService::architectureContext()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function architectureContext(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::text($arguments, 'task_description', 2000, allowEmpty: true, default: ''),
            ToolArguments::strings($arguments, 'files', 50),
            $spec->integer($arguments, 'max_chars'),
            $spec->integer($arguments, 'timeout_ms'),
            $spec->boolean($arguments, 'include_source'),
        ];

        return fn(): ResultEnvelope => $this->queries->services()->contextQueries->architectureContext(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\DiagramExportService::exportDiagram()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function diagram(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            $spec->string($arguments, 'format'),
            array_key_exists('boundary', $arguments) ? ToolArguments::string($arguments, 'boundary') : null,
            ToolArguments::strings($arguments, 'edge_kinds'),
            $spec->string($arguments, 'min_confidence'),
            $spec->string($arguments, 'direction'),
            $spec->integer($arguments, 'max_nodes'),
            $spec->integer($arguments, 'max_edges'),
        ];

        return fn(): ResultEnvelope => $this->queries->services()->diagramQueries->exportDiagram(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\GraphSummaryQuery::listBoundaries()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function boundaries(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            array_key_exists('source', $arguments) ? ToolArguments::string($arguments, 'source') : null,
            $spec->integer($arguments, 'limit'),
            $spec->integer($arguments, 'offset'),
        ];

        return fn(): ResultEnvelope => $this->queries->services()->summaryQueries->listBoundaries(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::searchArchitecture()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function search(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::string($arguments, 'query'),
            ToolArguments::strings($arguments, 'kinds'),
            ToolArguments::strings($arguments, 'roles'),
            ToolArguments::strings($arguments, 'boundary_ids'),
            ToolArguments::strings($arguments, 'confidences'),
            $spec->integer($arguments, 'limit'),
            $spec->integer($arguments, 'offset'),
            $spec->boolean($arguments, 'files'),
        ];

        return fn(): ResultEnvelope => $this->queries->searchArchitecture(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see \Knossos\Query\AnnotationService::listAnnotations()}.
     *
     * @param array<string, mixed> $arguments
     * @param ToolArgumentSpec $spec the tool's declared defaults and bounds
     * @return \Closure(): ResultEnvelope
     */
    private function listAnnotations(array $arguments, ToolArgumentSpec $spec): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            array_key_exists('component', $arguments) ? ToolArguments::string($arguments, 'component') : null,
            array_key_exists('kind', $arguments) ? ToolArguments::string($arguments, 'kind') : null,
            $spec->integer($arguments, 'limit'),
            $spec->integer($arguments, 'offset'),
        ];

        return fn(): ResultEnvelope => $this->queries->services()->annotationQueries->listAnnotations(...$args);
    }
}
