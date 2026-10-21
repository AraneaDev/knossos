<?php

declare(strict_types=1);

namespace Knossos\Store;

use InvalidArgumentException;
use PDO;

/**
 * Every row a scan writes into the graph, one statement per table.
 *
 * Statements come from the shared cache because a scan replays each of them
 * thousands of times. Nothing here opens a transaction: the reconciler owns the
 * transaction a scan's writes run inside.
 */
final class SqliteGraphWriter
{
    private const FILE_COLUMNS = ['id', 'project_id', 'relative_path', 'content_hash', 'size', 'mtime', 'language', 'scanner_version', 'last_scan_id', 'line_count'];

    private const NODE_COLUMNS = ['id', 'project_id', 'language', 'kind', 'canonical_name', 'display_name', 'parent_id', 'file_id', 'start_line', 'end_line', 'origin', 'confidence', 'attributes_json', 'owner_key', 'last_scan_id'];

    private const EDGE_COLUMNS = ['id', 'project_id', 'kind', 'source_id', 'target_id', 'file_id', 'start_line', 'end_line', 'origin', 'confidence', 'attributes_json', 'owner_key', 'last_scan_id'];

    private const CLASSIFICATION_COLUMNS = ['id', 'project_id', 'node_id', 'role', 'origin', 'confidence', 'rule_id', 'file_id', 'start_line', 'end_line', 'attributes_json', 'last_scan_id'];

    private const BOUNDARY_COLUMNS = ['id', 'project_id', 'name', 'matcher_json', 'source', 'last_scan_id'];

    public function __construct(private readonly SqliteStatementCache $statements) {}

    /** Record one discovered file and the fingerprint that lets the next scan decide whether to re-analyse it. */
    public function saveFile(
        string $id,
        string $projectId,
        string $relativePath,
        string $contentHash,
        int $size,
        int $mtime,
        string $language,
        string $scannerVersion,
        string $scanId,
        int $lineCount,
    ): void {
        $this->saveFiles([[
            'id' => $id,
            'relative_path' => $relativePath,
            'content_hash' => $contentHash,
            'size' => $size,
            'mtime' => $mtime,
            'language' => $language,
            'scanner_version' => $scannerVersion,
            'line_count' => $lineCount,
        ]], $projectId, $scanId);
    }

    /**
     * Persist one graph node.
     *
     * @param string $ownerKey the contributing scanner, so reconciliation can replace
     *        one analyzer's facts without disturbing another's
     * @param array<string, mixed> $attributes
     */
    public function saveNode(
        string $id,
        string $projectId,
        string $language,
        string $kind,
        string $canonicalName,
        string $displayName,
        ?string $parentId,
        ?string $fileId,
        ?int $startLine,
        ?int $endLine,
        string $origin,
        string $confidence,
        array $attributes,
        string $ownerKey,
        string $scanId,
    ): void {
        $this->saveNodes([[
            'id' => $id,
            'language' => $language,
            'kind' => $kind,
            'canonical_name' => $canonicalName,
            'display_name' => $displayName,
            'parent_id' => $parentId,
            'file_id' => $fileId,
            'start_line' => $startLine,
            'end_line' => $endLine,
            'origin' => $origin,
            'confidence' => $confidence,
            'attributes' => $attributes,
            'owner_key' => $ownerKey,
        ]], $projectId, $scanId);
    }

    /**
     * Persist one relationship.
     *
     * @param string $confidence how far the edge is inferred rather than proven; queries
     *        filter on it, so a guess must never be recorded as certain
     * @param array<string, mixed> $attributes
     */
    public function saveEdge(
        string $id,
        string $projectId,
        string $kind,
        string $sourceId,
        string $targetId,
        ?string $fileId,
        ?int $startLine,
        ?int $endLine,
        string $origin,
        string $confidence,
        array $attributes,
        string $ownerKey,
        string $scanId,
    ): void {
        $this->saveEdges([[
            'id' => $id,
            'kind' => $kind,
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'file_id' => $fileId,
            'start_line' => $startLine,
            'end_line' => $endLine,
            'origin' => $origin,
            'confidence' => $confidence,
            'attributes' => $attributes,
            'owner_key' => $ownerKey,
        ]], $projectId, $scanId);
    }

