<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Scan\ProjectPathResolver;
use PDO;

/**
 * The architecture pane's finder: what search_architecture answers the
 * agent for what was typed, then every other component and file whose name
 * holds the letters in order, the closest first.
 *
 * The core's results come first and in its order: every term matching a
 * component's name, attributes, roles or a note on it, else the components
 * matching most terms; a query naming a file no component matches finds
 * what that file declares. Notes on names no component holds are listed
 * apart, under `notes`, as the core lists them.
 *
 * The core has no match by letters in order, so the finder keeps its own as
 * a fallback after the core's results, then the files: the typed characters
 * must appear in the name in order, any case, with anything between them
 * (`dsvc` finds `DashboardService`). Candidates are read with one bounded
 * `LIKE` per kind of thing, each at most {@see self::CANDIDATES} rows, the
 * strongest kinds of match first (see {@see self::strength()}) so the bound
 * drops scattered letters before a name that starts with what was typed,
 * then ranked here: the whole name, then the name's start, then a run inside
 * the name, then a run anywhere in the full name or path, then the scattered
 * letters, the fewest gaps first; among equals a type before a function or
 * member and a component before a file, then shorter names before longer,
 * then by name, so the order is stable. Every kind of component is searched,
 * routes and the kinds an enricher contributes among them, except external
 * symbols (a vendor class, a built-in), which have no place to open, and the
 * members there are too many of to look for by name ({@see self::UNSEARCHED}).
 *
 * Read-only, never scans; `truncated` says a list may have missed a match
 * because a read stopped at its bound.
 */
