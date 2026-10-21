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
 * refreshes by. Every other query is read from {@see self::services()}, as
 * the tools, the CLI, the pane services and the tests do. The behaviour,
 * limits, and result shape of each method here are documented on the
 * delegate named in its `@see`.
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
     * The services this facade delegates to, wired once: every query the
     * facade does not annotate with notes is read from here.
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
