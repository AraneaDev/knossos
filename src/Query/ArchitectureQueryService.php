<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use Knossos\Git\GitHistoryProvider;
use Knossos\Git\GitWorkingTreeProvider;
use Knossos\Query\Drift\DriftOracle;
use Knossos\Result\ResultEnvelope;
use Knossos\Store\ProjectRepository;
use PDO;

/**
 * Facade over the specialised query services.
 *
 * What it owns itself is cross-cutting: the notes a component answer carries
 * ({@see AnnotationNotes::attach()}, on find, inspect, usages, flow, impact,
 * search and file context) and the project root the MCP layer confines and
 * refreshes by. A caller that wants a query the facade would only forward
 * reads it from {@see self::services()} instead, as the tools, the CLI and
 * the pane services do.
 *
 * The one-line forwards that remain have no production caller: the test
 * suite reads every query through this one seam, so they stay for it. The
 * behaviour, limits, and result shape are documented on the delegate named
 * in each `@see`.
 */
final readonly class ArchitectureQueryService
{
    /** @param QueryServices $services the services every method delegates to, and the database they read */
    public function __construct(
        private QueryServices $services,
    ) {}

    /**
     * The facade over every query service wired against `$pdo`; see
     * {@see QueryServices::wire()} for what each optional collaborator does.
     */
    public static function forDatabase(
        PDO $pdo,
        ?Closure $clock = null,
        ?SemanticRanker $semanticRanker = null,
        ?GitHistoryProvider $gitHistory = null,
        ?GitWorkingTreeProvider $gitWorkingTree = null,
        ?DriftOracle $driftOracle = null,
    ): self {
        return new self(QueryServices::wire($pdo, $clock, $semanticRanker, $gitHistory, $gitWorkingTree, $driftOracle));
    }

    /**
     * The services this facade delegates to, for a caller that wants one
     * directly: a query whose answer the facade only forwards gains nothing
     * by going through it, and the services are wired once already.
     */
    public function services(): QueryServices
    {
        return $this->services;
    }

    /** Root path recorded at scan time; used by the MCP layer to self-heal stale graphs. */
    public function projectRoot(string $projectId): ?string
    {
        return (new ProjectRepository($this->services->pdo))->rootOf($projectId);
    }

    /** {@see ProjectCatalogQueryService::listProjects()} */
    public function listProjects(int $limit = 50, int $offset = 0, bool $includeRoots = false): ResultEnvelope
    {
        return $this->services->catalogQueries->listProjects($limit, $offset, $includeRoots);
    }

    /** {@see ProjectCatalogQueryService::listSnapshots()} */
    public function listSnapshots(string $projectId, int $limit = 20, int $offset = 0): ResultEnvelope
    {
        return $this->services->catalogQueries->listSnapshots($projectId, $limit, $offset);
    }

    /** {@see SnapshotDiffQuery::snapshotDiff()} */
    public function snapshotDiff(string $projectId, string $fromSnapshot, string $toSnapshot = 'active', int $maxChanges = 25): ResultEnvelope
    {
        return $this->services->diffQueries->snapshotDiff($projectId, $fromSnapshot, $toSnapshot, $maxChanges);
    }

    /**
     * {@see QualityGateQueryService::branchComparison()}
     *
     * @param list<array<string, mixed>> $policies
     * @return array<string, mixed>
     */
    public function branchComparison(string $projectId, string $baseSnapshot, array $policies, int $limit = 8): array
    {
        return $this->services->gateQueries->branchComparison($projectId, $baseSnapshot, $policies, $limit);
    }

    /**
     * {@see QualityGateQueryService::qualityGate()}
     *
     * @param array<string, mixed> $budgets
     * @param list<array<string, mixed>> $policies
     */
    public function qualityGate(
        string $projectId,
        string $baselineSnapshot,
        array $budgets,
        array $policies = [],
        bool $sarif = false,
        bool $proposeBaseline = false,
    ): ResultEnvelope {
        return $this->services->gateQueries->qualityGate($projectId, $baselineSnapshot, $budgets, $policies, $sarif, $proposeBaseline);
    }

    /** {@see QualityGateQueryService::architectureTrends()} */
    public function architectureTrends(string $projectId, int $limit = 10, ?string $releaseFrom = null): ResultEnvelope
    {
        return $this->services->gateQueries->architectureTrends($projectId, $limit, $releaseFrom);
    }

    /** {@see ComponentQueryService::findComponent()} */
    public function findComponent(string $projectId, string $name, int $limit = 20): ResultEnvelope
    {
        return $this->withNotes($this->services->componentQueries->findComponent($projectId, $name, $limit));
    }

    /** {@see ComponentQueryService::inspectComponent()} */
    public function inspectComponent(
        string $projectId,
        string $component,
        int $maxRelationships = 25,
        int $maxChildren = 25,
        string $minConfidence = 'possible',
    ): ResultEnvelope {
        return $this->withNotes($this->services->componentQueries->inspectComponent($projectId, $component, $maxRelationships, $maxChildren, $minConfidence));
    }

    /**
     * {@see ComponentQueryService::listUsages()}
     *
     * @param list<string> $edgeKinds
     */
    public function listUsages(string $projectId, string $symbol, array $edgeKinds = [], string $minConfidence = 'possible', int $limit = 100, bool $excludeTests = false): ResultEnvelope
    {
        return $this->withNotes($this->services->componentQueries->listUsages($projectId, $symbol, $edgeKinds, $minConfidence, $limit, $excludeTests));
    }

    /** {@see GraphSummaryQuery::architectureSummary()} */
    public function architectureSummary(string $projectId, int $limit = 50): ResultEnvelope
    {
        return $this->services->summaryQueries->architectureSummary($projectId, $limit);
    }

    /** {@see FileMetricsQueryService::fileMetrics()} */
    public function fileMetrics(
        string $projectId,
        ?string $pathContains = null,
        ?string $language = null,
        string $sortBy = 'line_count',
        string $order = 'desc',
        int $limit = 50,
        int $offset = 0,
    ): ResultEnvelope {
        return $this->services->fileMetricsQueries->fileMetrics($projectId, $pathContains, $language, $sortBy, $order, $limit, $offset);
    }

    /**
     * {@see DependencyCycleQuery::dependencyCycles()}
     *
     * @param list<string> $edgeKinds
     * @param list<string> $touchingIds
     */
    public function dependencyCycles(
        string $projectId,
        array $edgeKinds = [],
        string $minConfidence = 'possible',
        int $limit = 20,
        int $maxNodes = QueryBounds::MAX_NODES,
        int $maxEdges = QueryBounds::MAX_EDGES,
        int $timeoutMs = 1000,
        bool $includeSelfLoops = false,
        array $touchingIds = [],
        bool $withinType = false,
    ): ResultEnvelope {
        return $this->services->cycleQueries->dependencyCycles($projectId, $edgeKinds, $minConfidence, $limit, $maxNodes, $maxEdges, $timeoutMs, $includeSelfLoops, $touchingIds, $withinType);
    }

    /**
     * {@see ArchitectureHealthQuery::architectureHealth()}
     *
     * @param list<string> $edgeKinds
     */
    public function architectureHealth(
        string $projectId,
        array $edgeKinds = [],
        string $minConfidence = 'possible',
        int $limit = 20,
        int $maxNodes = QueryBounds::MAX_NODES,
        int $maxEdges = QueryBounds::MAX_EDGES,
        int $timeoutMs = 1000,
        bool $includeExternal = false,
        bool $includeTests = false,
        string $candidateConfidence = 'possible',
        int $candidateOffset = 0,
        int $candidateTimeoutMs = 5000,
    ): ResultEnvelope {
        return $this->services->healthQueries->architectureHealth($projectId, $edgeKinds, $minConfidence, $limit, $maxNodes, $maxEdges, $timeoutMs, $includeExternal, $includeTests, $candidateConfidence, $candidateOffset, $candidateTimeoutMs);
    }

    /**
     * {@see ArchitecturePolicyQueryService::checkArchitecture()}
     *
     * @param list<array<string, mixed>> $policies
     * @param list<string> $sourceFiles
     */
    public function checkArchitecture(
        string $projectId,
        ?array $policies,
        string $minConfidence = 'possible',
        int $limit = 100,
        int $maxEdges = ArchitecturePolicyQueryService::DEFAULT_MAX_EDGES,
        int $timeoutMs = 1000,
        array $sourceFiles = [],
    ): ResultEnvelope {
        return $this->services->policyQueries->checkArchitecture($projectId, $policies, $minConfidence, $limit, $maxEdges, $timeoutMs, $sourceFiles);
    }

    /** {@see LocationSuggestionService::suggestLocation()} */
    public function suggestLocation(
        string $projectId,
        string $featureDescription,
        int $limit = 5,
        int $maxMembers = 20_000,
        int $maxEdges = QueryBounds::MAX_EDGES,
        int $timeoutMs = 1000,
        string $rankingMode = 'deterministic',
    ): ResultEnvelope {
        return $this->services->locationQueries->suggestLocation($projectId, $featureDescription, $limit, $maxMembers, $maxEdges, $timeoutMs, $rankingMode);
    }

    /**
     * {@see ChangeImpactQueryService::changeImpact()}
     *
     * @param list<string> $edgeKinds
     */
    public function changeImpact(
        string $projectId,
        string $symbol,
        int $sinceDays = 90,
        int $maxCommits = 500,
        int $maxDepth = 4,
        int $limit = 100,
        array $edgeKinds = [],
        string $minConfidence = 'possible',
        int $timeoutMs = 1000,
    ): ResultEnvelope {
        return $this->services->changeQueries->changeImpact($projectId, $symbol, $sinceDays, $maxCommits, $maxDepth, $limit, $edgeKinds, $minConfidence, $timeoutMs);
    }

    /**
     * {@see ChangeImpactQueryService::changedFilesImpact()}
     *
     * @param list<string> $files
     * @param list<string> $edgeKinds
     */
    public function changedFilesImpact(
        string $projectId,
        array $files = [],
        bool $workingTree = false,
        ?string $baseRef = null,
        int $maxDepth = 4,
        int $limit = 100,
        array $edgeKinds = [],
        string $minConfidence = 'possible',
        int $timeoutMs = 1000,
    ): ResultEnvelope {
        return $this->services->changeQueries->changedFilesImpact($projectId, $files, $workingTree, $baseRef, $maxDepth, $limit, $edgeKinds, $minConfidence, $timeoutMs);
    }

    /**
     * {@see ChangeImpactQueryService::testImpact()}
     *
     * @param list<string> $files
     * @param list<string> $edgeKinds
     */
    public function testImpact(
        string $projectId,
        array $files = [],
        bool $workingTree = false,
        ?string $baseRef = null,
        int $maxDepth = 4,
        int $limit = 100,
        array $edgeKinds = [],
        string $minConfidence = 'possible',
        int $timeoutMs = 1000,
    ): ResultEnvelope {
        return $this->services->changeQueries->testImpact($projectId, $files, $workingTree, $baseRef, $maxDepth, $limit, $edgeKinds, $minConfidence, $timeoutMs);
    }

    /**
     * {@see ReviewDiffService::reviewDiff()}
     *
     * @param list<string> $files
     * @param list<array<string, mixed>>|null $policies
     * @param array<string, int>|null $budgets
     */
    public function reviewDiff(
        string $projectId,
        ?string $baseRef = null,
        array $files = [],
        ?array $policies = null,
        ?array $budgets = null,
        ?string $baselineSnapshot = null,
        int $maxDepth = 4,
        int $limit = 100,
        string $minConfidence = 'possible',
        int $timeoutMs = 1000,
    ): ResultEnvelope {
        return $this->services->reviewQueries->reviewDiff($projectId, $baseRef, $files, $policies, $budgets, $baselineSnapshot, $maxDepth, $limit, $minConfidence, $timeoutMs);
    }

    /**
     * {@see ArchitectureContextService::architectureContext()}
     *
     * @param list<string> $files
     */
    public function architectureContext(
        string $projectId,
        string $taskDescription = '',
        array $files = [],
        int $maxChars = 30_000,
        int $timeoutMs = 1500,
        bool $includeSource = false,
    ): ResultEnvelope {
        return $this->services->contextQueries->architectureContext($projectId, $taskDescription, $files, $maxChars, $timeoutMs, $includeSource);
    }

    /**
     * {@see DiagramExportService::exportDiagram()}
     *
     * @param list<string> $edgeKinds
     */
    public function exportDiagram(
        string $projectId,
        string $format = 'mermaid',
        ?string $boundary = null,
        array $edgeKinds = [],
        string $minConfidence = 'possible',
        string $direction = 'LR',
        int $maxNodes = 200,
        int $maxEdges = 500,
    ): ResultEnvelope {
        return $this->services->diagramQueries->exportDiagram($projectId, $format, $boundary, $edgeKinds, $minConfidence, $direction, $maxNodes, $maxEdges);
    }

    /**
     * {@see FlowQuery::explainFlow()}
     *
     * @param list<string> $edgeKinds
     */
    public function explainFlow(
        string $projectId,
        string $from,
        string $to,
        int $maxDepth = 6,
        int $maxPaths = 5,
        array $edgeKinds = [],
        string $minConfidence = 'possible',
        int $timeoutMs = FlowQuery::DEFAULT_TIMEOUT_MS,
        bool $bothDirections = false,
        ?string $profile = null,
    ): ResultEnvelope {
        return $this->withNotes($this->services->flowQueries->explainFlow($projectId, $from, $to, $maxDepth, $maxPaths, $edgeKinds, $minConfidence, $timeoutMs, $bothDirections, $profile));
    }

    /**
     * {@see ImpactAnalysisQuery::impactAnalysis()}
     *
     * @param list<string> $edgeKinds
     */
    public function impactAnalysis(
        string $projectId,
        string $symbol,
        int $maxDepth = 6,
        int $limit = 100,
        array $edgeKinds = [],
        string $minConfidence = 'possible',
        int $timeoutMs = 1000,
        bool $seedMembers = true,
        bool $rings = false,
    ): ResultEnvelope {
        return $this->withNotes($this->services->impactQueries->impactAnalysis($projectId, $symbol, $maxDepth, $limit, $edgeKinds, $minConfidence, $timeoutMs, seedMembers: $seedMembers, rings: $rings));
    }

    /** {@see GraphSummaryQuery::listBoundaries()} */
    public function listBoundaries(string $projectId, ?string $source = null, int $limit = 50, int $offset = 0): ResultEnvelope
    {
        return $this->services->summaryQueries->listBoundaries($projectId, $source, $limit, $offset);
    }

    /** {@see AgentBriefService::exportAgentBrief()} */
    public function exportAgentBrief(string $projectId, int $maxChars = 4000): ResultEnvelope
    {
        return $this->services->briefQueries->exportAgentBrief($projectId, $maxChars);
    }

    /**
     * {@see ComponentQueryService::searchArchitecture()}
     *
     * @param list<string> $kinds
     * @param list<string> $roles
     * @param list<string> $boundaryIds
     * @param list<string> $confidences
     */
    public function searchArchitecture(
        string $projectId,
        string $query,
        array $kinds = [],
        array $roles = [],
        array $boundaryIds = [],
        array $confidences = [],
        int $limit = 20,
        int $offset = 0,
        bool $includeFiles = false,
    ): ResultEnvelope {
        // A note can be why a component matched, so its results carry their notes.
        return $this->withNotes($this->services->componentQueries->searchArchitecture($projectId, $query, $kinds, $roles, $boundaryIds, $confidences, $limit, $offset, $includeFiles));
    }

    /**
     * {@see AnnotationService::upsertAnnotation()}
     *
     * @param list<string> $evidencePaths
     */
    public function upsertAnnotation(string $projectId, string $component, string $kind, string $value = '', bool $execute = false, string $author = 'model', array $evidencePaths = [], bool $replace = false): ResultEnvelope
    {
        return $this->services->annotationQueries->upsertAnnotation($projectId, $component, $kind, $value, $execute, $author, $evidencePaths, $replace);
    }

    /** {@see AnnotationService::removeAnnotation()} */
    public function removeAnnotation(string $projectId, string $component, string $kind, string $value = '', bool $execute = false, string $author = 'model'): ResultEnvelope
    {
        return $this->services->annotationQueries->removeAnnotation($projectId, $component, $kind, $value, $execute, $author);
    }

    /** {@see FileContextQueryService::fileContext()} */
    public function fileContext(string $projectId, string $path): ResultEnvelope
    {
        $result = $this->services->fileContextQueries->fileContext($projectId, $path);
        // A file's context counts its components without naming them: the
        // notes are those of the components the file declares, looked up by
        // the path the result normalised, however the caller wrote it.
        $declared = $this->services->pdo->prepare(
            'SELECT n.canonical_name FROM nodes n JOIN files f ON f.id = n.file_id WHERE f.project_id = ? AND f.relative_path = ? LIMIT ' . AnnotationNotes::NOTE_LOOKUP_NAMES,
        );
        $declared->execute([$projectId, (string) ($result->data['path'] ?? $path)]);

        return $this->withNotes($result, $declared->fetchAll(PDO::FETCH_COLUMN));
    }

    /** {@see DiagnosticsQueryService::listDiagnostics()} */
    public function listDiagnostics(string $projectId, ?string $severity = null, ?string $pathPrefix = null, int $limit = 100, int $offset = 0): ResultEnvelope
    {
        return $this->services->diagnosticsQueries->listDiagnostics($projectId, $severity, $pathPrefix, $limit, $offset);
    }

    /** {@see AnnotationService::listAnnotations()} */
    public function listAnnotations(string $projectId, ?string $component = null, ?string $kind = null, int $limit = 100, int $offset = 0): ResultEnvelope
    {
        return $this->services->annotationQueries->listAnnotations($projectId, $component, $kind, $limit, $offset);
    }

    /**
     * The result with the notes of the components it names; see
     * {@see AnnotationNotes::attach()}.
     *
     * @param list<string> $alsoNamed components the result bears on without naming them in its data
     */
    private function withNotes(ResultEnvelope $result, array $alsoNamed = []): ResultEnvelope
    {
        return (new AnnotationNotes($this->services->pdo))->attach($result, $alsoNamed);
    }
}