final readonly class GraphSearchService
{
    /** Results listed: as many as the finder shows. */
    public const LIMIT = 20;

    /** Results read from search_architecture, its own largest page. */
    private const CORE_READ = 100;

    /** Rows each candidate read takes at most. */
    private const CANDIDATES = 400;

    /** The longest query searched; the rest is ignored. */
    public const QUERY_MAX = 80;

    /**
     * The case pair Unicode leaves out: `ß` upper-cases to `SS`, so nothing
     * maps it to the capital `ẞ` (U+1E9E). The query is lower-cased first, which
     * turns a typed `ẞ` into `ß`, so this one direction covers both.
     */
    private const SHARP_S = ['ß' => ['ẞ']];

    /** The kinds a tie is broken by, in order; any other kind comes after them. */
    private const KINDS = ['class', 'interface', 'trait', 'enum', 'module', 'function', 'method', 'route', 'endpoint', 'command'];

    /** Kinds there are too many of to look for by name: a class's fields and a function's locals. */
    private const UNSEARCHED = ['property', 'variable', 'object', 'parameter'];

    public function __construct(private PDO $pdo) {}

    /**
     * The components and files matching `$query` in the project that owns
     * `$path`, the closest first, or an `unscanned` envelope.
     *
     * @return array<string, mixed>
     */
    public function search(string $path, string $query): array
    {
        $absolute = realpath($path) ?: $path;
        $typed = mb_substr(trim($query), 0, self::QUERY_MAX);
        $envelope = ['path' => $absolute, 'project_id' => null, 'snapshot_id' => null, 'query' => $typed, 'results' => [], 'notes' => [], 'truncated' => false];
        $project = (new ProjectPathResolver($this->pdo))->resolve($absolute);
        if ($project === null || !is_string($project['active_scan_id']) || $project['active_scan_id'] === '') {
            return ['status' => 'unscanned'] + $envelope;
        }
        $id = (string) $project['id'];
        $base = ['status' => 'ok', 'project_id' => $id, 'snapshot_id' => $project['active_scan_id']] + $envelope;
        $needle = mb_strtolower(preg_replace('/\s+/u', '', $typed) ?? '');
        if ($needle === '') {
            return $base;
        }
        // The core takes at most eight words.
        $words = implode(' ', array_slice(preg_split('/\s+/u', $typed, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 8));
        // Read past the finder's own limit: the components it cannot open (externals first among them) can fill a page.
        $core = ArchitectureQueryService::forDatabase($this->pdo)->searchArchitecture($id, $words, limit: self::CORE_READ, includeFiles: true);
        $places = [];
        foreach ($core->evidence as $evidence) {
            $places[(string) $evidence['component_id']] = ['path' => (string) $evidence['path'], 'line' => $evidence['start_line'] === null ? null : (int) $evidence['start_line']];
        }
        $listed = [];
        $found = [];
        foreach ($core->data['results'] as $row) {
            $listed[(string) $row['id']] = true;
            // What the finder cannot open, or would drown in, as its own search leaves out.
            if (str_starts_with((string) $row['kind'], 'external_') || in_array($row['kind'], self::UNSEARCHED, true)) {
                continue;
            }
            $found[] = ['type' => 'component', 'id' => (string) $row['id'], 'name' => (string) $row['display_name'], 'canonical_name' => (string) $row['canonical_name'], 'kind' => (string) $row['kind'],
                'path' => $places[(string) $row['id']]['path'] ?? null, 'line' => $places[(string) $row['id']]['line'] ?? null];
        }
        foreach ($core->data['files'] as $file) {
            $listed['file:' . $file] = true;
        }
        [$components, $cutComponents] = $this->components($id, $needle);
        [$files, $cutFiles] = $this->files($id, $needle);
        // A candidate the pattern let through only for a non-ASCII letter it stood in for scores 0.
        $ranked = array_values(array_filter([...$components, ...$files], static fn(array $r): bool => $r['score'] > 0 && !isset($listed[$r['type'] === 'file' ? 'file:' . $r['path'] : (string) $r['id']])));
        usort($ranked, static fn(array $a, array $b): int => [$b['score'], $a['order'], mb_strlen($a['name']), $a['name']] <=> [$a['score'], $b['order'], mb_strlen($b['name']), $b['name']]);
        $coreFiles = array_map(static fn(string $file): array => ['type' => 'file', 'id' => null, 'name' => $file, 'canonical_name' => $file, 'kind' => 'file', 'path' => $file, 'line' => null], $core->data['files']);
        $fuzzyComponents = array_values(array_filter($ranked, static fn(array $r): bool => $r['type'] === 'component'));
        $fuzzyFiles = array_values(array_filter($ranked, static fn(array $r): bool => $r['type'] === 'file'));
        $results = array_slice([...$found, ...$fuzzyComponents, ...$coreFiles, ...$fuzzyFiles], 0, self::LIMIT);
        $labels = BoundaryLabels::load($this->pdo, $id);
        $nodeLabels = $labels->forNodes(array_values(array_filter(array_column($results, 'id'))));
        $fileLabels = $labels->forFiles($id, array_values(array_column(array_filter($results, static fn(array $r): bool => $r['type'] === 'file'), 'path')));

        return ['results' => array_map(static fn(array $r): array => [
            'type' => $r['type'],
            'name' => $r['name'],
            'canonical_name' => $r['canonical_name'],
            'kind' => $r['kind'],
            'path' => $r['path'],
            'line' => $r['line'],
            'boundary' => $r['type'] === 'file' ? ($fileLabels[(string) $r['path']] ?? null) : ($nodeLabels[(string) $r['id']] ?? null),
        ], $results), 'notes' => array_map(static fn(array $note): array => [
            'canonical_name' => $note['component'],
            'kind' => $note['kind'],
            'author' => $note['author'],
            'text' => $note['text'],
            'written' => $note['written'],
            'component_status' => 'missing',
        ], $core->data['notes_without_component']), 'truncated' => $core->truncated || $cutComponents || $cutFiles] + $base;
    }

    /**
     * The components whose name or full name holds the letters, ranked.
     *
     * @return array{list<array<string, mixed>>, bool}
     */
    private function components(string $projectId, string $needle): array
    {
        $unsearched = implode(',', array_fill(0, count(self::UNSEARCHED), '?'));
        $statement = $this->pdo->prepare(
            'SELECT n.id, n.kind, n.canonical_name, n.display_name, n.start_line, f.relative_path FROM nodes n LEFT JOIN files f ON f.id = n.file_id '
            . "WHERE n.project_id = ? AND n.kind NOT LIKE 'external\\_%' ESCAPE '\\' AND n.kind NOT IN ({$unsearched}) "
            . "AND ({$this->holds('n.display_name', $needle)} OR {$this->holds('n.canonical_name', $needle)}) "
            . 'ORDER BY ' . self::strength('n.display_name', 'n.canonical_name') . ', LENGTH(n.display_name), n.display_name LIMIT ' . (self::CANDIDATES + 1),
        );
        $holds = self::holdsValues($needle);
        $statement->execute([$projectId, ...self::UNSEARCHED, ...$holds, ...$holds, ...self::strengthValues($needle)]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $order = array_flip(self::KINDS);
        $found = [];
        foreach (array_slice($rows, 0, self::CANDIDATES) as $row) {
            $name = (string) $row['display_name'];
            $found[] = [
                'type' => 'component', 'id' => (string) $row['id'], 'name' => $name, 'canonical_name' => (string) $row['canonical_name'], 'kind' => (string) $row['kind'],
                'path' => $row['relative_path'] === null ? null : (string) $row['relative_path'], 'line' => $row['start_line'] === null ? null : (int) $row['start_line'],
                'score' => self::score($needle, $name, (string) $row['canonical_name']), 'order' => $order[(string) $row['kind']] ?? count(self::KINDS),
            ];
        }

        return [$found, count($rows) > self::CANDIDATES];
    }

    /**
     * The files whose path holds the letters, ranked by their own name first.
     *
     * @return array{list<array<string, mixed>>, bool}
     */
    private function files(string $projectId, string $needle): array
    {
        $statement = $this->pdo->prepare(
            "SELECT relative_path FROM files WHERE project_id = ? AND {$this->holds('relative_path', $needle)} "
            . 'ORDER BY ' . self::strength(self::BASENAME, 'relative_path') . ', LENGTH(relative_path), relative_path LIMIT ' . (self::CANDIDATES + 1),
        );
        $statement->execute([$projectId, ...self::holdsValues($needle), ...self::strengthValues($needle)]);
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
        $found = [];
        foreach (array_slice($rows, 0, self::CANDIDATES) as $path) {
            $path = (string) $path;
            $found[] = [
                'type' => 'file', 'id' => null, 'name' => $path, 'canonical_name' => $path, 'kind' => 'file', 'path' => $path, 'line' => null,
                'score' => self::score($needle, basename($path), $path), 'order' => count(self::KINDS) + 1,
            ];
        }

        return [$found, count($rows) > self::CANDIDATES];
    }

    /** A file's own name in SQL: what follows its last `/`. */
    private const BASENAME = "SUBSTR(relative_path, LENGTH(RTRIM(relative_path, REPLACE(relative_path, '/', ''))) + 1)";

    /**
     * An SQL ordering term, lowest first for the strongest kinds of match, as
     * {@see self::score()} ranks them: `$name` is the whole query, then starts
     * with it, then holds it as a run, then `$full` holds it as a run, then
     * the letters only scattered. Its values are bound by
     * {@see self::strengthValues()}. `LOWER()` folds only ASCII, so a name
     * whose non-ASCII letters differ in case falls to the last tier here and
     * is still ranked right by {@see self::score()} if it is read.
     */
    private static function strength(string $name, string $full): string
    {
        return "CASE WHEN LOWER({$name}) = ? THEN 0 WHEN LOWER({$name}) LIKE ? ESCAPE '\\' THEN 1 "
            . "WHEN LOWER({$name}) LIKE ? ESCAPE '\\' THEN 2 WHEN LOWER({$full}) LIKE ? ESCAPE '\\' THEN 3 ELSE 4 END";
    }

    /**
     * The values {@see self::strength()} binds.
     *
     * @return list<string>
     */
    private static function strengthValues(string $needle): array
    {
        $literal = addcslashes($needle, '%_\\');

        return [$needle, $literal . '%', '%' . $literal . '%', '%' . $literal . '%'];
    }

    /**
     * The SQL condition that `$column` holds the letters of `$needle` in
     * order, its values bound by {@see self::holdsValues()} in the same order.
     *
     * SQLite's `LOWER()` and `LIKE` fold only ASCII, so the `LIKE` pattern
     * stands a non-ASCII letter in as any one character. On its own that lets
     * through nearly every name (`設定` would be `%_%_%`), and the bounded read
     * would then keep only the shortest names in the project. Each distinct
     * non-ASCII letter is therefore also required to appear in the name in
     * one of its cases, compared exactly, so the read is narrowed in SQL
     * before its bound. {@see self::score()}, which folds every letter,
     * decides the rest.
     */
    private function holds(string $column, string $needle): string
    {
        $condition = "LOWER({$column}) LIKE ? ESCAPE '\\'";
        foreach (self::nonAsciiCases($needle) as $cases) {
            $condition .= ' AND (' . implode(' OR ', array_fill(0, count($cases), "INSTR({$column}, ?) > 0")) . ')';
        }

        return '(' . $condition . ')';
    }

    /**
     * The values {@see self::holds()} binds: the pattern, then each
     * non-ASCII letter's cases.
     *
     * @return list<string>
     */
    private static function holdsValues(string $needle): array
    {
        $values = [self::pattern($needle)];
        foreach (self::nonAsciiCases($needle) as $cases) {
            array_push($values, ...$cases);
        }

        return $values;
    }

    /**
     * Each distinct non-ASCII letter of `$needle`, as the forms it may take in a name: itself, upper case and title case,
     * and for a sharp s the capital one too.
     *
     * @return list<list<string>>
     */
    private static function nonAsciiCases(string $needle): array
    {
        $letters = array_unique(array_filter(preg_split('//u', $needle, -1, PREG_SPLIT_NO_EMPTY) ?: [], static fn(string $c): bool => strlen($c) > 1));

        return array_values(array_map(static fn(string $c): array => array_values(array_unique([
            $c, mb_strtoupper($c), mb_convert_case($c, MB_CASE_TITLE), ...(self::SHARP_S[$c] ?? []),
        ])), $letters));
    }

    /**
     * A `LIKE` pattern matching the letters in order with anything between
     * them; `%`, `_` and `\` are matched as themselves, and a non-ASCII letter
     * stands for any one character (see {@see self::holds()}).
     */
    private static function pattern(string $needle): string
    {
        $letters = preg_split('//u', $needle, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return '%' . implode('%', array_map(static fn(string $c): string => strlen($c) > 1 ? '_' : addcslashes($c, '%_\\'), $letters)) . '%';
    }

    /**
     * How closely `$name` (what is shown) or `$full` (the full name or path)
     * matches: the whole name 1000, its start 800, a run in it 600, a run in
     * the full name 400, else the letters in order, fewer gaps higher: from
     * 300 down in the name, from 150 down across the full name; 0 for none.
     */
    public static function score(string $needle, string $name, string $full): int
    {
        $lower = mb_strtolower($name);
        if ($lower === $needle) {
            return 1000;
        }
        if (str_starts_with($lower, $needle)) {
            return 800;
        }
        if (str_contains($lower, $needle)) {
            return 600;
        }
        $whole = mb_strtolower($full);
        if (str_contains($whole, $needle)) {
            return 400;
        }
        $gaps = self::gaps($needle, $lower);
        if ($gaps !== null) {
            return max(151, 300 - $gaps * 10);
        }
        // The letters only across the full name (a member's class, a file's directories): below any match in the name itself.
        $gaps = self::gaps($needle, $whole);

        return $gaps === null ? 0 : max(1, 150 - $gaps * 10);
    }

    /** How many runs the letters of `$needle` fall into, in order, in `$haystack`, less one; null when they are not all there. */
    private static function gaps(string $needle, string $haystack): ?int
    {
        $at = 0;
        $gaps = 0;
        $last = -2;
        foreach (preg_split('//u', $needle, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $letter) {
            $found = mb_strpos($haystack, $letter, $at);
            if ($found === false) {
                return null;
            }
            $gaps += $found === $last + 1 ? 0 : 1;
            $last = $found;
            $at = $found + 1;
        }

        return max(0, $gaps - 1);
    }
}
