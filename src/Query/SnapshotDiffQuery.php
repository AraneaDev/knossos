<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use Knossos\Result\ResultEnvelope;
use Knossos\Store\EnrichedFacts;
use Knossos\Store\SnapshotBounds;
use PDO;

/**
 * Architectural changes between two snapshots: `snapshot_diff`.
 *
 * Compares two graphs one table at a time and one row at a time, keeping
 * a hash and a few fields of each row, so a diff never holds two whole
 * graphs, nor two whole tables, at once. Release
 * notes in `architecture_trends` are built from the same diff, so the two
 * cannot count a change differently.
 */
final readonly class SnapshotDiffQuery extends AbstractArchitectureQueryService
{
    private SnapshotResolver $snapshots;

    public function __construct(PDO $pdo, ?Closure $clock = null)
    {
        parent::__construct($pdo, $clock);
        $this->snapshots = new SnapshotResolver($pdo);
    }

    /** Architectural changes between two scans: added, removed, changed, and moved facts. */
    public function snapshotDiff(string $projectId, string $fromSnapshot, string $toSnapshot = 'active', int $maxChanges = 25): ResultEnvelope
    {
        $project = $this->project($projectId);
        if ($maxChanges < 1 || $maxChanges > 1000) {
            throw new InvalidArgumentException('max_changes must be between 1 and 1000.');
        }
        $from = $this->snapshotSource($projectId, $fromSnapshot, $project['active_scan_id'] ?? '');
        $to = $this->snapshotSource($projectId, $toSnapshot, $project['active_scan_id'] ?? '');
        if ($from['metadata']['scan_id'] === $to['metadata']['scan_id']) {
            throw new InvalidArgumentException('from_snapshot and to_snapshot must identify different scans.');
        }
        // Files are the only cross-section fact (path resolution), so load them once.
        $fromFiles = self::all($from['each'], 'files');
        $toFiles = self::all($to['each'], 'files');

        // Pass one compares slim rows: a hash of each row and the few fields
        // the counts, moves, confidence changes and rename candidates read.
        // It counts every change but keeps only the first $maxChanges of each
        // kind, all a result can report, and tallies confidence as it goes:
        // the whole rows of both graphs, about 6 KB a row, ran the core out of
        // memory on a large project. Pass two reads whole rows back only for
        // the changes this result reports.
        $confidence = ['raised' => 0, 'lowered' => 0];
        $rawDiffs = [];
        foreach (self::TABLE_MAP as $section => [$table, $key]) {
            $rawDiffs[$section] = $table === 'edges'
                ? self::diffEdgeRows(self::slimEdges($from['each']), self::slimEdges($to['each']), $maxChanges, $confidence)
                : self::diffSnapshotRows(self::slimRows($from['each'], $table, $key), self::slimRows($to['each'], $table, $key), $maxChanges, $confidence, $table);
        }

        $remaining = $maxChanges;
        $total = 0;
        $truncated = false;
        // What this result reports, in report order, before the whole rows are read back.
        $picked = [];
        $take = static function (string $section, string $table, string $kind) use (&$rawDiffs, &$remaining, &$total, &$truncated, &$picked): void {
            $count = $rawDiffs[$section]['counts'][$kind];
            $total += $count;
            $taken = min($remaining, $count);
            $picked[$section][$kind] = [$table, array_slice($rawDiffs[$section][$kind], 0, $taken)];
            $remaining -= $taken;
            $truncated = $truncated || $taken < $count;
        };
        foreach (self::TABLE_MAP as $section => [$table]) {
            foreach (['added', 'removed', 'changed'] as $kind) {
                $take($section, $table, $kind);
            }
            // The same relationship written somewhere else, after the others.
            if ($table === 'edges') {
                $take($section, $table, 'moved');
            }
        }
        // A component in another file is a move, reported under `moved` after
        // every section with every field that changed, and not under `changed`.
        $take('components', 'nodes', 'moved');

        $whole = $this->wholeRows($from['each'], $to['each'], $picked);
        $records = fn(string $section, string $kind): array => array_map(
            fn(array $change): array => $this->snapshotChangeRecord($picked[$section][$kind][0], $kind, self::whole($picked[$section][$kind][0], $change, $whole), $fromFiles, $toFiles),
            $picked[$section][$kind][1],
        );
        $sections = [];
        foreach (self::TABLE_MAP as $section => [$table]) {
            $counts = $rawDiffs[$section]['counts'];
            $output = ['added' => $records($section, 'added'), 'removed' => $records($section, 'removed'), 'changed' => $records($section, 'changed')];
            $output['counts'] = ['added' => $counts['added'], 'removed' => $counts['removed'], 'changed' => $counts['changed']];
            if ($table === 'edges') {
                $output['moved'] = $records($section, 'moved');
                $output['counts']['moved'] = $counts['moved'];
            }
            $sections[$section] = $output;
        }
        $sections['components']['moved'] = $records('components', 'moved');
        $sections['components']['counts']['moved'] = $rawDiffs['components']['counts']['moved'];

        // Candidates are offered within what is left, but are not changes, so they leave the reported count alone.
        $renameCandidates = $this->renameCandidates($rawDiffs['components']['removed_all'], $rawDiffs['components']['added_all']);
        $renameCount = count($renameCandidates);
        $taken = min($remaining, $renameCount);
        $sections['components']['rename_candidates'] = array_slice($renameCandidates, 0, $taken);
        $sections['components']['counts']['rename_candidates'] = $renameCount;
        $truncated = $truncated || $taken < $renameCount;

        return new ResultEnvelope(
            $projectId,
            $to['metadata']['scan_id'],
            sprintf('Compared snapshots with %d added, removed, changed, or moved fact%s.', $total, $total === 1 ? '' : 's'),
            ['from' => $from['metadata'], 'to' => $to['metadata'], 'changes' => $sections, 'confidence_changes' => $confidence,
                'bounds' => ['max_changes' => $maxChanges, 'reported_changes' => $maxChanges - $remaining, 'total_changes' => $total]],
            warnings: ['Rename candidates are conservative exact kind/display-name heuristics, not proven identity.'],
            truncated: $truncated,
        );
    }

    /**
     * The sections a diff reports, each with its table and the column that identifies a row (none for a membership, which is its pair).
     */
    private const TABLE_MAP = [
        'components' => ['nodes', 'id'],
        'boundaries' => ['boundaries', 'id'],
        'relationships' => ['edges', 'id'],
        'roles' => ['classifications', 'id'],
        'boundary_memberships' => ['boundary_memberships', null],
        'diagnostics' => ['diagnostics', 'id'],
    ];

    /** The fields a slim row keeps besides its hash, per table: what the counts, moves, confidence changes and rename candidates read. */
    private const SLIM_FIELDS = [
        'nodes' => ['id', 'kind', 'display_name', 'file_id', 'confidence'],
        'classifications' => ['confidence'],
    ];

    /** Where an edge is written: a pair that differs only here is `moved`. */
    private const EDGE_WHERE = ['id' => true, 'file_id' => true, 'start_line' => true, 'end_line' => true];

    /**
     * A snapshot as a source of rows, one table at a time and one row at a
     * time, so neither graph is ever held whole.
     *
     * @return array{metadata: array<string, mixed>, each: Closure(string, Closure(array<string, mixed>): void): void}
     */
    private function snapshotSource(string $projectId, string $identifier, string $activeScanId): array
    {
        $resolved = $this->snapshots->resolve($projectId, $identifier, $activeScanId);
        $scanId = $resolved['scan_id'];
        if (!$resolved['is_active']) {
            $reader = new SnapshotGraphReader($this->pdo);
            $each = static function (string $table, Closure $sink) use ($reader, $scanId): void {
                $reader->eachArchivedRow($scanId, $table, $sink);
            };
        } else {
            $each = function (string $table, Closure $sink) use ($projectId, $scanId): void {
                $this->eachActiveRow($projectId, $scanId, $table, $sink);
            };
        }
        return ['metadata' => $resolved['metadata'], 'each' => $each];
    }

    /**
     * Each row of one table of the active graph, in the shape snapshot comparison expects.
     *
     * @param Closure(array<string, mixed>): void $sink
     */
    private function eachActiveRow(string $projectId, string $scanId, string $table, Closure $sink): void
    {
        // Each column with `+`: ordered by an index, SQLite would walk every project's rows rather than find this one's and sort them.
        $order = $table === 'boundary_memberships' ? '+boundary_id, +node_id' : '+id';
        // Native rows only, as the archive stores them: an enricher's output is never drift.
        $native = in_array($table, EnrichedFacts::TABLES, true) ? ' AND ' . EnrichedFacts::excludeSql($table) : '';
        $statement = $this->pdo->prepare(sprintf('SELECT * FROM %s WHERE project_id = :project%s ORDER BY %s LIMIT %d', $table, $native, $order, SnapshotBounds::MAX_ROWS_PER_TABLE + 1));
        $statement->execute(['project' => $projectId]);
        $rows = 0;
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (++$rows > SnapshotBounds::MAX_ROWS_PER_TABLE) {
                $statement->closeCursor();
                throw new InvalidArgumentException(sprintf('Active snapshot %s exceeds the %d-row %s diff limit.', $scanId, SnapshotBounds::MAX_ROWS_PER_TABLE, $table));
            }
            $sink($row);
        }
    }

    /**
     * Every row of a small table, such as files.
     *
     * @param Closure(string, Closure(array<string, mixed>): void): void $each
     * @return list<array<string, mixed>>
     */
    private static function all(Closure $each, string $table): array
    {
        $rows = [];
        $each($table, static function (array $row) use (&$rows): void {
            $rows[] = $row;
        });

        return $rows;
    }

    /**
     * A row as comparison sees it: without the scan it was written in, keys in order.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalized(array $row): array
    {
        unset($row['last_scan_id'], $row['scan_id']);
        ksort($row, SORT_STRING);

        return $row;
    }

    /**
     * What identifies a row of a table other than edges.
     *
     * @param array<string, mixed> $row
     */
    private static function rowKey(?string $key, array $row): string
    {
        return $key === null ? (string) $row['boundary_id'] . "\0" . (string) $row['node_id'] : (string) $row[$key];
    }

    /**
     * One table's rows, slim: a hash of the whole row and the fields {@see self::SLIM_FIELDS} names, by key, in key order.
     *
     * @param Closure(string, Closure(array<string, mixed>): void): void $each
     * @return array<string, array<string, mixed>>
     */
    private static function slimRows(Closure $each, string $table, ?string $key): array
    {
        $fields = array_fill_keys(self::SLIM_FIELDS[$table] ?? [], true);
        $indexed = [];
        $each($table, static function (array $row) use (&$indexed, $fields, $key): void {
            $row = self::normalized($row);
            $indexed[self::rowKey($key, $row)] = ['hash' => md5(serialize($row), true)] + array_intersect_key($row, $fields);
        });
        ksort($indexed, SORT_STRING);

        return $indexed;
    }

    /**
     * Compare two slim fact sets into added, removed, and changed records.
     *
     * Every change is counted, the first `$limit` of each kind kept. A
     * component that changed file is `moved` rather than `changed`, and every
     * added and removed component is kept whole besides, for the rename
     * candidates. Confidence changes of components and roles are tallied into
     * `$confidence`.
     *
     * @param array<string, array<string, mixed>> $before
     * @param array<string, array<string, mixed>> $after
     * @param array{raised: int, lowered: int} $confidence
     * @return array<string, mixed>
     */
    private static function diffSnapshotRows(array $before, array $after, int $limit, array &$confidence, string $table): array
    {
        $diff = ['added' => [], 'removed' => [], 'changed' => [], 'moved' => [], 'added_all' => [], 'removed_all' => [],
            'counts' => ['added' => 0, 'removed' => 0, 'changed' => 0, 'moved' => 0]];
        $nodes = $table === 'nodes';
        foreach (array_diff_key($after, $before) as $id => $row) {
            $entry = ['id' => (string) $id, 'after' => $row];
            self::keep($diff, 'added', $entry, $limit);
            if ($nodes) {
                $diff['added_all'][] = $entry;
            }
        }
        foreach (array_diff_key($before, $after) as $id => $row) {
            $entry = ['id' => (string) $id, 'before' => $row];
            self::keep($diff, 'removed', $entry, $limit);
            if ($nodes) {
                $diff['removed_all'][] = $entry;
            }
        }
        foreach (array_intersect_key($before, $after) as $id => $row) {
            if ($row['hash'] === $after[$id]['hash']) {
                continue;
            }
            $moved = $nodes && ($row['file_id'] ?? null) !== ($after[$id]['file_id'] ?? null);
            self::keep($diff, $moved ? 'moved' : 'changed', ['id' => (string) $id, 'before' => $row, 'after' => $after[$id]], $limit);
            if ($nodes || $table === 'classifications') {
                self::tallyConfidence($confidence, $row, $after[$id]);
            }
        }
        return $diff;
    }

    /**
     * Count one change of `$kind`, and keep it while fewer than `$limit` are kept.
     *
     * @param array<string, mixed> $diff
     * @param array<string, mixed> $entry
     */
    private static function keep(array &$diff, string $kind, array $entry, int $limit): void
    {
        ++$diff['counts'][$kind];
        if (count($diff[$kind]) < $limit) {
            $diff[$kind][] = $entry;
        }
    }

    /**
     * One confidence that rose or fell between two versions of a fact.
     *
     * @param array{raised: int, lowered: int} $confidence
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private static function tallyConfidence(array &$confidence, array $before, array $after): void
    {
        $from = self::CONFIDENCE_RANK[$before['confidence'] ?? ''] ?? null;
        $to = self::CONFIDENCE_RANK[$after['confidence'] ?? ''] ?? null;
        if ($from !== null && $to !== null && $from !== $to) {
            ++$confidence[$to > $from ? 'raised' : 'lowered'];
        }
    }

    /**
     * The edges, slim, in one flat array ordered by relationship (kind,
     * source, target) and then by position: each key is the relationship,
     * a separator no id holds, the file, the zero-padded line and the id, so
     * one sort puts every relationship's edges together in position order.
     * A value is a list: id, file, line, confidence, a hash of the whole row
     * and one of everything but where it is written. Nested per relationship,
     * as it once was, an edge cost about a kilobyte.
     *
     * @param Closure(string, Closure(array<string, mixed>): void): void $each
     * @return array<string, list<mixed>>
     */
    private static function slimEdges(Closure $each): array
    {
        $flat = [];
        $each('edges', static function (array $row) use (&$flat): void {
            $row = self::normalized($row);
            $key = $row['kind'] . "\0" . $row['source_id'] . "\0" . $row['target_id'] . self::GROUP_END
                . ($row['file_id'] ?? '') . "\0" . sprintf('%011d', (int) ($row['start_line'] ?? 0)) . "\0" . $row['id'];
            $flat[$key] = [
                (string) $row['id'], $row['file_id'] ?? null, $row['start_line'] ?? null, $row['confidence'] ?? null,
                md5(serialize($row), true), md5(serialize(array_diff_key($row, self::EDGE_WHERE)), true),
            ];
        });
        ksort($flat, SORT_STRING);

        return $flat;
    }

    /** Ends the relationship part of a flat edge key: below every character an id, kind or path holds. */
    private const GROUP_END = "\x01";

    /**
     * One relationship's edges from a flat slim set, from `$at` on, by id in position order; `$at` moves past them.
     *
     * @param list<string> $keys
     * @param array<string, list<mixed>> $flat
     * @return array<string, array<string, mixed>>
     */
    private static function group(array $keys, array $flat, int &$at, string $relationship): array
    {
        $edges = [];
        $prefix = $relationship . self::GROUP_END;
        for ($count = count($keys); $at < $count && str_starts_with($keys[$at], $prefix); ++$at) {
            [$id, $file, $line, $confidence, $hash, $what] = $flat[$keys[$at]];
            $edges[$id] = ['id' => $id, 'file_id' => $file, 'start_line' => $line, 'confidence' => $confidence, 'hash' => $hash, 'what' => $what];
        }

        return $edges;
    }

    /**
     * Compare two edge sets by relationship rather than by id. An edge's id names
     * where it is written, so a line inserted above a call gave the call a new id
     * and it read as removed and added. Edges are paired by kind, source and
     * target: the same id first, then the rest in order of position. A pair
     * that differs only in where it is written is `moved`; one that differs in
     * anything else is `changed`; what is left over is added or removed.
     *
     * Every change is counted, the first `$limit` of each kind kept, and the
     * confidence of a changed relationship tallied into `$confidence`.
     *
     * @param array<string, list<mixed>> $before
     * @param array<string, list<mixed>> $after
     * @param array{raised: int, lowered: int} $confidence
     * @return array<string, mixed>
     */
    private static function diffEdgeRows(array $before, array $after, int $limit, array &$confidence): array
    {
        $diff = ['added' => [], 'removed' => [], 'changed' => [], 'moved' => [], 'counts' => ['added' => 0, 'removed' => 0, 'changed' => 0, 'moved' => 0]];
        $pair = static function (string $id, array $old, array $new) use (&$diff, &$confidence, $limit): void {
            $kind = $old['what'] === $new['what'] ? 'moved' : 'changed';
            self::keep($diff, $kind, ['id' => $id, 'before' => $old, 'after' => $new], $limit);
            if ($kind === 'changed') {
                self::tallyConfidence($confidence, $old, $new);
            }
        };
        $beforeKeys = array_keys($before);
        $afterKeys = array_keys($after);
        $relationshipOf = static fn(string $key): string => substr($key, 0, (int) strpos($key, self::GROUP_END));
        $b = 0;
        $a = 0;
        while ($b < count($beforeKeys) || $a < count($afterKeys)) {
            // The two sides walk their relationships in the same sorted order, the smaller first.
            $nextBefore = $b < count($beforeKeys) ? $relationshipOf($beforeKeys[$b]) : null;
            $nextAfter = $a < count($afterKeys) ? $relationshipOf($afterKeys[$a]) : null;
            $relationship = $nextBefore === null ? $nextAfter : ($nextAfter === null || strcmp($nextBefore, $nextAfter) <= 0 ? $nextBefore : $nextAfter);
            $old = $nextBefore === $relationship ? self::group($beforeKeys, $before, $b, (string) $relationship) : [];
            $new = $nextAfter === $relationship ? self::group($afterKeys, $after, $a, (string) $relationship) : [];
            foreach (array_intersect_key($old, $new) as $id => $row) {
                if ($row['hash'] !== $new[$id]['hash']) {
                    $pair((string) $id, $row, $new[$id]);
                }
            }
            $unpairedOld = array_values(array_diff_key($old, $new));
            $unpairedNew = array_values(array_diff_key($new, $old));
            $paired = min(count($unpairedOld), count($unpairedNew));
            for ($i = 0; $i < $paired; ++$i) {
                $pair((string) $unpairedNew[$i]['id'], $unpairedOld[$i], $unpairedNew[$i]);
            }
            foreach (array_slice($unpairedNew, $paired) as $row) {
                self::keep($diff, 'added', ['id' => (string) $row['id'], 'after' => $row], $limit);
            }
            foreach (array_slice($unpairedOld, $paired) as $row) {
                self::keep($diff, 'removed', ['id' => (string) $row['id'], 'before' => $row], $limit);
            }
        }

        return $diff;
    }

    /**
     * The whole rows behind the changes a result reports, read back from each
     * graph, by table, side and key: an edge by its own id on each side, any
     * other row by the key it was compared under.
     *
     * @param Closure(string, Closure(array<string, mixed>): void): void $fromEach
     * @param Closure(string, Closure(array<string, mixed>): void): void $toEach
     * @param array<string, array<string, array{string, list<array<string, mixed>>}>> $picked
     * @return array<string, array{before: array<string, array<string, mixed>>, after: array<string, array<string, mixed>>}>
     */
    private function wholeRows(Closure $fromEach, Closure $toEach, array $picked): array
    {
        $wanted = [];
        foreach ($picked as $kinds) {
            foreach ($kinds as [$table, $changes]) {
                foreach ($changes as $change) {
                    foreach (['before', 'after'] as $side) {
                        if (isset($change[$side])) {
                            $wanted[$table][$side][self::wholeKey($table, $change, $side)] = true;
                        }
                    }
                }
            }
        }
        $keys = array_column(self::TABLE_MAP, 1, 0);
        $whole = [];
        foreach ($wanted as $table => $sides) {
            foreach (['before' => $fromEach, 'after' => $toEach] as $side => $each) {
                $want = $sides[$side] ?? [];
                $kept = [];
                if ($want !== []) {
                    $each($table, static function (array $row) use (&$kept, $want, $table, $keys): void {
                        $row = self::normalized($row);
                        $key = $table === 'edges' ? (string) $row['id'] : self::rowKey($keys[$table], $row);
                        if (isset($want[$key])) {
                            $kept[$key] = $row;
                        }
                    });
                }
                $whole[$table][$side] = $kept;
            }
        }

        return $whole;
    }

    /**
     * Where a change's row on one side is found among the whole rows.
     *
     * @param array<string, mixed> $change
     */
    private static function wholeKey(string $table, array $change, string $side): string
    {
        return $table === 'edges' ? (string) $change[$side]['id'] : (string) $change['id'];
    }

    /**
     * A reported change with its slim rows replaced by the whole ones.
     *
     * @param array<string, mixed> $change
     * @param array<string, array{before: array<string, array<string, mixed>>, after: array<string, array<string, mixed>>}> $whole
     * @return array<string, mixed>
     */
    private static function whole(string $table, array $change, array $whole): array
    {
        foreach (['before', 'after'] as $side) {
            if (isset($change[$side])) {
                $change[$side] = $whole[$table][$side][self::wholeKey($table, $change, $side)];
            }
        }

        return $change;
    }

    /**
     * One change entry, carrying enough identity to be actionable rather than just a count.
     *
     * @param list<array<string, mixed>> $beforeFiles @param list<array<string, mixed>> $afterFiles @return array<string, mixed>
     */
    private function snapshotChangeRecord(string $table, string $kind, array $change, array $beforeFiles, array $afterFiles): array
    {
        $summarize = function (?array $row, array $files) use ($table): ?array {
            if ($row === null) {
                return null;
            }
            $fields = match ($table) {
                'nodes' => ['id', 'kind', 'canonical_name', 'display_name', 'file_id', 'start_line', 'end_line', 'origin', 'confidence'],
                'edges' => ['id', 'kind', 'source_id', 'target_id', 'file_id', 'start_line', 'end_line', 'origin', 'confidence'],
                'classifications' => ['id', 'node_id', 'role', 'origin', 'confidence', 'rule_id'],
                'boundaries' => ['id', 'name', 'source', 'matcher_json'],
                'boundary_memberships' => ['boundary_id', 'node_id'],
                'diagnostics' => ['id', 'severity', 'code', 'message', 'file_id', 'start_line', 'end_line'],
                default => array_keys($row),
            };
            $summary = array_intersect_key($row, array_fill_keys($fields, true));
            if (isset($summary['file_id'])) {
                $paths = [];
                foreach ($files as $file) {
                    $paths[$file['id']] = $file['relative_path'];
                }
                $summary['path'] = $paths[$summary['file_id']] ?? null;
                unset($summary['file_id']);
            }
            return $summary;
        };
        $before = $summarize($change['before'] ?? null, $beforeFiles);
        $after = $summarize($change['after'] ?? null, $afterFiles);
        $record = ['id' => str_replace("\0", ':', (string) $change['id'])];
        if ($before !== null) {
            $record['before'] = $before;
        }
        if ($after !== null) {
            $record['after'] = $after;
        }
        if ($kind === 'changed' || $kind === 'moved') {
            $record['changed_fields'] = array_values(array_unique(array_merge(
                array_keys(array_diff_assoc($before ?? [], $after ?? [])),
                array_keys(array_diff_assoc($after ?? [], $before ?? [])),
            )));
            sort($record['changed_fields'], SORT_STRING);
        }
        return $record;
    }

    /**
     * Pairs that look like a rename rather than a delete plus an add, so a move is not double-counted.
     *
     * @param list<array<string, mixed>> $removed @param list<array<string, mixed>> $added @return list<array<string, mixed>>
     */
    private function renameCandidates(array $removed, array $added): array
    {
        $signature = static fn(array $row): string => $row['kind'] . "\0" . $row['display_name'];
        $rows = [];
        $from = [];
        foreach ($removed as $change) {
            $rows[(string) $change['before']['id']] = $change['before'];
            $from[(string) $change['before']['id']] = $signature($change['before']);
        }
        $to = [];
        foreach ($added as $change) {
            $to[(string) $change['after']['id']] = $signature($change['after']);
        }
        $candidates = [];
        // Unique among the added only: these are "possible" renames for a reader to judge ({@see RenameMatching}).
        foreach (RenameMatching::uniquePairs($from, $to, false) as $fromId => $toId) {
            $before = $rows[$fromId];
            $candidates[] = ['from_id' => $before['id'], 'to_id' => $toId, 'kind' => $before['kind'],
                'display_name' => $before['display_name'], 'heuristic' => 'exact_kind_and_display_name', 'confidence' => 'possible'];
        }
        usort($candidates, static fn(array $left, array $right): int => [$left['from_id'], $left['to_id']] <=> [$right['from_id'], $right['to_id']]);
        return $candidates;
    }
}
