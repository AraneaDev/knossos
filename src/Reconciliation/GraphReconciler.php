<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

use Knossos\Discovery\DiscoveredFile;
use Knossos\Discovery\UnitInputSet;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Store\GraphRepository;

/**
 * Merges scanner contributions into the persisted graph.
 *
 * The hard part is identity: facts arrive per file with canonical names, and have
 * to become stable ids, resolved edges, and per-scanner ownership so one language's
 * facts can be replaced without disturbing another's. {@see GraphAssembler} does
 * that resolution; this class persists the result in one transaction — a
 * partially reconciled graph would answer queries confidently and wrongly.
 */
final readonly class GraphReconciler
{
    public function __construct(
        private GraphRepository $repository,
    ) {}

    /**
     * Merge a scan's contributions into the graph, in one transaction.
     *
     * @param ?callable(): void $beforeBulkTransaction an optional final check
     *        run after reconciliation preparation and before persistence
     */
    public function reconcile(FullScanRequest $request, ?callable $beforeBulkTransaction = null): ReconciliationResult
    {
        // The timer starts before any other pre-transaction work, including
        // the file id hashing, so 'prepare' captures the full duration of all
        // pre-transaction preparation.
        $timer = new PhaseTimer();
        $graph = (new GraphAssembler())->assemble($request);
        $timer->mark('prepare');

        if ($beforeBulkTransaction !== null) {
            $beforeBulkTransaction();
        }
        $diagnosticCount = $this->persist($graph, $timer);
        // The commit is a phase of its own: it writes every page the reconcile
        // dirtied, which on this repository is a third of the reconciliation and
        // was previously invisible — the phase timings summed to well under the
        // total and gave no clue where the difference went.
        $timer->mark('commit');

        return new ReconciliationResult(
            $graph->projectId,
            $graph->scanId,
            count($request->discovery->files),
            count($graph->nodes),
            count($graph->edges),
            $diagnosticCount,
            $graph->unresolvedNodes,
            $timer->phases(),
        );
    }


    /**
     * Write a prepared graph in one transaction, returning how many diagnostics it saved.
     *
     * A rewrite of this size is dominated by per-statement foreign-key
     * enforcement, so integrity is verified once before the commit instead.
     */
    private function persist(PreparedGraph $graph, PhaseTimer $timer): int
    {
        $diagnosticCount = 0;
        $this->repository->bulkTransaction(function () use ($graph, $timer, &$diagnosticCount): void {
            $existing = $this->openScan($graph, $timer);
            $memberships = $this->writeGraph($graph, $timer);

            $this->repository->pruneGraph($graph->projectId, $existing, [
                'files' => array_fill_keys(array_values($graph->fileIds), true),
                'nodes' => array_fill_keys(array_keys($graph->nodes), true),
                'edges' => array_fill_keys(array_keys($graph->edges), true),
                'classifications' => array_fill_keys(array_column($graph->classifications, 'id'), true),
                'boundaries' => array_fill_keys(array_column($graph->boundaries, 'id'), true),
                'boundary_memberships' => array_fill_keys(array_map(
                    static fn(array $membership): string => $membership['boundary_id'] . "\0" . $membership['node_id'],
                    $memberships,
                ), true),
            ]);
            // Rows this scan left untouched are still current, so they carry its
            // id too; nothing indexes the column, so this rewrites rows without
            // touching an index.
            $this->repository->stampGraphScan($graph->projectId, $graph->scanId);
            $timer->mark('prune');

            $this->repository->replaceContributionCache($graph->projectId, $graph->request->contributionCache, $graph->request->readGroups);
            $timer->mark('contribution_cache');

            // Diagnostics belong to the scan that produced them, so the previous
            // scan's are replaced wholesale rather than diffed.
            $this->repository->clearProjectDiagnostics($graph->projectId);
            $diagnosticCount = (new ReconciliationDiagnostics($this->repository))->save(
                $graph->request,
                $graph->projectId,
                $graph->scanId,
                $graph->fileIds,
                $graph->warnings,
            );
            // completeScan falls inside the save_diagnostics window (same rationale as
            // read_existing folding in saveProject/createScan): it's a cheap trailing
            // bookkeeping write, not worth its own phase.
            $this->repository->completeScan($graph->projectId, $graph->scanId);
            $timer->mark('save_diagnostics');
        });

        return $diagnosticCount;
    }

    /**
     * Archive the previous snapshot, record the project and the scan, and read
     * the ids the graph holds now, so what this scan does not produce can be
     * deleted afterwards.
     *
     * @return array<string, array<string, true>> the ids the graph holds now, per table
     */
    private function openScan(PreparedGraph $graph, PhaseTimer $timer): array
    {
        $request = $graph->request;
        $previousProject = $this->repository->findProject($graph->projectId);
        $this->repository->archiveActiveSnapshot(
            $graph->projectId,
            hash('sha256', (string) ($previousProject['config_json'] ?? '{}')),
            $request->projectConfig['snapshot_retention'] ?? GraphRepository::DEFAULT_SNAPSHOT_RETENTION,
        );
        $timer->mark('archive_snapshot');

        // saveProject/createScan fall inside the read_existing window per the
        // phase-timing contract: they are cheap bookkeeping writes that
        // immediately precede the read, and splitting them into their own
        // phase would add noise without profiling value.
        $this->repository->saveProject(
            $graph->projectId,
            $request->projectName,
            $request->discovery->rootRealpath,
            $request->projectConfig,
        );
        $this->repository->createScan(
            $graph->scanId,
            $graph->projectId,
            $request->mode,
            $graph->scannerSetHash,
            // Taken from the request, not resolved here. Two reasons, and the
            // second is the one that matters: shelling out while the graph write
            // lock is held would hold that lock for a subprocess timeout, and
            // resolving at this point resolves *after* discovery has read every
            // file, so a commit landing in between records a commit this graph was
            // never built against. The caller captures it before the walk; see
            // {@see \Knossos\Scan\ScanPlanner}.
            $request->gitHead,
            $request->dirtyPaths?->encode(),
            // The manifests and successful worker dependency reads this
            // scan used but stores no files row for. Without them, editing
            // composer.json or a node_modules declaration changes what a
            // scan would produce while the graph reports itself fresh.
            UnitInputSet::of($request->discovery->units)->encode($request->workerInputs),
        );
        // Reading ids is what makes the write proportional to the change:
        // clearing the project first meant every row had to be written back
        // whether or not the scan altered it.
        $existing = $this->repository->existingGraphIds($graph->projectId);
        $timer->mark('read_existing');

        return $existing;
    }

    /**
     * Write the prepared files, nodes, edges, classifications and boundaries.
     *
     * @return list<array{boundary_id: string, node_id: string}> the boundary memberships written
     */
    private function writeGraph(PreparedGraph $graph, PhaseTimer $timer): array
    {
        $projectId = $graph->projectId;
        $scanId = $graph->scanId;
        $versions = $this->scannerVersions($graph->request->scanners);
        $this->repository->saveFiles($this->fileRows($graph->request->discovery->files, $graph->fileIds, $versions), $projectId, $scanId);
        $timer->mark('save_files');

        $this->repository->saveNodes(array_values($graph->nodes), $projectId, $scanId);
        $timer->mark('save_nodes');
        $this->repository->saveEdges(array_values($graph->edges), $projectId, $scanId);
        $timer->mark('save_edges');

        $this->repository->saveClassifications($graph->classifications, $projectId, $scanId);
        $timer->mark('save_classifications');

        $memberships = [];
        foreach ($graph->boundaries as $boundary) {
            $this->repository->saveBoundary(
                $boundary['id'],
                $projectId,
                $boundary['name'],
                $boundary['matcher'],
                $boundary['source'],
                $scanId,
            );
            foreach ($boundary['node_ids'] as $nodeId) {
                $memberships[] = ['boundary_id' => $boundary['id'], 'node_id' => $nodeId];
            }
        }
        $this->repository->saveBoundaryMemberships($memberships, $projectId, $scanId);
        $timer->mark('save_boundaries');

        return $memberships;
    }

    /**
     * The file rows this scan wrote, indexed for node attachment.
     *
     * @param list<DiscoveredFile> $files
     * @param array<string, string> $fileIds relative path => stable file id
     * @param array<string, string> $versions language => scanner version
     * @return list<array<string, mixed>>
     */
    private function fileRows(array $files, array $fileIds, array $versions): array
    {
        $rows = [];
        foreach ($files as $file) {
            $rows[] = [
                'id' => $fileIds[$file->relativePath],
                'relative_path' => $file->relativePath,
                'content_hash' => $file->contentHash,
                'size' => $file->size,
                'mtime' => $file->mtime,
                'language' => $file->language,
                'scanner_version' => $versions[$file->language] ?? 'unknown',
                'line_count' => $file->lineCount,
            ];
        }
        return $rows;
    }

    /**
     * Which scanner produced each contribution, recorded for provenance.
     *
     * @param list<ScannerManifest> $scanners @return array<string, string>
     */
    private function scannerVersions(array $scanners): array
    {
        $versions = [];
        foreach ($scanners as $scanner) {
            foreach ($scanner->languages as $language) {
                $versions[$language] = $scanner->id . '@' . $scanner->version;
            }
        }
        return $versions;
    }
}
