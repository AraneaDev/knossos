<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use Closure;
use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Result\ResultEnvelope;
use LogicException;

/**
 * What each query tool calls, and with which arguments in which order: the one
 * table both front ends build their calls from.
 *
 * {@see ToolService} reads an MCP call's arguments through it, and the CLI's
 * {@see \Knossos\Cli\Command\QueryCommand} builds the same arguments from its
 * positionals and options and then reads them the same way. Before, each tool
 * was written out three times, the schema in {@see ToolCatalog}, an adapter in
 * ToolService and another in QueryCommand, and two of the ToolService adapters'
 * docblocks had already come to describe a different tool.
 *
 * An argument is read as its schema declares it ({@see ToolArgumentSpec::read()}),
 * so a default, a bound, a maxLength or a maxItems lives only in the catalogue.
 * Per argument the table adds only what the schema cannot say: that the CLI
 * takes it as a positional (and the error when it is missing), or under an
 * option whose name is not the argument's in kebab case, or a reader of its
 * own where the tool's contract is not the schema's plain reading.
 *
 * The tools in {@see self::HAND_WRITTEN} keep their own adapters in ToolService:
 * they scan, describe the server, or maintain the database, rather than ask
 * one query service one question.
 */
final class ToolBindings
{
    /** The tools ToolService answers with an adapter of its own rather than from this table. */
    public const HAND_WRITTEN = [
        'server_info', 'diagnose_runtime', 'scan_project',
        'remove_project', 'move_project', 'cleanup_stale_scans', 'maintain_database',
    ];

    private function __construct() {}

    /** Whether `$tool` is answered from this table. */
    public static function binds(string $tool): bool
    {
        return isset(self::table()[$tool]);
    }

    /**
     * The arguments `$tool` passes, in the order its service method takes them.
     *
     * @return array<string, array{positional?: string|null, rest?: true, option?: string, read?: Closure(array<string, mixed>): mixed}>
     */
    public static function arguments(string $tool): array
    {
        return self::binding($tool)['arguments'];
    }

    /**
     * The arguments the CLI takes as positionals, in order: each with the
     * error a missing one raises (null when it may be left out), and whether
     * it takes every positional left (a list of files).
     *
     * @return list<array{argument: string, missing: string|null, rest: bool}>
     */
    public static function positionals(string $tool): array
    {
        $positionals = [];
        foreach (self::arguments($tool) as $argument => $declared) {
            if (array_key_exists('positional', $declared) || isset($declared['rest'])) {
                $positionals[] = ['argument' => $argument, 'missing' => $declared['positional'] ?? null, 'rest' => isset($declared['rest'])];
            }
        }

        return $positionals;
    }

    /**
     * The CLI option that sets each argument the CLI does not take as a
     * positional: the argument's name in kebab case unless the table names
     * another.
     *
     * @return array<string, string> option => argument
     */
    public static function options(string $tool): array
    {
        $options = [];
        foreach (self::arguments($tool) as $argument => $declared) {
            if (!array_key_exists('positional', $declared) && !isset($declared['rest'])) {
                $options[$declared['option'] ?? str_replace('_', '-', $argument)] = $argument;
            }
        }

        return $options;
    }

    /**
     * Every argument `$tool` passes, validated and in call order, from the
     * MCP-shaped `$arguments`. Throws InvalidArgumentException with the
     * message the caller sees for the first one that is refused.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function read(string $tool, array $arguments): array
    {
        $binding = self::binding($tool);
        if (isset($binding['guard'])) {
            ($binding['guard'])($arguments);
        }
        $spec = ToolArgumentSpec::for($tool);
        $values = [];
        foreach ($binding['arguments'] as $argument => $declared) {
            $values[$argument] = isset($declared['read']) ? ($declared['read'])($arguments) : $spec->read($arguments, $argument);
        }

        return $values;
    }

    /**
     * Ask the query `$tool` is bound to, with the values {@see self::read()} returned.
     *
     * @param array<string, mixed> $values
     */
    public static function call(string $tool, ArchitectureQueryService $queries, array $values): ResultEnvelope
    {
        return (self::binding($tool)['call'])($queries, array_values($values), $values);
    }