    /**
     * Insert many nodes in one prepared batch, since a scan writes thousands.
     *
     * A row may carry parent_id; the reconciler's records do not, so a scan
     * writes every node without a parent.
     *
     * @param list<array<string, mixed>> $nodes rows shaped as GraphReconciler node records
     */
    public function saveNodes(array $nodes, string $projectId, string $scanId): void
    {
        $this->upsert('nodes', self::NODE_COLUMNS, ['id'], array_map(static fn(array $node): array => [
            $node['id'], $projectId, $node['language'], $node['kind'], $node['canonical_name'], $node['display_name'],
            $node['parent_id'] ?? null, $node['file_id'], $node['start_line'], $node['end_line'], $node['origin'],
            $node['confidence'], SqliteValues::json($node['attributes']), $node['owner_key'], $scanId,
        ], $nodes));
    }

    /**
     * Insert many edges in one prepared batch.
     *
     * @param list<array<string, mixed>> $edges rows shaped as GraphReconciler edge records
     */
    public function saveEdges(array $edges, string $projectId, string $scanId): void
    {
        $this->upsert('edges', self::EDGE_COLUMNS, ['id'], array_map(static fn(array $edge): array => [
            $edge['id'], $projectId, $edge['kind'], $edge['source_id'], $edge['target_id'], $edge['file_id'],
            $edge['start_line'], $edge['end_line'], $edge['origin'], $edge['confidence'],
            SqliteValues::json($edge['attributes']), $edge['owner_key'], $scanId,
        ], $edges));
    }

    /**
     * Insert many file rows in one prepared batch.
     *
     * @param list<array<string, mixed>> $files each: id, relative_path, content_hash, size, mtime, language, scanner_version, line_count
     */
    public function saveFiles(array $files, string $projectId, string $scanId): void
    {
        $this->upsert('files', self::FILE_COLUMNS, ['project_id', 'relative_path'], array_map(static fn(array $file): array => [
            $file['id'], $projectId, $file['relative_path'], $file['content_hash'], $file['size'], $file['mtime'],
            $file['language'], $file['scanner_version'], $scanId, $file['line_count'],
        ], $files));
    }

    /**
     * Insert many classifications in one prepared batch.
     *
     * @param list<array<string, mixed>> $classifications each shaped as a GraphReconciler classification record
     */
    public function saveClassifications(array $classifications, string $projectId, string $scanId): void
    {
        $this->upsert('classifications', self::CLASSIFICATION_COLUMNS, ['id'], array_map(static fn(array $classification): array => [
            $classification['id'], $projectId, $classification['node_id'], $classification['role'], $classification['origin'],
            $classification['confidence'], $classification['rule_id'], $classification['file_id'], $classification['start_line'],
            $classification['end_line'], SqliteValues::json($classification['attributes']), $scanId,
        ], $classifications));
    }

    /**
     * Write rows through the guarded upsert for $table, chunked to stay under
     * SQLite's bound-variable limit.
     *
     * @param list<string> $columns
     * @param list<string> $conflict
     * @param list<list<mixed>> $rows each in $columns order
     */
    private function upsert(string $table, array $columns, array $conflict, array $rows): void
    {
        // 60 rows of at most 15 columns stay under SQLite's historic 999-variable floor.
        foreach (array_chunk($rows, 60) as $chunk) {
            $this->statements->prepare(Upsert::sql($table, $columns, $conflict, count($chunk)))
                ->execute(array_merge(...$chunk));
        }
    }

