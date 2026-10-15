<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use Knossos\Result\ResultEnvelope;
use Knossos\Store\ChunkedInQuery;
use PDO;

/**
 * Finds and inspects individual components.
 *
 * Name lookup is deliberately fuzzy — a caller rarely knows the fully-qualified
 * name — while an ambiguous match is reported as ambiguous rather than resolved to
 * a guess.
 */
final readonly class ComponentQueryService extends AbstractArchitectureQueryService
{
    /** Find components by name, reporting an ambiguous match rather than guessing. */
    public function findComponent(string $projectId, string $name, int $limit = 20): ResultEnvelope
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Component name must not be empty.');
        }
        self::assertLimit($limit);
        $project = $this->project($projectId);
        $statement = $this->pdo->prepare(
            'SELECT n.*, f.relative_path FROM nodes n LEFT JOIN files f ON f.id = n.file_id ' .
            'WHERE n.project_id = :project AND (n.canonical_name = :name OR n.display_name = :name ' .
            "OR n.canonical_name LIKE :prefix ESCAPE '!' OR n.display_name LIKE :prefix ESCAPE '!' " .
            "OR n.canonical_name LIKE :contains ESCAPE '!' OR n.display_name LIKE :contains ESCAPE '!') " .
            'ORDER BY CASE WHEN n.canonical_name = :name THEN 0 WHEN n.display_name = :name THEN 1 ' .
            "WHEN n.canonical_name LIKE :prefix ESCAPE '!' THEN 2 WHEN n.display_name LIKE :prefix ESCAPE '!' THEN 3 ELSE 4 END, " .
            'n.canonical_name LIMIT :fetch_limit',
        );
        $statement->bindValue(':project', $projectId);
        $statement->bindValue(':name', $name);
        $statement->bindValue(':prefix', self::like($name) . '%');
        $statement->bindValue(':contains', '%' . self::like($name) . '%');
        $statement->bindValue(':fetch_limit', $limit + 1, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        // A Python module is named `app.models` and a PHP file has no module
        // node, so a path names no component: asked by a path no name
        // matches, the answer is what that file declares, its module first.
        $declaredIn = $rows === [] ? $this->declaringFile($project, $name) : null;
        if ($declaredIn !== null) {
            $declared = $this->pdo->prepare(
                'SELECT n.*, f.relative_path FROM nodes n JOIN files f ON f.id = n.file_id ' .
                "WHERE f.project_id = :project AND f.relative_path = :path ORDER BY CASE WHEN n.kind = 'module' THEN 0 ELSE 1 END, n.start_line, n.canonical_name LIMIT :fetch_limit",
            );
            $declared->bindValue(':project', $projectId);
            $declared->bindValue(':path', $declaredIn);
            $declared->bindValue(':fetch_limit', $limit + 1, PDO::PARAM_INT);
            $declared->execute();
            $rows = $declared->fetchAll();
        }
        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $rolesByNode = $this->roles(array_column($rows, 'id'));
        $apart = $this->declaredApart(array_column($rows, 'id'));
        $components = [];
        $evidence = [];
        foreach ($rows as $row) {
            $components[] = [
                'id' => $row['id'], 'kind' => $row['kind'], 'canonical_name' => $row['canonical_name'],
                'display_name' => $row['display_name'], 'origin' => $row['origin'],
                'confidence' => $row['confidence'], 'roles' => $rolesByNode[$row['id']] ?? [],
                'attributes' => self::decode($row['attributes_json']),
            ] + (isset($apart[$row['id']]) ? ['declared_in' => $apart[$row['id']]] : []);
            // A file component has no file row; its path is its name.
            $path = $row['relative_path'] ?? ($row['kind'] === 'file' ? $row['canonical_name'] : null);
            if ($path !== null) {
                $evidence[] = [
                    'component_id' => $row['id'], 'path' => $path,
                    'start_line' => $row['start_line'], 'end_line' => $row['end_line'],
                ];
            }
        }
        $count = count($components);

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            match (true) {
                $count === 0 => sprintf('No component matched "%s".', $name),
                $declaredIn !== null => sprintf('No name matched; %s declares %d component%s.', $declaredIn, $count, $count === 1 ? '' : 's'),
                default => sprintf('Found %d component candidate%s.', $count, $count === 1 ? '' : 's'),
            },
            // What one file declares is a list, not a choice between candidates.
            ['query' => $name, 'components' => $components, 'ambiguous' => $declaredIn === null && $count > 1]
                + ($declaredIn !== null && $count > 0 ? ['declared_in' => $declaredIn] : []),
            $evidence,
            [],
            $truncated,
        );
    }

    /**
     * The project file `$name` names, when it reads as a path (it holds a `/`
     * or ends in an extension) and the project holds that file, read as
     * `file_context` reads a path; null otherwise.
     *
     * @param array<string, mixed> $project
     */
    private function declaringFile(array $project, string $name): ?string
    {
        if (!str_contains($name, '/') && preg_match('/\.[A-Za-z0-9]{1,8}$/', $name) !== 1) {
            return null;
        }
        try {
            $path = ProjectRelativePath::of((string) $project['root_realpath'], $name);
        } catch (InvalidArgumentException) {
            return null;
        }
        $file = $this->pdo->prepare('SELECT 1 FROM files WHERE project_id = :project AND relative_path = :path');
        $file->execute(['project' => $project['id'], 'path' => $path]);

        return $file->fetchColumn() === false ? null : $path;
    }
    /** One component in detail: its members, relationships, and evidence. */

    public function inspectComponent(string $projectId, string $component, int $maxRelationships = 25, int $maxChildren = 25, string $minConfidence = 'possible'): ResultEnvelope
    {
        $project = $this->project($projectId);
        self::assertLimit($maxRelationships);
        self::assertLimit($maxChildren);
        $confidenceRank = self::CONFIDENCE_RANK;
        if (!isset($confidenceRank[$minConfidence])) {
            throw new InvalidArgumentException('min_confidence must be possible, probable, or certain.');
        }
        $matches = $this->resolve($projectId, $component);
        if (count($matches) !== 1) {
            return new ResultEnvelope(
                $projectId,
                $project['active_scan_id'],
                $matches === [] ? sprintf('No component matched "%s".', $component) : sprintf('Component "%s" is ambiguous.', $component),
                ['query' => $component, 'component' => null, 'ambiguous' => count($matches) > 1, 'candidates' => $matches],
            );
        }
        $nodeId = $matches[0]['id'];
        $statement = $this->pdo->prepare(
            'SELECT n.*, f.relative_path, p.id AS parent_component_id, p.kind AS parent_kind, ' .
            'p.canonical_name AS parent_canonical_name FROM nodes n LEFT JOIN files f ON f.id = n.file_id ' .
            'LEFT JOIN nodes p ON p.id = n.parent_id WHERE n.project_id = :project AND n.id = :node',
        );
        $statement->execute(['project' => $projectId, 'node' => $nodeId]);
        $node = $statement->fetch();
        if ($node === false) {
            throw new InvalidArgumentException('Resolved component no longer exists.');
        }

        $childrenStatement = $this->pdo->prepare(
            'SELECT n.id, n.kind, n.canonical_name, n.display_name, n.confidence, f.relative_path, n.start_line, n.end_line ' .
            'FROM nodes n LEFT JOIN files f ON f.id = n.file_id WHERE n.project_id = :project AND n.parent_id = :node ' .
            'ORDER BY n.canonical_name, n.id LIMIT :limit',
        );
        $childrenStatement->bindValue(':project', $projectId);
        $childrenStatement->bindValue(':node', $nodeId);
        $childrenStatement->bindValue(':limit', $maxChildren + 1, PDO::PARAM_INT);
        $childrenStatement->execute();
        $children = $childrenStatement->fetchAll();
        $childrenTruncated = count($children) > $maxChildren;
        $children = array_slice($children, 0, $maxChildren);

        $allowedConfidence = array_keys(array_filter(
            $confidenceRank,
            static fn(int $rank): bool => $rank >= $confidenceRank[$minConfidence],
        ));
        $relationships = function (string $direction) use ($projectId, $nodeId, $allowedConfidence, $maxRelationships): array {
            $nodeColumn = $direction === 'outgoing' ? 'e.source_id' : 'e.target_id';
            $otherColumn = $direction === 'outgoing' ? 'e.target_id' : 'e.source_id';
            $placeholders = implode(',', array_fill(0, count($allowedConfidence), '?'));
            $statement = $this->pdo->prepare(
                'SELECT e.id, e.kind, e.confidence, e.origin, e.attributes_json, e.start_line, e.end_line, ' .
                'other.id AS component_id, other.kind AS component_kind, other.canonical_name, other.display_name, ' .
                'f.relative_path FROM edges e JOIN nodes other ON other.id = ' . $otherColumn . ' ' .
                'LEFT JOIN files f ON f.id = e.file_id WHERE e.project_id = ? AND ' . $nodeColumn . ' = ? ' .
                'AND e.confidence IN (' . $placeholders . ') ORDER BY e.kind, other.canonical_name, e.id LIMIT ?',
            );
            $statement->execute([$projectId, $nodeId, ...$allowedConfidence, $maxRelationships + 1]);
            $rows = $statement->fetchAll();
            $truncated = count($rows) > $maxRelationships;
            $rows = array_slice($rows, 0, $maxRelationships);
            return [array_map(static fn(array $row): array => [
                'id' => $row['id'], 'kind' => $row['kind'], 'confidence' => $row['confidence'], 'origin' => $row['origin'],
                'attributes' => self::decode($row['attributes_json']),
                'component' => ['id' => $row['component_id'], 'kind' => $row['component_kind'], 'canonical_name' => $row['canonical_name'], 'display_name' => $row['display_name']],
                'evidence' => $row['relative_path'] === null ? null : ['path' => $row['relative_path'], 'start_line' => $row['start_line'], 'end_line' => $row['end_line']],
            ], $rows), $truncated];
        };
        [$outgoing, $outgoingTruncated] = $relationships('outgoing');
        [$incoming, $incomingTruncated] = $relationships('incoming');
        $evidence = $node['relative_path'] === null ? [] : [[
            'component_id' => $nodeId, 'path' => $node['relative_path'],
            'start_line' => $node['start_line'], 'end_line' => $node['end_line'],
        ]];
        $truncationReasons = [];
        if ($childrenTruncated) {
            $truncationReasons[] = 'child_limit';
        }
        if ($outgoingTruncated) {
            $truncationReasons[] = 'outgoing_relationship_limit';
        }
        if ($incomingTruncated) {
            $truncationReasons[] = 'incoming_relationship_limit';
        }

        $declaredIn = $this->declaredApart([$nodeId])[$nodeId] ?? null;
        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('Inspected %s.', $node['canonical_name']),
            ['query' => $component, 'ambiguous' => false, 'component' => [
                'id' => $nodeId, 'kind' => $node['kind'], 'canonical_name' => $node['canonical_name'],
                'display_name' => $node['display_name'], 'confidence' => $node['confidence'], 'origin' => $node['origin'],
                'attributes' => self::decode($node['attributes_json']), 'roles' => $this->roles([$nodeId])[$nodeId] ?? [],
                'boundaries' => $this->boundaryNames([$nodeId])[$nodeId] ?? [],
                'annotations' => $this->annotationsFor($projectId, (string) $node['canonical_name']),
                'parent' => $node['parent_component_id'] === null ? null : ['id' => $node['parent_component_id'], 'kind' => $node['parent_kind'], 'canonical_name' => $node['parent_canonical_name']],
                'children' => $children, 'outgoing' => $outgoing, 'incoming' => $incoming,
            ] + ($declaredIn === null ? [] : ['declared_in' => $declaredIn]), 'limits' => ['max_relationships_per_direction' => $maxRelationships, 'max_children' => $maxChildren, 'min_confidence' => $minConfidence, 'truncation_reasons' => $truncationReasons]],
            $evidence,
            [],
            $truncationReasons !== [],
        );
    }

    /** Whether a usage's source is test code: 1 or 0, bound to the test role as its one parameter. */
    private const IN_TEST_SQL = 'EXISTS (SELECT 1 FROM classifications c WHERE c.project_id = e.project_id AND c.node_id = s.id AND c.role = ?)';

    /**
     * Every inbound usage occurrence of one symbol, one row per call/reference
     * site. Edges are occurrence-level facts (their stable id includes the
     * evidence location), so this is a direct listing, not an aggregation.
     *
     * @param list<string> $edgeKinds
     */
    public function listUsages(string $projectId, string $symbol, array $edgeKinds = [], string $minConfidence = 'possible', int $limit = 100): ResultEnvelope
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('limit must be between 1 and 500.');
        }
        $confidenceRank = self::CONFIDENCE_RANK;
        if (!isset($confidenceRank[$minConfidence])) {
            throw new InvalidArgumentException('min_confidence must be possible, probable, or certain.');
        }
        $edgeKinds = self::selectedEdgeKinds($edgeKinds, self::IMPACT_EDGE_KINDS, 'dependency');
        $project = $this->project($projectId);
        $matches = $this->resolve($projectId, $symbol);
        if (count($matches) !== 1) {
            return new ResultEnvelope(
                $projectId,
                $project['active_scan_id'],
                $matches === [] ? sprintf('No component matched "%s".', $symbol) : sprintf('Component "%s" is ambiguous.', $symbol),
                ['query' => $symbol, 'ambiguous' => count($matches) > 1, 'candidates' => $matches],
            );
        }
        $target = $matches[0];
        $allowedConfidence = array_keys(array_filter(
            $confidenceRank,
            static fn(int $rank): bool => $rank >= $confidenceRank[$minConfidence],
        ));
        $kindPlaceholders = implode(',', array_fill(0, count($edgeKinds), '?'));
        $confidencePlaceholders = implode(',', array_fill(0, count($allowedConfidence), '?'));
        $statement = $this->pdo->prepare(
            'SELECT e.id AS edge_id, e.kind, e.confidence, e.origin, e.attributes_json, e.start_line, e.end_line, f.relative_path, ' .
            's.id AS source_id, s.kind AS source_kind, s.canonical_name AS source_canonical, s.display_name AS source_display, ' .
            self::IN_TEST_SQL . ' AS in_test ' .
            'FROM edges e JOIN nodes s ON s.id = e.source_id LEFT JOIN files f ON f.id = e.file_id ' .
            'WHERE e.project_id = ? AND e.target_id = ? AND e.kind IN (' . $kindPlaceholders . ') ' .
            'AND e.confidence IN (' . $confidencePlaceholders . ') ' .
            'ORDER BY f.relative_path, e.start_line, e.id LIMIT ?',
        );
        $statement->execute([ReportableComponent::TEST_ROLE, $projectId, $target['id'], ...$edgeKinds, ...$allowedConfidence, $limit + 1]);
        $rows = $statement->fetchAll();
        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        // Fetching limit + 1 rows proves there are more but not how many, and
        // "Found 5 usage sites" for a symbol used 98 times understates the very
        // thing the caller asked for. Count only when the page did not already
        // hold every match.
        $totalMatched = count($rows);
        $outsideTests = count(array_filter($rows, static fn(array $row): bool => (int) $row['in_test'] === 0));
        if ($truncated) {
            $countStatement = $this->pdo->prepare(
                'SELECT COUNT(*), COALESCE(SUM(CASE WHEN ' . self::IN_TEST_SQL . ' THEN 0 ELSE 1 END), 0) ' .
                'FROM edges e JOIN nodes s ON s.id = e.source_id WHERE e.project_id = ? AND e.target_id = ? ' .
                'AND e.kind IN (' . $kindPlaceholders . ') AND e.confidence IN (' . $confidencePlaceholders . ')',
            );
            $countStatement->execute([ReportableComponent::TEST_ROLE, $projectId, $target['id'], ...$edgeKinds, ...$allowedConfidence]);
            [$totalMatched, $outsideTests] = array_map('intval', $countStatement->fetch(PDO::FETCH_NUM));
        }
        $usages = [];
        $evidence = [];
        foreach ($rows as $row) {
            $usages[] = [
                'edge_id' => $row['edge_id'], 'kind' => $row['kind'], 'confidence' => $row['confidence'], 'origin' => $row['origin'],
                'attributes' => self::decode($row['attributes_json']),
                'source' => ['id' => $row['source_id'], 'kind' => $row['source_kind'], 'canonical_name' => $row['source_canonical'], 'display_name' => $row['source_display']],
                'path' => $row['relative_path'], 'start_line' => $row['start_line'], 'end_line' => $row['end_line'],
                'test' => (int) $row['in_test'] === 1,
            ];
            if ($row['relative_path'] !== null && count($evidence) < 100) {
                $evidence[] = ['edge_id' => $row['edge_id'], 'path' => $row['relative_path'], 'start_line' => $row['start_line'], 'end_line' => $row['end_line']];
            }
        }

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            // The summary says what the list covers, so a reader can take it
            // as the answer: agents that were not told grepped for the symbol
            // afterwards to find out, in most sessions of the agent eval.
            sprintf(
                'Found %d usage site%s of %s, %d outside tests%s. Every static reference the graph resolved is listed; '
                . 'calls through strings, reflection or dynamic dispatch are not.',
                $totalMatched,
                $totalMatched === 1 ? '' : 's',
                $target['canonical_name'],
                $outsideTests,
                $truncated ? sprintf('; showing %d', count($usages)) : '',
            ),
            [
                'target' => ['id' => $target['id'], 'kind' => $target['kind'], 'canonical_name' => $target['canonical_name'], 'display_name' => $target['display_name']],
                'usages' => $usages,
                'bounds' => [
                    'limit' => $limit, 'total_matched' => $totalMatched, 'outside_tests' => $outsideTests, 'total_shown' => count($usages),
                    'edge_kinds' => $edgeKinds, 'truncation_reasons' => $truncated ? ['result_limit'] : [],
                ],
            ],
            $evidence,
            ['Usage sites are static occurrences; dynamic dispatch, reflection, and string-based references are not resolved.'],
            $truncated,
        );
    }

    /**
     * Search components by name, kind, role, boundary, or the text of a note on them.
     *
     * A note holds what the graph cannot: `method_of_return` is a reference
     * kind no symbol is named after, and only a note on its resolver says so.
     * A component found only through a note ranks after every one whose own
     * name, attributes or roles match.
     *
     * @param list<string> $kinds @param list<string> $roles @param list<string> $boundaryIds @param list<string> $confidences
     */
    public function searchArchitecture(string $projectId, string $query, array $kinds = [], array $roles = [], array $boundaryIds = [], array $confidences = [], int $limit = 20, int $offset = 0): ResultEnvelope
    {
        $project = $this->project($projectId);
        if (trim($query) === '') {
            throw new InvalidArgumentException('query must not be empty.');
        }
        self::assertLimit($limit);
        if ($offset < 0 || $offset > 100_000) {
            throw new InvalidArgumentException('offset must be between 0 and 100000.');
        }
        foreach ([$kinds, $roles, $boundaryIds, $confidences] as $filter) {
            if (!array_is_list($filter) || count($filter) > 20) {
                throw new InvalidArgumentException('Search filters must be lists of at most 20 values.');
            }
            foreach ($filter as $value) {
                if (!is_string($value) || $value === '') {
                    throw new InvalidArgumentException('Search filters must contain non-empty strings.');
                }
            }
        }
        if (array_diff($confidences, ['certain', 'probable', 'possible']) !== []) {
            throw new InvalidArgumentException('confidence filter is invalid.');
        }
        $terms = array_values(array_filter(preg_split('/\s+/', trim($query)) ?: [], static fn(string $term): bool => $term !== ''));
        if (count($terms) > 8) {
            throw new InvalidArgumentException('query must contain at most 8 words.');
        }
        $params = ['project' => $projectId, 'exact' => $query, 'prefix' => self::like($query) . '%'];
        // Every whitespace-separated term must match somewhere, so natural
        // phrasings like "review diff" find reviewDiffText: in the component
        // itself, or in a note recorded on it.
        $own = [];
        $where = '';
        foreach ($terms as $index => $term) {
            $key = 'contains' . $index;
            $params[$key] = '%' . self::like($term) . '%';
            $matches = "(n.canonical_name LIKE :$key ESCAPE '!' OR n.display_name LIKE :$key ESCAPE '!' OR n.attributes_json LIKE :$key ESCAPE '!' " .
                "OR EXISTS (SELECT 1 FROM classifications cq WHERE cq.node_id = n.id AND cq.role LIKE :$key ESCAPE '!'))";
            $own[] = $matches;
            $where .= " AND ($matches OR EXISTS (SELECT 1 FROM annotations aq WHERE aq.project_id = n.project_id " .
                "AND aq.canonical_name = n.canonical_name AND aq.value LIKE :$key ESCAPE '!'))";
        }
        $sql = "SELECT DISTINCT n.*, f.relative_path, CASE WHEN n.canonical_name = :exact THEN 0 WHEN n.display_name = :exact THEN 1 WHEN n.canonical_name LIKE :prefix ESCAPE '!' THEN 2 " .
            'WHEN ' . implode(' AND ', $own) . ' THEN 3 ELSE 4 END AS rank ' .
            'FROM nodes n LEFT JOIN files f ON f.id = n.file_id WHERE n.project_id = :project' . $where;
        $sql .= $this->filterClause('n.kind', 'kind', $kinds, $params);
        $sql .= $this->filterClause('n.confidence', 'confidence', $confidences, $params);
        if ($roles !== []) {
            $clause = $this->filterClause('cr.role', 'role', $roles, $params);
            $sql .= ' AND EXISTS (SELECT 1 FROM classifications cr WHERE cr.node_id = n.id' . $clause . ')';
        }
        if ($boundaryIds !== []) {
            $clause = $this->filterClause('bm.boundary_id', 'boundary', $boundaryIds, $params);
            $sql .= ' AND EXISTS (SELECT 1 FROM boundary_memberships bm WHERE bm.node_id = n.id' . $clause . ')';
        }
        $sql .= ' ORDER BY rank, n.canonical_name, n.id LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $rolesByNode = $this->roles(array_column($rows, 'id'));
        $boundariesByNode = $this->boundaryNames(array_column($rows, 'id'));
        $apart = $this->declaredApart(array_column($rows, 'id'));
        $results = [];
        $evidence = [];
        foreach ($rows as $row) {
            $results[] = [
                'id' => $row['id'], 'kind' => $row['kind'], 'canonical_name' => $row['canonical_name'],
                'display_name' => $row['display_name'], 'origin' => $row['origin'], 'confidence' => $row['confidence'],
                'roles' => $rolesByNode[$row['id']] ?? [], 'boundaries' => $boundariesByNode[$row['id']] ?? [],
                'attributes' => self::decode($row['attributes_json']),
            ] + (isset($apart[$row['id']]) ? ['declared_in' => $apart[$row['id']]] : []);
            if ($row['relative_path'] !== null) {
                $evidence[] = [
                    'component_id' => $row['id'], 'path' => $row['relative_path'],
                    'start_line' => $row['start_line'], 'end_line' => $row['end_line'],
                ];
            }
        }
        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('Found %d architecture search result%s.', count($results), count($results) === 1 ? '' : 's'),
            ['query' => $query, 'results' => $results, 'pagination' => ['offset' => $offset, 'next_offset' => $truncated ? $offset + $limit : null, 'truncation_reason' => $truncated ? 'result_limit' : null]],
            $evidence,
            [],
            $truncated,
        );
    }

    /**
     * The file each member is declared in, for the members a type in another
     * file contains: a Rust method in an `impl` block of another module is
     * named after its type, so its name reads as if it lived beside it.
     *
     * @param list<string> $ids
     * @return array<string, string> node id => project-relative path
     */
    private function declaredApart(array $ids): array
    {
        $apart = [];
        foreach (ChunkedInQuery::rows(
            $this->pdo,
            'SELECT n.id, f.relative_path FROM nodes n JOIN files f ON f.id = n.file_id '
            . "JOIN edges e ON e.target_id = n.id AND e.kind = 'contains' JOIN nodes c ON c.id = e.source_id "
            . "WHERE c.kind IN ('class', 'interface', 'trait', 'enum') AND c.file_id <> n.file_id AND n.id IN (%s)",
            array_values(array_map('strval', $ids)),
            mode: PDO::FETCH_NUM,
        ) as [$id, $path]) {
            $apart[(string) $id] = (string) $path;
        }

        return $apart;
    }

    /**
     * Durable annotations recorded against a component.
     *
     * @return list<array<string, mixed>>
     */
    private function annotationsFor(string $projectId, string $canonicalName): array
    {
        $statement = $this->pdo->prepare(
            'SELECT kind, value, author, created_at, updated_at FROM annotations ' .
            "WHERE project_id = :project AND canonical_name = :name ORDER BY CASE author WHEN 'user' THEN 0 ELSE 1 END, kind",
        );
        $statement->execute(['project' => $projectId, 'name' => $canonicalName]);
        return $statement->fetchAll();
    }

    /**
     * Build the SQL filter for the optional search facets.
     *
     * @param list<string> $values @param array<string, string> $params
     */
    private function filterClause(string $column, string $prefix, array $values, array &$params): string
    {
        if ($values === []) {
            return '';
        }
        $placeholders = [];
        foreach ($values as $index => $value) {
            $key = $prefix . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $value;
        }
        return sprintf(' AND %s IN (%s)', $column, implode(',', $placeholders));
    }
}