    /**
     * One tool's row.
     *
     * @return array{call: Closure(ArchitectureQueryService, list<mixed>, array<string, mixed>): ResultEnvelope, arguments: array<string, array{positional?: string|null, rest?: true, option?: string, read?: Closure(array<string, mixed>): mixed}>, guard?: Closure(array<string, mixed>): void}
     */
    private static function binding(string $tool): array
    {
        return self::table()[$tool] ?? throw new LogicException(sprintf('No binding for %s.', $tool));
    }

    /**
     * Every bound tool, built once.
     *
     * @return array<string, array{call: Closure(ArchitectureQueryService, list<mixed>, array<string, mixed>): ResultEnvelope, arguments: array<string, array{positional?: string|null, rest?: true, option?: string, read?: Closure(array<string, mixed>): mixed}>, guard?: Closure(array<string, mixed>): void}>
     */
    private static function table(): array
    {
        /** @var array<string, array{call: Closure(ArchitectureQueryService, list<mixed>, array<string, mixed>): ResultEnvelope, arguments: array<string, array{positional?: string|null, rest?: true, option?: string, read?: Closure(array<string, mixed>): mixed}>, guard?: Closure(array<string, mixed>): void}>|null $table */
        static $table = null;

        return $table ??= [...self::projectTools(), ...self::componentTools(), ...self::analysisTools(), ...self::changeTools()];
    }

    /**
     * A positional the CLI requires, with the error a missing one raises, or
     * one it may leave out (null).
     *
     * @return array{positional: string|null}
     */
    private static function at(?string $missing): array
    {
        return ['positional' => $missing];
    }

    /**
     * Every positional left over, as a list.
     *
     * @return array{rest: true}
     */
    private static function rest(): array
    {
        return ['rest' => true];
    }

    /**
     * An option whose CLI name is not the argument's in kebab case.
     *
     * @return array{option: string}
     */
    private static function as(string $option): array
    {
        return ['option' => $option];
    }

