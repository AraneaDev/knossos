<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\ProjectReference;
use Knossos\Git\ProcessGitHistoryProvider;
use Knossos\Git\ProcessGitWorkingTreeProvider;
use Knossos\Mcp\ToolArgumentSpec;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\QueryServices;
use Knossos\Result\ResultEnvelope;

/**
 * Every read-only query as a CLI command.
 *
 * One command class for all of them because they share option parsing, envelope
 * rendering, and the `--json` contract; splitting them would duplicate that three
 * dozen times.
 */
final class QueryCommand implements CliCommand
{
    private const COMMANDS = [
        'list-projects', 'list-snapshots', 'snapshot-diff', 'quality-gate', 'architecture-trends',
        'find-component', 'inspect-component', 'list-usages', 'architecture-summary', 'file-metrics', 'explain-flow', 'impact-analysis',
        'dependency-cycles', 'architecture-health', 'check-architecture', 'suggest-location', 'change-impact',
        'changed-files-impact', 'test-impact', 'review-diff', 'architecture-context', 'export-diagram', 'export-agent-brief', 'list-boundaries',
        'search-architecture', 'annotate-component', 'list-annotations',
    ];

    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return in_array($command, self::COMMANDS, true);
    }

    /**
     * The options each command takes beside `--db` and `--json`, each mapped
     * to the argument of the same-named MCP tool it sets, or null for an
     * option only the CLI has. The tool's schema is where an option's default
     * and bounds come from ({@see ToolArgumentSpec}), so the CLI states no
     * number of its own; QueryCommandContractTest holds this map to the
     * schema, so an argument added to a tool fails until the CLI takes it too.
     */
    private const OPTIONS = [
        'list-projects' => ['limit' => 'limit', 'offset' => 'offset', 'include-roots' => 'include_roots'],
        'list-snapshots' => ['limit' => 'limit', 'offset' => 'offset'],
        'snapshot-diff' => ['max-changes' => 'max_changes'],
        'quality-gate' => ['budgets' => 'budgets', 'policies' => 'policies', 'sarif' => 'sarif', 'propose-baseline' => 'propose_baseline'],
        'architecture-trends' => ['limit' => 'limit', 'release-from' => 'release_from'],
        'find-component' => ['limit' => 'limit'],
        'inspect-component' => ['max-relationships' => 'max_relationships', 'max-children' => 'max_children', 'min-confidence' => 'min_confidence'],
        'list-usages' => ['edge-kind' => 'edge_kinds', 'min-confidence' => 'min_confidence', 'limit' => 'limit', 'exclude-tests' => 'exclude_tests'],
        'architecture-summary' => ['limit' => 'limit'],
        'file-metrics' => ['path' => 'path_contains', 'language' => 'language', 'sort-by' => 'sort_by', 'order' => 'order', 'limit' => 'limit', 'offset' => 'offset'],
        'explain-flow' => ['max-depth' => 'max_depth', 'max-paths' => 'max_paths', 'edge-kind' => 'edge_kinds', 'min-confidence' => 'min_confidence', 'timeout-ms' => 'timeout_ms', 'both-directions' => 'both_directions', 'profile' => 'profile'],
        'impact-analysis' => ['max-depth' => 'max_depth', 'limit' => 'limit', 'edge-kind' => 'edge_kinds', 'min-confidence' => 'min_confidence', 'timeout-ms' => 'timeout_ms', 'seed-members' => 'seed_members', 'rings' => 'rings'],
        'dependency-cycles' => ['edge-kind' => 'edge_kinds', 'min-confidence' => 'min_confidence', 'limit' => 'limit', 'max-nodes' => 'max_nodes', 'max-edges' => 'max_edges', 'timeout-ms' => 'timeout_ms', 'include-self-loops' => 'include_self_loops', 'within-type' => 'within_type'],
        'architecture-health' => ['edge-kind' => 'edge_kinds', 'min-confidence' => 'min_confidence', 'limit' => 'limit', 'max-nodes' => 'max_nodes', 'max-edges' => 'max_edges', 'timeout-ms' => 'timeout_ms', 'include-external' => 'include_external', 'include-tests' => 'include_tests', 'candidate-confidence' => 'candidate_confidence', 'candidate-offset' => 'candidate_offset', 'candidate-timeout' => 'candidate_timeout_ms'],
        'check-architecture' => ['policies' => 'policies', 'min-confidence' => 'min_confidence', 'limit' => 'limit', 'max-edges' => 'max_edges', 'timeout-ms' => 'timeout_ms'],
        'suggest-location' => ['limit' => 'limit', 'max-members' => 'max_members', 'max-edges' => 'max_edges', 'timeout-ms' => 'timeout_ms', 'ranking-mode' => 'ranking_mode'],
        'change-impact' => ['since-days' => 'since_days', 'max-commits' => 'max_commits', 'max-depth' => 'max_depth', 'limit' => 'limit', 'edge-kind' => 'edge_kinds', 'min-confidence' => 'min_confidence', 'timeout-ms' => 'timeout_ms'],
        'changed-files-impact' => ['working-tree' => 'working_tree', 'base-ref' => 'base_ref', 'max-depth' => 'max_depth', 'limit' => 'limit', 'edge-kind' => 'edge_kinds', 'min-confidence' => 'min_confidence', 'timeout-ms' => 'timeout_ms'],
        'test-impact' => ['working-tree' => 'working_tree', 'base-ref' => 'base_ref', 'max-depth' => 'max_depth', 'limit' => 'limit', 'edge-kind' => 'edge_kinds', 'min-confidence' => 'min_confidence', 'timeout-ms' => 'timeout_ms'],
        'review-diff' => ['base-ref' => 'base_ref', 'policies' => 'policies', 'budgets' => 'budgets', 'baseline-snapshot' => 'baseline_snapshot', 'max-depth' => 'max_depth', 'limit' => 'limit', 'min-confidence' => 'min_confidence', 'timeout-ms' => 'timeout_ms'],
        'architecture-context' => ['task' => 'task_description', 'max-chars' => 'max_chars', 'timeout-ms' => 'timeout_ms', 'include-source' => 'include_source'],
        'export-diagram' => ['format' => 'format', 'boundary' => 'boundary', 'edge-kind' => 'edge_kinds', 'min-confidence' => 'min_confidence', 'direction' => 'direction', 'max-nodes' => 'max_nodes', 'max-edges' => 'max_edges'],
        // --out writes the brief to a file: a CLI convenience with no tool argument behind it.
        'export-agent-brief' => ['max-chars' => 'max_chars', 'out' => null],
        'list-boundaries' => ['source' => 'source', 'limit' => 'limit', 'offset' => 'offset'],
        'annotate-component' => ['remove' => 'remove', 'replace' => 'replace', 'execute' => 'execute', 'evidence' => 'evidence_paths'],
        'search-architecture' => ['kind' => 'kinds', 'role' => 'roles', 'boundary' => 'boundary_ids', 'confidence' => 'confidences', 'limit' => 'limit', 'offset' => 'offset', 'files' => 'files'],
        'list-annotations' => ['component' => 'component', 'kind' => 'kind', 'limit' => 'limit', 'offset' => 'offset'],
    ];

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return ['db', 'json', ...array_keys(self::optionArguments($command))];
    }

    /**
     * The tool argument each of `$command`'s own options sets, null for a
     * CLI-only one; the tool is the command's name in snake case.
     *
     * @return array<string, string|null>
     */
    public static function optionArguments(string $command): array
    {
        // Unreachable through the router, which asks only for supported
        // commands: a new command without an entry fails its first test
        // instead of borrowing another command's list.
        return self::OPTIONS[$command] ?? throw new \LogicException(sprintf('No option allowlist for %s.', $command));
    }

    /** {@inheritDoc} */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        // Every command but list-projects names its project first: read the graph that holds it.
        if ($command !== 'list-projects' && isset($positionals[0])) {
            $context = $context->forTarget($positionals[0], $options);
        }
        return match ($command) {
            'list-projects' => $this->listProjects($options, $context),
            'list-snapshots' => $this->listSnapshots($positionals, $options, $context),
            'snapshot-diff' => $this->snapshotDiff($positionals, $options, $context),
            'quality-gate' => $this->qualityGate($positionals, $options, $context),
            'architecture-trends' => $this->architectureTrends($positionals, $options, $context),
            'find-component' => $this->findComponent($positionals, $options, $context),
            'inspect-component' => $this->inspectComponent($positionals, $options, $context),
            'list-usages' => $this->listUsages($positionals, $options, $context),
            'architecture-summary' => $this->architectureSummary($positionals, $options, $context),
            'file-metrics' => $this->fileMetrics($positionals, $options, $context),
            'explain-flow' => $this->explainFlow($positionals, $options, $context),
            'impact-analysis' => $this->impactAnalysis($positionals, $options, $context),
            'dependency-cycles' => $this->dependencyCycles($positionals, $options, $context),
            'architecture-health' => $this->architectureHealth($positionals, $options, $context),
            'check-architecture' => $this->checkArchitecture($positionals, $options, $context),
            'suggest-location' => $this->suggestLocation($positionals, $options, $context),
            'change-impact' => $this->changeImpact($positionals, $options, $context),
            'changed-files-impact' => $this->changedFilesImpact($positionals, $options, $context),
            'test-impact' => $this->testImpact($positionals, $options, $context),
            'review-diff' => $this->reviewDiff($positionals, $options, $context),
            'architecture-context' => $this->architectureContext($positionals, $options, $context),
            'export-diagram' => $this->exportDiagram($positionals, $options, $context),
            'export-agent-brief' => $this->exportAgentBrief($positionals, $options, $context),
            'list-boundaries' => $this->listBoundaries($positionals, $options, $context),
            'annotate-component' => $this->annotateComponent($positionals, $options, $context),
            'list-annotations' => $this->listAnnotations($positionals, $options, $context),
            default => $this->searchArchitecture($positionals, $options, $context),
        };
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ProjectCatalogQueryService::listProjects()}.
     *
     * @param array<string, list<string>> $options
     */
    private function listProjects(array $options, CliCommandContext $context): int
    {
        $result = $this->services($context)->catalogQueries->listProjects(
            $this->integer($options, $context, 'list-projects', 'limit'),
            $this->integer($options, $context, 'list-projects', 'offset'),
            $this->flag($options, $context, 'list-projects', 'include-roots'),
        );
        $text = $result->summary;
        foreach ($result->data['projects'] as $project) {
            $text .= sprintf(
                "\n%s  %s  %s  files=%d nodes=%d edges=%d%s",
                $project['id'],
                $project['name'],
                $project['freshness'],
                $project['counts']['files'],
                $project['counts']['nodes'],
                $project['counts']['edges'],
                isset($project['root']) ? '  root=' . $project['root'] : '',
            );
        }
        $context->output($result->jsonSerialize(), $context->options->flag($options, 'json'), $text);
        return CliCommand::EXIT_OK;
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ProjectCatalogQueryService::listSnapshots()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function listSnapshots(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos list-snapshots <path|project-id> [--limit=N] [--offset=N]'), $c);
        $result = $this->services($c)->catalogQueries->listSnapshots($project, $this->integer($o, $c, 'list-snapshots', 'limit'), $this->integer($o, $c, 'list-snapshots', 'offset'));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\SnapshotDiffQuery::snapshotDiff()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function snapshotDiff(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos snapshot-diff <path|project-id> <from-snapshot> [to-snapshot]'), $c);
        $from = $p[1] ?? throw new InvalidArgumentException('A source snapshot is required.');
        $result = $this->services($c)->diffQueries->snapshotDiff($project, $from, $p[2] ?? self::spec('snapshot-diff')->stringDefault('to_snapshot'), $this->integer($o, $c, 'snapshot-diff', 'max-changes'));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\QualityGateQueryService::qualityGate()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function qualityGate(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos quality-gate <path|project-id> <baseline-snapshot> --budgets=FILE'), $c);
        $baseline = $p[1] ?? throw new InvalidArgumentException('A baseline snapshot is required.');
        $budget = $c->options->single($o, 'budgets') ?? throw new InvalidArgumentException('--budgets=FILE is required.');
        $policies = $c->options->single($o, 'policies');
        $result = $this->services($c)->gateQueries->qualityGate($project, $baseline, $c->input->jsonObject($budget), $policies === null ? [] : $c->input->policies($policies), $this->flag($o, $c, 'quality-gate', 'sarif'), $this->flag($o, $c, 'quality-gate', 'propose-baseline'));
        $c->output($result->jsonSerialize(), $c->options->flag($o, 'json'), $result->summary);
        return $result->data['passed'] ? CliCommand::EXIT_OK : CliCommand::EXIT_GATE_FAILED;
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\QualityGateQueryService::architectureTrends()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function architectureTrends(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos architecture-trends <path|project-id> [options]'), $c);
        $result = $this->services($c)->gateQueries->architectureTrends($project, $this->integer($o, $c, 'architecture-trends', 'limit'), $c->options->single($o, 'release-from'));
        $c->output($result->jsonSerialize(), $c->options->flag($o, 'json'), $result->data['release_notes']['markdown'] ?? $result->summary);
        return CliCommand::EXIT_OK;
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::findComponent()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function findComponent(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos find-component <path|project-id> <name> [--limit=N] [--json]'), $c);
        $name = $p[1] ?? throw new InvalidArgumentException('A component name is required.');
        return $this->result($this->queries($c)->findComponent($project, $name, $this->integer($o, $c, 'find-component', 'limit')), $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::inspectComponent()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function inspectComponent(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos inspect-component <path|project-id> <component> [options]'), $c);
        $component = $p[1] ?? throw new InvalidArgumentException('A component ID or name is required.');
        $result = $this->queries($c)->inspectComponent($project, $component, $this->integer($o, $c, 'inspect-component', 'max-relationships'), $this->integer($o, $c, 'inspect-component', 'max-children'), $this->choice($o, $c, 'inspect-component', 'min-confidence'));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::listUsages()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function listUsages(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos list-usages <path|project-id> <symbol> [--edge-kind=K]... [--min-confidence=L] [--limit=N] [--exclude-tests] [--json]'), $c);
        $symbol = $p[1] ?? throw new InvalidArgumentException('A symbol is required.');
        $result = $this->queries($c)->listUsages($project, $symbol, $c->options->values($o, 'edge-kind'), $this->choice($o, $c, 'list-usages', 'min-confidence'), $this->integer($o, $c, 'list-usages', 'limit'), $this->flag($o, $c, 'list-usages', 'exclude-tests'));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\GraphSummaryQuery::architectureSummary()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function architectureSummary(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos architecture-summary <path|project-id> [--json]'), $c);
        $result = $this->services($c)->summaryQueries->architectureSummary($project, $this->integer($o, $c, 'architecture-summary', 'limit'));
        $c->output($result->jsonSerialize(), $c->options->flag($o, 'json'), $result->summary);
        return CliCommand::EXIT_OK;
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::explainFlow()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function explainFlow(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos explain-flow <path|project-id> <from> <to> [options]'), $c);
        $from = $p[1] ?? throw new InvalidArgumentException('A flow source is required.');
        $to = $p[2] ?? throw new InvalidArgumentException('A flow target is required.');
        $result = $this->queries($c)->explainFlow($project, $from, $to, $this->integer($o, $c, 'explain-flow', 'max-depth'), $this->integer($o, $c, 'explain-flow', 'max-paths'), $c->options->values($o, 'edge-kind'), $this->choice($o, $c, 'explain-flow', 'min-confidence'), $this->integer($o, $c, 'explain-flow', 'timeout-ms'), $this->flag($o, $c, 'explain-flow', 'both-directions'), $c->options->single($o, 'profile'));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::impactAnalysis()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function impactAnalysis(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos impact-analysis <path|project-id> <symbol> [options]'), $c);
        $symbol = $p[1] ?? throw new InvalidArgumentException('An impact target is required.');
        $result = $this->queries($c)->impactAnalysis($project, $symbol, $this->integer($o, $c, 'impact-analysis', 'max-depth'), $this->integer($o, $c, 'impact-analysis', 'limit'), $c->options->values($o, 'edge-kind'), $this->choice($o, $c, 'impact-analysis', 'min-confidence'), $this->integer($o, $c, 'impact-analysis', 'timeout-ms'), $this->flag($o, $c, 'impact-analysis', 'seed-members'), $this->flag($o, $c, 'impact-analysis', 'rings'));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\DependencyCycleQuery::dependencyCycles()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function dependencyCycles(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos dependency-cycles <path|project-id> [options]'), $c);
        $result = $this->services($c)->cycleQueries->dependencyCycles($project, $c->options->values($o, 'edge-kind'), $this->choice($o, $c, 'dependency-cycles', 'min-confidence'), $this->integer($o, $c, 'dependency-cycles', 'limit'), $this->integer($o, $c, 'dependency-cycles', 'max-nodes'), $this->integer($o, $c, 'dependency-cycles', 'max-edges'), $this->integer($o, $c, 'dependency-cycles', 'timeout-ms'), $this->flag($o, $c, 'dependency-cycles', 'include-self-loops'), withinType: $this->flag($o, $c, 'dependency-cycles', 'within-type'));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureHealthQuery::architectureHealth()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function architectureHealth(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos architecture-health <path|project-id> [options]'), $c);
        $result = $this->services($c)->healthQueries->architectureHealth($project, $c->options->values($o, 'edge-kind'), $this->choice($o, $c, 'architecture-health', 'min-confidence'), $this->integer($o, $c, 'architecture-health', 'limit'), $this->integer($o, $c, 'architecture-health', 'max-nodes'), $this->integer($o, $c, 'architecture-health', 'max-edges'), $this->integer($o, $c, 'architecture-health', 'timeout-ms'), $this->flag($o, $c, 'architecture-health', 'include-external'), $this->flag($o, $c, 'architecture-health', 'include-tests'), $this->choice($o, $c, 'architecture-health', 'candidate-confidence'), $this->integer($o, $c, 'architecture-health', 'candidate-offset'), $this->integer($o, $c, 'architecture-health', 'candidate-timeout'));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitecturePolicyQueryService::checkArchitecture()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function checkArchitecture(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos check-architecture <path|project-id> [--policies=FILE] [options]'), $c);
        // Without a file, the policies the project declares in knossos.json.
        $path = $c->options->single($o, 'policies');
        $result = $this->services($c)->policyQueries->checkArchitecture($project, $path === null ? null : $c->input->policies($path), $this->choice($o, $c, 'check-architecture', 'min-confidence'), $this->integer($o, $c, 'check-architecture', 'limit'), $this->integer($o, $c, 'check-architecture', 'max-edges'), $this->integer($o, $c, 'check-architecture', 'timeout-ms'));
        $c->output($result->jsonSerialize(), $c->options->flag($o, 'json'), $result->summary);
        // Exit non-zero when declared-policy violations exist so the "check"
        // command can gate CI on its own result, mirroring quality-gate. The
        // authoritative count is the (possibly larger) bounds.violation_count.
        return ($result->data['bounds']['violation_count'] ?? count($result->data['violations'])) > 0 ? CliCommand::EXIT_GATE_FAILED : CliCommand::EXIT_OK;
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\LocationSuggestionService::suggestLocation()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function suggestLocation(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos suggest-location <path|project-id> <feature-description> [options]'), $c);
        $description = $p[1] ?? throw new InvalidArgumentException('A feature description is required.');
        $result = $this->services($c)->locationQueries->suggestLocation($project, $description, $this->integer($o, $c, 'suggest-location', 'limit'), $this->integer($o, $c, 'suggest-location', 'max-members'), $this->integer($o, $c, 'suggest-location', 'max-edges'), $this->integer($o, $c, 'suggest-location', 'timeout-ms'), $this->choice($o, $c, 'suggest-location', 'ranking-mode'));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders the Git-weighted blast radius.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function changeImpact(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos change-impact <path|project-id> <symbol> [options]'), $c);
        $symbol = $p[1] ?? throw new InvalidArgumentException('An impact target is required.');
        $changes = QueryServices::wire($c->database(), gitHistory: new ProcessGitHistoryProvider())->changeQueries;
        $result = $changes->changeImpact($project, $symbol, $this->integer($o, $c, 'change-impact', 'since-days'), $this->integer($o, $c, 'change-impact', 'max-commits'), $this->integer($o, $c, 'change-impact', 'max-depth'), $this->integer($o, $c, 'change-impact', 'limit'), $c->options->values($o, 'edge-kind'), $this->choice($o, $c, 'change-impact', 'min-confidence'), $this->integer($o, $c, 'change-impact', 'timeout-ms'));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders the impact of a changed file set.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function changedFilesImpact(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos changed-files-impact <path|project-id> [files...] [options]'), $c);
        $changes = QueryServices::wire($c->database(), gitWorkingTree: new ProcessGitWorkingTreeProvider())->changeQueries;
        $result = $changes->changedFilesImpact($project, array_slice($p, 1), $this->flag($o, $c, 'changed-files-impact', 'working-tree'), $c->options->single($o, 'base-ref'), $this->integer($o, $c, 'changed-files-impact', 'max-depth'), $this->integer($o, $c, 'changed-files-impact', 'limit'), $c->options->values($o, 'edge-kind'), $this->choice($o, $c, 'changed-files-impact', 'min-confidence'), $this->integer($o, $c, 'changed-files-impact', 'timeout-ms'));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders the tests exercising a change.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function testImpact(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos test-impact <path|project-id> [files...] [options]'), $c);
        $changes = QueryServices::wire($c->database(), gitWorkingTree: new ProcessGitWorkingTreeProvider())->changeQueries;
        $result = $changes->testImpact($project, array_slice($p, 1), $this->flag($o, $c, 'test-impact', 'working-tree'), $c->options->single($o, 'base-ref'), $this->integer($o, $c, 'test-impact', 'max-depth'), $this->integer($o, $c, 'test-impact', 'limit'), $c->options->values($o, 'edge-kind'), $this->choice($o, $c, 'test-impact', 'min-confidence'), $this->integer($o, $c, 'test-impact', 'timeout-ms'));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders the one-call architectural review.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function reviewDiff(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos review-diff <path|project-id> [FILE...] [options]'), $c);
        $policies = $c->options->single($o, 'policies');
        $budgets = $c->options->single($o, 'budgets');
        $review = QueryServices::wire($c->database(), gitWorkingTree: new ProcessGitWorkingTreeProvider())->reviewQueries;
        $result = $review->reviewDiff(
            $project,
            $c->options->single($o, 'base-ref'),
            array_slice($p, 1),
            $policies === null ? null : $c->input->policies($policies),
            $budgets === null ? null : $c->input->jsonObject($budgets),
            $c->options->single($o, 'baseline-snapshot'),
            $this->integer($o, $c, 'review-diff', 'max-depth'),
            $this->integer($o, $c, 'review-diff', 'limit'),
            $this->choice($o, $c, 'review-diff', 'min-confidence'),
            $this->integer($o, $c, 'review-diff', 'timeout-ms'),
        );
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureContextService::architectureContext()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function architectureContext(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos architecture-context <path|project-id> [files...] --task=TEXT [options]'), $c);
        $result = $this->services($c)->contextQueries->architectureContext($project, $c->options->single($o, 'task') ?? '', array_slice($p, 1), $this->integer($o, $c, 'architecture-context', 'max-chars'), $this->integer($o, $c, 'architecture-context', 'timeout-ms'), $this->flag($o, $c, 'architecture-context', 'include-source'));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\DiagramExportService::exportDiagram()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function exportDiagram(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos export-diagram <path|project-id> [options]'), $c);
        $result = $this->services($c)->diagramQueries->exportDiagram($project, $this->choice($o, $c, 'export-diagram', 'format'), $c->options->single($o, 'boundary'), $c->options->values($o, 'edge-kind'), $this->choice($o, $c, 'export-diagram', 'min-confidence'), $this->choice($o, $c, 'export-diagram', 'direction'), $this->integer($o, $c, 'export-diagram', 'max-nodes'), $this->integer($o, $c, 'export-diagram', 'max-edges'));
        $c->output($result->jsonSerialize(), $c->options->flag($o, 'json'), $result->data['diagram']);
        return CliCommand::EXIT_OK;
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\AgentBriefService::exportAgentBrief()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function exportAgentBrief(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos export-agent-brief <path|project-id> [--max-chars=N] [--out=FILE] [--json]'), $c);
        $result = $this->services($c)->briefQueries->exportAgentBrief($project, $this->integer($o, $c, 'export-agent-brief', 'max-chars'));
        $out = $c->options->single($o, 'out');
        if ($out !== null && file_put_contents($out, $result->data['markdown']) === false) {
            throw new InvalidArgumentException(sprintf('Unable to write brief to %s.', $out));
        }
        $c->output($result->jsonSerialize(), $c->options->flag($o, 'json'), $result->data['markdown']);
        return CliCommand::EXIT_OK;
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\GraphSummaryQuery::listBoundaries()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function listBoundaries(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos list-boundaries <path|project-id> [options]'), $c);
        $result = $this->services($c)->summaryQueries->listBoundaries($project, $c->options->single($o, 'source'), $this->integer($o, $c, 'list-boundaries', 'limit'), $this->integer($o, $c, 'list-boundaries', 'offset'));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::searchArchitecture()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function searchArchitecture(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos search-architecture <path|project-id> <query> [options]'), $c);
        $query = $p[1] ?? throw new InvalidArgumentException('A search query is required.');
        $result = $this->queries($c)->searchArchitecture($project, $query, $c->options->values($o, 'kind'), $c->options->values($o, 'role'), $c->options->values($o, 'boundary'), $c->options->values($o, 'confidence'), $this->integer($o, $c, 'search-architecture', 'limit'), $this->integer($o, $c, 'search-architecture', 'offset'), $this->flag($o, $c, 'search-architecture', 'files'));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\FileMetricsQueryService::fileMetrics()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function fileMetrics(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos file-metrics <path|project-id> [--path=SUBSTR] [--language=LANG] [--sort-by=path|line_count] [--order=asc|desc] [--limit=N] [--offset=N]'), $c);
        $result = $this->services($c)->fileMetricsQueries->fileMetrics(
            $project,
            $c->options->single($o, 'path'),
            $c->options->single($o, 'language'),
            $this->choice($o, $c, 'file-metrics', 'sort-by'),
            $this->choice($o, $c, 'file-metrics', 'order'),
            $this->integer($o, $c, 'file-metrics', 'limit'),
            $this->integer($o, $c, 'file-metrics', 'offset'),
        );
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\AnnotationService::removeAnnotation()}
     * under `--remove`, else {@see \Knossos\Query\AnnotationService::upsertAnnotation()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function annotateComponent(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos annotate-component <path|project-id> <component> <kind> [value] [--evidence=path]... [--remove] [--replace] [--execute] [--json]'), $c);
        $component = $p[1] ?? throw new InvalidArgumentException('A component is required.');
        $kind = $p[2] ?? throw new InvalidArgumentException('A kind is required.');
        $value = $p[3] ?? '';
        $execute = $this->flag($o, $c, 'annotate-component', 'execute');
        $result = $this->flag($o, $c, 'annotate-component', 'remove')
            ? $this->services($c)->annotationQueries->removeAnnotation($project, $component, $kind, $value, $execute)
            : $this->services($c)->annotationQueries->upsertAnnotation($project, $component, $kind, $value, $execute, 'model', array_values(array_map('trim', $o['evidence'] ?? [])), $this->flag($o, $c, 'annotate-component', 'replace'));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\AnnotationService::listAnnotations()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function listAnnotations(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos list-annotations <path|project-id> [--component=NAME] [--kind=KIND] [--limit=N] [--offset=N] [--json]'), $c);
        $result = $this->services($c)->annotationQueries->listAnnotations($project, $c->options->single($o, 'component'), $c->options->single($o, 'kind'), $this->integer($o, $c, 'list-annotations', 'limit'), $this->integer($o, $c, 'list-annotations', 'offset'));
        return $this->result($result, $o, $c);
    }
    /** The project id a `<path|project-id>` argument names, in this invocation's database. */
    private function project(string $argument, CliCommandContext $context): string
    {
        return (new ProjectReference($context->database(), $context->databasePath()))->resolve($argument)['id'];
    }

    /** The query facade for this invocation, for the queries whose answers carry the notes of the components they name. */
    private function queries(CliCommandContext $context): ArchitectureQueryService
    {
        return ArchitectureQueryService::forDatabase($context->database());
    }

    /** The query services for this invocation, for every query the facade does not annotate with notes. */
    private function services(CliCommandContext $context): QueryServices
    {
        return QueryServices::wire($context->database());
    }

    /** The argument contract of the tool `$command` mirrors: the command's name in snake case. */
    private static function spec(string $command): ToolArgumentSpec
    {
        return ToolArgumentSpec::for(str_replace('-', '_', $command));
    }

    /**
     * An integer option, with the default and bounds of the tool argument it sets.
     *
     * @param array<string, list<string>> $options
     */
    private function integer(array $options, CliCommandContext $context, string $command, string $option): int
    {
        ['default' => $default, 'minimum' => $minimum, 'maximum' => $maximum] = self::spec($command)->bounds((string) self::optionArguments($command)[$option]);

        return $context->options->integer($options, $option, $default, $minimum, $maximum);
    }

    /**
     * A value option, or the default of the tool argument it sets when absent.
     *
     * @param array<string, list<string>> $options
     */
    private function choice(array $options, CliCommandContext $context, string $command, string $option): string
    {
        return $context->options->single($options, $option) ?? self::spec($command)->stringDefault((string) self::optionArguments($command)[$option]);
    }

    /**
     * A flag, or the default of the tool argument it sets when absent.
     *
     * @param array<string, list<string>> $options
     */
    private function flag(array $options, CliCommandContext $context, string $command, string $option): bool
    {
        return $context->options->flag($options, $option, self::spec($command)->booleanDefault((string) self::optionArguments($command)[$option]));
    }

    /**
     * Render a result envelope as JSON or text, per the --json flag.
     *
     * @param array<string, list<string>> $options
     */
    private function result(ResultEnvelope $result, array $options, CliCommandContext $context): int
    {
        $context->output($result->jsonSerialize(), $context->options->flag($options, 'json'), $result->summary);
        return CliCommand::EXIT_OK;
    }
}