    /**
     * Insert many boundary memberships in one prepared batch.
     *
     * @param list<array<string, mixed>> $memberships each: boundary_id, node_id
     */
    public function saveBoundaryMemberships(array $memberships, string $projectId, string $scanId): void
    {
        foreach (array_chunk($memberships, 240) as $chunk) { // 4 params/row
            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?)'));
            $sql = 'INSERT OR IGNORE INTO boundary_memberships(boundary_id, project_id, node_id, last_scan_id) VALUES ' . $placeholders;
            $values = [];
            foreach ($chunk as $membership) {
                array_push($values, $membership['boundary_id'], $projectId, $membership['node_id'], $scanId);
            }
            $this->statements->prepare($sql)->execute($values);
        }
    }

    /** Record a scanner-reported problem against a file, surfaced with the scan rather than thrown. */
    public function saveDiagnostic(
        string $id,
        string $projectId,
        string $scanId,
        ?string $fileId,
        string $severity,
        string $code,
        string $message,
        ?int $startLine,
        ?int $endLine,
        string $ownerKey,
    ): void {
        $statement = $this->statements->prepare(
            'INSERT INTO diagnostics(id, project_id, scan_id, file_id, severity, code, message, start_line, end_line, owner_key) ' .
            'VALUES (:id, :project, :scan, :file, :severity, :code, :message, :start, :end, :owner)',
        );
        $statement->execute([
            'id' => $id,
            'project' => $projectId,
            'scan' => $scanId,
            'file' => $fileId,
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'start' => $startLine,
            'end' => $endLine,
            'owner' => $ownerKey,
        ]);
    }

    /**
     * Record an inferred role for a node, attributed to the rule that inferred it.
     *
     * @param array<string, mixed> $attributes
     */
    public function saveClassification(
        string $id,
        string $projectId,
        string $nodeId,
        string $role,
        string $origin,
        string $confidence,
        string $ruleId,
        ?string $fileId,
        ?int $startLine,
        ?int $endLine,
        array $attributes,
        string $scanId,
    ): void {
        $this->saveClassifications([[
            'id' => $id, 'node_id' => $nodeId, 'role' => $role, 'origin' => $origin, 'confidence' => $confidence,
            'rule_id' => $ruleId, 'file_id' => $fileId, 'start_line' => $startLine, 'end_line' => $endLine,
            'attributes' => $attributes,
        ]], $projectId, $scanId);
    }

    /**
     * Persist a boundary, declared or inferred.
     *
     * @param array<string, mixed> $matcher the path/name rules defining membership
     */
    public function saveBoundary(string $id, string $projectId, string $name, array $matcher, string $source, string $scanId): void
    {
        $this->upsert('boundaries', self::BOUNDARY_COLUMNS, ['id'], [[
            $id, $projectId, $name, SqliteValues::json($matcher), $source, $scanId,
        ]]);
    }

    /** Attach a node to a boundary. Separate from the boundary itself so membership can be recomputed alone. */
    public function saveBoundaryMembership(string $boundaryId, string $projectId, string $nodeId, string $scanId): void
    {
        $this->saveBoundaryMemberships([['boundary_id' => $boundaryId, 'node_id' => $nodeId]], $projectId, $scanId);
    }

    /**
     * Bring a project's incremental-reuse cache to the given entries, writing by difference.
     *
     * An entry carried over from the previous scan keeps its row and reads; a
     * stale one would let the next scan reuse facts for a file it should have
     * re-analysed, so every other entry is replaced and every owner not named is
     * removed. Deletes are explicit because a bulk transaction runs with foreign
     * keys off and cascades do not fire.
     *
     * @param list<ContributionCacheEntry> $entries
     * @param array<string, array<string, ?string>> $readGroups group id to the reads it shares
     */
    public function replaceContributionCache(string $projectId, array $entries, array $readGroups = []): void
    {
        $pdo = $this->statements->pdo();
        $wanted = [];
        foreach ($entries as $entry) {
            $wanted[$entry->contribution->ownerKey] = true;
        }
        $storedGroups = $this->statements->prepare('SELECT 1 FROM contribution_read_groups WHERE project_id = :project AND group_id = :group LIMIT 1');
        foreach ($entries as $entry) {
            if ($entry->fromCache || $entry->readGroup === null || isset($readGroups[$entry->readGroup])) {
                continue;
            }
            $storedGroups->execute(['project' => $projectId, 'group' => $entry->readGroup]);
            if ($storedGroups->fetchColumn() === false) {
                throw new InvalidArgumentException(sprintf('Contribution cache entry %s names an unknown read group.', $entry->contribution->ownerKey));
            }
        }
        $existing = $pdo->prepare('SELECT owner_key FROM contribution_cache WHERE project_id = :project');
        $existing->execute(['project' => $projectId]);
        $deleteReads = $this->statements->prepare('DELETE FROM contribution_reads WHERE project_id = :project AND owner_key = :owner');
        $deleteOwner = $this->statements->prepare('DELETE FROM contribution_cache WHERE project_id = :project AND owner_key = :owner');
        foreach ($existing->fetchAll(PDO::FETCH_COLUMN) as $owner) {
            if (!isset($wanted[(string) $owner])) {
                $deleteReads->execute(['project' => $projectId, 'owner' => $owner]);
                $deleteOwner->execute(['project' => $projectId, 'owner' => $owner]);
            }
        }
        $upsert = $this->statements->prepare(
            'INSERT INTO contribution_cache(project_id, owner_key, file_path, content_hash, scanner_id, scanner_version, ' .
            'configuration_hash, payload_json, updated_at, read_attribution, read_group) VALUES (:project, :owner, :path, :hash, ' .
            ':scanner, :version, :config, :payload, :updated, :attributed, :group) ON CONFLICT(project_id, owner_key) DO UPDATE SET ' .
            'file_path = excluded.file_path, content_hash = excluded.content_hash, scanner_id = excluded.scanner_id, ' .
            'scanner_version = excluded.scanner_version, configuration_hash = excluded.configuration_hash, ' .
            'payload_json = excluded.payload_json, updated_at = excluded.updated_at, ' .
            'read_attribution = excluded.read_attribution, read_group = excluded.read_group',
        );
        $insertRead = $this->statements->prepare(
            'INSERT INTO contribution_reads(project_id, owner_key, read_path, read_hash) VALUES (:project, :owner, :path, :hash)',
        );
        foreach ($entries as $entry) {
            if ($entry->fromCache) {
                continue;
            }
            $owner = $entry->contribution->ownerKey;
            $upsert->execute([
                'project' => $projectId,
                'owner' => $owner,
                'path' => $entry->filePath,
                'hash' => $entry->contentHash,
                'scanner' => $entry->scannerId,
                'version' => $entry->scannerVersion,
                'config' => $entry->configurationHash,
                'payload' => self::cachePayload($entry->contribution),
                'updated' => SqliteValues::now(),
                // 2: attributed, with reads that cannot cover the file
                // (ContributionCacheEntry::$readsIncomplete).
                'attributed' => $entry->readAttribution ? ($entry->readsIncomplete ? 2 : 1) : 0,
                'group' => $entry->readGroup,
            ]);
            $deleteReads->execute(['project' => $projectId, 'owner' => $owner]);
            foreach ($entry->reads as $path => $hash) {
                $insertRead->execute(['project' => $projectId, 'owner' => $owner, 'path' => (string) $path, 'hash' => $hash]);
            }
        }
        $this->storeReadGroups($projectId, $readGroups);
    }

    /**
     * The stored form of a cached contribution: its wire shape without the
     * reads, which contribution_reads already holds and which a reused entry
     * takes from there.
     */
    private static function cachePayload(\Knossos\Scanner\Protocol\ScanContribution $contribution): string
    {
        $payload = $contribution->jsonSerialize();
        unset($payload['reads']);

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Store the read groups not yet present and drop those no entry references.
     *
     * Groups are content-addressed, so an id that is already stored never changes.
     *
     * @param array<string, array<string, ?string>> $readGroups
     */
    private function storeReadGroups(string $projectId, array $readGroups): void
    {
        $known = $this->statements->prepare('SELECT 1 FROM contribution_read_groups WHERE project_id = :project AND group_id = :group LIMIT 1');
        $insert = $this->statements->prepare(
            'INSERT INTO contribution_read_groups(project_id, group_id, read_path, read_hash) VALUES (:project, :group, :path, :hash)',
        );
        foreach ($readGroups as $groupId => $reads) {
            $known->execute(['project' => $projectId, 'group' => (string) $groupId]);
            if ($known->fetchColumn() !== false) {
                continue;
            }
            foreach ($reads as $path => $hash) {
                $insert->execute(['project' => $projectId, 'group' => (string) $groupId, 'path' => (string) $path, 'hash' => $hash]);
            }
        }
        $this->statements->prepare(
            'DELETE FROM contribution_read_groups WHERE project_id = :project AND group_id NOT IN ' .
            '(SELECT read_group FROM contribution_cache WHERE project_id = :project AND read_group IS NOT NULL)',
        )->execute(['project' => $projectId]);
    }
}