    /**
     * The project catalogue, history and gate tools.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function projectTools(): array
    {
        return [
            'list_projects' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->catalogQueries->listProjects(...$v),
                'arguments' => ['limit' => [], 'offset' => [], 'include_roots' => []],
            ],
            'list_snapshots' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->catalogQueries->listSnapshots(...$v),
                'arguments' => ['project_id' => self::at('Usage: knossos list-snapshots <path|project-id> [--limit=N] [--offset=N]'), 'limit' => [], 'offset' => []],
            ],
            'snapshot_diff' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->diffQueries->snapshotDiff(...$v),
                'arguments' => [
                    'project_id' => self::at('Usage: knossos snapshot-diff <path|project-id> <from-snapshot> [to-snapshot]'),
                    'from_snapshot' => self::at('A source snapshot is required.'),
                    'to_snapshot' => self::at(null),
                    'max_changes' => [],
                ],
            ],
            'quality_gate' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->gateQueries->qualityGate(...$v),
                // Checked before anything else is read, together, with the one message.
                'guard' => static function (array $a): void {
                    $budgets = $a['budgets'] ?? null;
                    $policies = $a['policies'] ?? [];
                    // An empty JSON object decodes to []; accept it as "no budgets"
                    // rather than mistaking it for a list.
                    if (!is_array($budgets) || ($budgets !== [] && array_is_list($budgets)) || !is_array($policies) || !array_is_list($policies)) {
                        throw new InvalidArgumentException('budgets must be an object and policies must be a list.');
                    }
                },
                'arguments' => [
                    'project_id' => self::at('Usage: knossos quality-gate <path|project-id> <baseline-snapshot> --budgets=FILE'),
                    'baseline_snapshot' => self::at('A baseline snapshot is required.'),
                    'budgets' => ['read' => static fn(array $a): mixed => $a['budgets']],
                    // Absent, the gate compiles no policies at all.
                    'policies' => ['read' => static fn(array $a): mixed => $a['policies'] ?? []],
                    'sarif' => [],
                    'propose_baseline' => [],
                ],
            ],
            'architecture_trends' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->gateQueries->architectureTrends(...$v),
                'arguments' => ['project_id' => self::at('Usage: knossos architecture-trends <path|project-id> [options]'), 'limit' => [], 'release_from' => []],
            ],
            'list_boundaries' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->summaryQueries->listBoundaries(...$v),
                'arguments' => ['project_id' => self::at('Usage: knossos list-boundaries <path|project-id> [options]'), 'source' => [], 'limit' => [], 'offset' => []],
            ],
            // No CLI command asks this one, so its positional needs no message.
            'list_diagnostics' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->diagnosticsQueries->listDiagnostics(...$v),
                'arguments' => ['project_id' => self::at(null), 'severity' => [], 'path_prefix' => [], 'limit' => [], 'offset' => []],
            ],
        ];
    }

    /**
     * The tools that look up, inspect and annotate components and files.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function componentTools(): array
    {
        return [
            'find_component' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->findComponent(...$v),
                'arguments' => [
                    'project_id' => self::at('Usage: knossos find-component <path|project-id> <name> [--limit=N] [--json]'),
                    'name' => self::at('A component name is required.'),
                    'limit' => [],
                ],
            ],
            'inspect_component' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->inspectComponent(...$v),
                'arguments' => [
                    'project_id' => self::at('Usage: knossos inspect-component <path|project-id> <component> [options]'),
                    'component' => self::at('A component ID or name is required.'),
                    'max_relationships' => [], 'max_children' => [], 'min_confidence' => [],
                ],
            ],
            'list_usages' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->listUsages(...$v),
                'arguments' => [
                    'project_id' => self::at('Usage: knossos list-usages <path|project-id> <symbol> [--edge-kind=K]... [--min-confidence=L] [--limit=N] [--exclude-tests] [--json]'),
                    'symbol' => self::at('A symbol is required.'),
                    'edge_kinds' => self::as('edge-kind'), 'min_confidence' => [], 'limit' => [], 'exclude_tests' => [],
                ],
            ],
            'search_architecture' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->searchArchitecture(...$v),
                'arguments' => [
                    'project_id' => self::at('Usage: knossos search-architecture <path|project-id> <query> [options]'),
                    'query' => self::at('A search query is required.'),
                    'kinds' => self::as('kind'), 'roles' => self::as('role'), 'boundary_ids' => self::as('boundary'), 'confidences' => self::as('confidence'),
                    'limit' => [], 'offset' => [], 'files' => [],
                ],
            ],
            'file_metrics' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->fileMetricsQueries->fileMetrics(...$v),
                'arguments' => [
                    'project_id' => self::at('Usage: knossos file-metrics <path|project-id> [--path=SUBSTR] [--language=LANG] [--sort-by=path|line_count] [--order=asc|desc] [--limit=N] [--offset=N]'),
                    'path_contains' => self::as('path'), 'language' => [], 'sort_by' => [], 'order' => [], 'limit' => [], 'offset' => [],
                ],
            ],
            'untested_code' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->untestedQueries->untested(...$v),
                'arguments' => [
                    'project_id' => self::at('Usage: knossos untested-code <path|project-id> [--granularity=files|components] [--path=PREFIX] [--limit=N] [--offset=N] [--max-nodes=N] [--timeout-ms=N]'),
                    'granularity' => [], 'path_prefix' => self::as('path'), 'limit' => [], 'offset' => [], 'max_nodes' => [], 'timeout_ms' => [],
                ],
            ],
            // MCP only: the CLI's file-context is the pane's, a different answer.
            'file_context' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->fileContext(...$v),
                'arguments' => ['project_id' => self::at(null), 'path' => self::at(null)],
            ],
            'list_annotations' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->annotationQueries->listAnnotations(...$v),
                'arguments' => [
                    'project_id' => self::at('Usage: knossos list-annotations <path|project-id> [--component=NAME] [--kind=KIND] [--limit=N] [--offset=N] [--json]'),
                    'component' => [], 'kind' => [], 'limit' => [], 'offset' => [],
                ],
            ],
            'annotate_component' => [
                // `remove`, `confirm` or `move_to` picks the method; a note written or moved here is the model's.
                'call' => static fn(ArchitectureQueryService $q, array $v, array $a): ResultEnvelope => match (true) {
                    $a['move_to'] !== null => $q->services()->annotationQueries->moveAnnotation($a['project_id'], $a['component'], $a['move_to'], $a['kind'], $a['execute']),
                    $a['confirm'] => $q->services()->annotationQueries->confirmAnnotation($a['project_id'], $a['component'], $a['kind'], $a['execute']),
                    $a['remove'] => $q->services()->annotationQueries->removeAnnotation($a['project_id'], $a['component'], $a['kind'], $a['value'], $a['execute']),
                    default => $q->services()->annotationQueries->upsertAnnotation($a['project_id'], $a['component'], $a['kind'], $a['value'], $a['execute'], 'model', $a['evidence_paths'], $a['replace']),
                },
                'arguments' => [
                    'project_id' => self::at('Usage: knossos annotate-component <path|project-id> <component> <kind> [value] [--evidence=path]... [--remove] [--replace] [--confirm] [--move-to=NAME] [--execute] [--json]'),
                    'component' => self::at('A component is required.'),
                    'kind' => self::at('A kind is required.'),
                    // Stored as given: a note's whitespace is content.
                    'value' => ['positional' => null, 'read' => static fn(array $a): string => ToolArguments::text($a, 'value', 2000, allowEmpty: true, default: '', trim: false)],
                    'remove' => [], 'replace' => [], 'execute' => [],
                    // Confirming keeps the note as it is, so it takes nothing a write or a removal does.
                    'confirm' => ['read' => static function (array $a): bool {
                        $confirm = ToolArguments::boolean($a, 'confirm', false);
                        if ($confirm && (($a['value'] ?? '') !== '' || ($a['remove'] ?? false) === true || ($a['replace'] ?? false) === true || ($a['evidence_paths'] ?? []) !== [])) {
                            throw new ToolInputException('confirm keeps the note as it is: pass no value, evidence_paths, remove or replace with it.');
                        }

                        return $confirm;
                    }],
                    // Moving keeps the note as it is too, and takes nothing else that acts on it.
                    'move_to' => ['read' => static function (array $a): ?string {
                        if (!array_key_exists('move_to', $a)) {
                            return null;
                        }
                        $to = ToolArguments::text($a, 'move_to', 1000);
                        if (($a['value'] ?? '') !== '' || ($a['remove'] ?? false) === true || ($a['replace'] ?? false) === true || ($a['confirm'] ?? false) === true || ($a['evidence_paths'] ?? []) !== []) {
                            throw new ToolInputException('move_to moves the note as it is: pass no value, evidence_paths, remove, replace or confirm with it.');
                        }
                        if ($to === ToolArguments::normalized($a['component'] ?? null)) {
                            throw new ToolInputException('move_to names the component the note is already on.');
                        }

                        return $to;
                    }],
                    'evidence_paths' => self::as('evidence'),
                ],
            ],
            'export_agent_brief' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->briefQueries->exportAgentBrief(...$v),
                'arguments' => ['project_id' => self::at('Usage: knossos export-agent-brief <path|project-id> [--max-chars=N] [--out=FILE] [--json]'), 'max_chars' => []],
            ],
        ];
    }

    /**
     * The graph analyses: summaries, flows, impact, cycles, health, policy, placement and diagrams.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function analysisTools(): array
    {
        return [
            'architecture_summary' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->summaryQueries->architectureSummary(...$v),
                'arguments' => ['project_id' => self::at('Usage: knossos architecture-summary <path|project-id> [--json]'), 'limit' => []],
            ],
            'explain_flow' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->explainFlow(...$v),
                'arguments' => [
                    'project_id' => self::at('Usage: knossos explain-flow <path|project-id> <from> <to> [options]'),
                    'from' => self::at('A flow source is required.'),
                    'to' => self::at('A flow target is required.'),
                    'max_depth' => [], 'max_paths' => [], 'edge_kinds' => self::as('edge-kind'), 'min_confidence' => [],
                    'timeout_ms' => [], 'both_directions' => [], 'profile' => [],
                ],
            ],
            'impact_analysis' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->impactAnalysis(...$v),
                'arguments' => [
                    'project_id' => self::at('Usage: knossos impact-analysis <path|project-id> <symbol> [options]'),
                    'symbol' => self::at('An impact target is required.'),
                    'max_depth' => [], 'limit' => [], 'edge_kinds' => self::as('edge-kind'), 'min_confidence' => [],
                    'timeout_ms' => [], 'seed_members' => [], 'rings' => [],
                ],
            ],
            'dependency_cycles' => [
                // within_type is passed by name: the method takes a parameter
                // no tool argument sets between it and the others.
                'call' => static fn(ArchitectureQueryService $q, array $v, array $a): ResultEnvelope => $q->services()->cycleQueries->dependencyCycles(...[...array_slice($v, 0, -1), 'withinType' => $a['within_type']]),
                'arguments' => [
                    'project_id' => self::at('Usage: knossos dependency-cycles <path|project-id> [options]'),
                    'edge_kinds' => self::as('edge-kind'), 'min_confidence' => [], 'limit' => [], 'max_nodes' => [], 'max_edges' => [],
                    'timeout_ms' => [], 'include_self_loops' => [], 'within_type' => [],
                ],
            ],
            'architecture_health' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->healthQueries->architectureHealth(...$v),
                'arguments' => [
                    'project_id' => self::at('Usage: knossos architecture-health <path|project-id> [options]'),
                    'edge_kinds' => self::as('edge-kind'), 'min_confidence' => [], 'limit' => [], 'max_nodes' => [], 'max_edges' => [],
                    'timeout_ms' => [], 'include_external' => [], 'include_tests' => [], 'candidate_confidence' => [],
                    'candidate_offset' => [], 'candidate_timeout_ms' => self::as('candidate-timeout'),
                ],
            ],
            'check_architecture' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->policyQueries->checkArchitecture(...$v),
                // Absent, the project's declared policies are checked.
                'arguments' => [
                    'project_id' => self::at('Usage: knossos check-architecture <path|project-id> [--policies=FILE] [options]'),
                    'policies' => [], 'min_confidence' => [], 'limit' => [], 'max_edges' => [], 'timeout_ms' => [],
                ],
            ],
            'suggest_location' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->locationQueries->suggestLocation(...$v),
                'arguments' => [
                    'project_id' => self::at('Usage: knossos suggest-location <path|project-id> <feature-description> [options]'),
                    'feature_description' => self::at('A feature description is required.'),
                    'limit' => [], 'max_members' => [], 'max_edges' => [], 'timeout_ms' => [], 'ranking_mode' => [],
                ],
            ],
            'export_diagram' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->diagramQueries->exportDiagram(...$v),
                'arguments' => [
                    'project_id' => self::at('Usage: knossos export-diagram <path|project-id> [options]'),
                    'format' => [], 'boundary' => [], 'edge_kinds' => self::as('edge-kind'), 'min_confidence' => [], 'direction' => [],
                    'max_nodes' => [], 'max_edges' => [],
                ],
            ],
        ];
    }

    /**
     * The tools that weigh a change: by history, by changed files, as tests, as a review, as context.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function changeTools(): array
    {
        $changedFiles = static fn(string $command): array => [
            'project_id' => self::at(sprintf('Usage: knossos %s <path|project-id> [files...] [options]', $command)),
            'files' => self::rest(), 'working_tree' => [], 'base_ref' => [], 'max_depth' => [], 'limit' => [],
            'edge_kinds' => self::as('edge-kind'), 'min_confidence' => [], 'timeout_ms' => [],
        ];

        return [
            'change_impact' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->changeQueries->changeImpact(...$v),
                'arguments' => [
                    'project_id' => self::at('Usage: knossos change-impact <path|project-id> <symbol> [options]'),
                    'symbol' => self::at('An impact target is required.'),
                    'since_days' => [], 'max_commits' => [], 'max_depth' => [], 'limit' => [], 'edge_kinds' => self::as('edge-kind'),
                    'min_confidence' => [], 'timeout_ms' => [],
                ],
            ],
            'changed_files_impact' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->changeQueries->changedFilesImpact(...$v),
                'arguments' => $changedFiles('changed-files-impact'),
            ],
            'test_impact' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->changeQueries->testImpact(...$v),
                'arguments' => $changedFiles('test-impact'),
            ],
            'review_diff' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->reviewQueries->reviewDiff(...$v),
                'arguments' => [
                    'project_id' => self::at('Usage: knossos review-diff <path|project-id> [FILE...] [options]'),
                    'base_ref' => [], 'files' => self::rest(), 'policies' => [], 'budgets' => [], 'baseline_snapshot' => [],
                    'max_depth' => [], 'limit' => [], 'min_confidence' => [], 'timeout_ms' => [],
                ],
            ],
            'architecture_context' => [
                'call' => static fn(ArchitectureQueryService $q, array $v): ResultEnvelope => $q->services()->contextQueries->architectureContext(...$v),
                'arguments' => [
                    'project_id' => self::at('Usage: knossos architecture-context <path|project-id> [files...] --task=TEXT [options]'),
                    // Optional, and empty when absent rather than null.
                    'task_description' => ['option' => 'task', 'read' => static fn(array $a): string => ToolArguments::text($a, 'task_description', 2000, allowEmpty: true, default: '')],
                    'files' => self::rest(), 'max_chars' => [], 'timeout_ms' => [], 'include_source' => [],
                ],
            ],
        ];
    }
}
