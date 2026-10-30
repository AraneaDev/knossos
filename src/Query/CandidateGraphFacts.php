<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Classification\TestModuleRule;
use Knossos\Scanner\Protocol\ConfidenceRank;
use Knossos\Store\EdgeQueries;
use Knossos\Store\EnrichedFacts;
use Knossos\Store\ProjectRepository;
use PDO;

/**
 * Facts about the whole project that dead-code classification reads: which
 * node carries a canonical name, whether any name starts with a prefix, a
 * node's degrees, and the member names called on untyped receivers.
 *
 * Answered from the graph itself, not from the nodes a bounded walk read, so a
 * candidate's verdict does not depend on where its name sorts. Each answer is
 * kept for the life of the instance, which is one health query.
 */
final class CandidateGraphFacts
{
    private const CHUNK = 500;

    /** Bound on the walks up the `extends` chain the shape check reads. */
    private const MAX_SHAPE_DEPTH = 10;

    /** @var array<string, ?string> */
    private array $ids = [];

    /** @var array<string, bool> */
    private array $prefixes = [];

    /** @var array<string, bool> directory prefix => whether a source root below it holds a file */
    private array $sourceRoots = [];

    /**
     * Degrees memoized per id, one flat map each rather than an array per id:
     * a project's every candidate stays here for the whole query, and a
     * three-key array per id was a third of what a large one needed.
     *
     * @var array<string, int>
     */
    private array $inDegrees = [];

    /** @var array<string, int> */
    private array $inheritanceInDegrees = [];

    /** @var array<string, int> */
    private array $outDegrees = [];

    /** @var array<string, true>|null */
    private ?array $untyped = null;

    /** @var array<string, array<string, true>> language => the untyped member names its code calls */
    private array $untypedByLanguage = [];

    /**
     * The untyped member names PHP code calls, lower-cased, since PHP reads
     * a method name without regard to case.
     *
     * @var array<string, true>
     */
    private array $untypedCaseless = [];

    /**
     * The project's interfaces, by language: each one's name and its members
     * by name, read once for the shape check.
     *
     * @var array<string, list<array{id: string, name: string, members: array<string, string>}>>|null
     */
    private ?array $interfaces = null;

    /** @var array<string, array<string, true>> type id => the member names it declares */
    private array $typeMembers = [];

    /** @var list<string>|null */
    private ?array $suppressions = null;

    /** @var array<string, array{kind: string, value: string}>|null */
    private ?array $annotations = null;

    /** @param list<string> $edgeKinds */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $projectId,
        private readonly array $edgeKinds,
        private readonly int $minConfidenceRank,
    ) {}

    /** The node carrying `$canonicalName`, the lowest id when several do, or null. */
    public function idOf(string $canonicalName): ?string
    {
        if (!array_key_exists($canonicalName, $this->ids)) {
            $statement = $this->pdo->prepare('SELECT MIN(id) FROM nodes WHERE project_id = ? AND canonical_name = ?');
            $statement->execute([$this->projectId, $canonicalName]);
            $id = $statement->fetchColumn();
            $this->ids[$canonicalName] = is_string($id) ? $id : null;
        }

        return $this->ids[$canonicalName];
    }

    /**
     * Whether any node's canonical name starts with `$prefix`.
     *
     * Asked as a range on the canonical-name index: every name at or after the
     * prefix and before the prefix with its last byte raised by one.
     */
    public function hasCanonicalPrefix(string $prefix): bool
    {
        if ($prefix === '') {
            return true;
        }
        // A byte of 0xFF never occurs in UTF-8, which every canonical name
        // is, so raising the last byte always gives a valid bound.
        if (!array_key_exists($prefix, $this->prefixes)) {
            $statement = $this->pdo->prepare('SELECT 1 FROM nodes WHERE project_id = ? AND canonical_name >= ? AND canonical_name < ? LIMIT 1');
            $statement->execute([$this->projectId, $prefix, substr($prefix, 0, -1) . chr(ord($prefix[strlen($prefix) - 1]) + 1)]);
            $this->prefixes[$prefix] = $statement->fetchColumn() !== false;
        }

        return $this->prefixes[$prefix];
    }

    /**
     * Whether a file sits outside the source roots of a package that keeps
     * its product code in one: no directory on its path is a source root
     * (`src`, `lib`, `app`, `source`), and a directory above it holds one
     * with a file in it. `frontend/scripts/tokens.mjs` beside
     * `frontend/src/` is; anything under `src/` is not, and neither is a
     * file of a package that keeps its code at its own top level, where
     * there is no source root to be outside of.
     */
    public function outsideSourceRoots(string $relativePath): bool
    {
        $segments = explode('/', $relativePath);
        array_pop($segments);
        foreach ($segments as $segment) {
            if (in_array(strtolower($segment), TestModuleRule::SOURCE_ROOTS, true)) {
                return false;
            }
        }
        $directory = '';
        foreach ([...$segments, null] as $segment) {
            if ($this->holdsSourceRoot($directory)) {
                return true;
            }
            if ($segment !== null) {
                $directory .= $segment . '/';
            }
        }

        return false;
    }

    /** Whether a source root directly below `$directory` (`''` or ending in `/`) holds a file. */
    private function holdsSourceRoot(string $directory): bool
    {
        if (!array_key_exists($directory, $this->sourceRoots)) {
            $this->sourceRoots[$directory] = false;
            $statement = $this->pdo->prepare('SELECT 1 FROM files WHERE project_id = ? AND relative_path >= ? AND relative_path < ? LIMIT 1');
            foreach (TestModuleRule::SOURCE_ROOTS as $root) {
                // `0` is the byte after `/`, so the range is every path below it.
                $statement->execute([$this->projectId, $directory . $root . '/', $directory . $root . '0']);
                if ($statement->fetchColumn() !== false) {
                    $this->sourceRoots[$directory] = true;
                    break;
                }
            }
        }

        return $this->sourceRoots[$directory];
    }

    /**
     * In-degree, inheritance in-degree and out-degree over the selected edge
     * kinds at or above the confidence floor, for each of `$ids`.
     *
     * @param list<string> $ids
     * @return array<string, array{in_degree: int, inheritance_in_degree: int, out_degree: int}>
     */
    public function degrees(array $ids): array
    {
        $missing = array_values(array_filter(array_unique($ids), fn(string $id): bool => !isset($this->inDegrees[$id])));
        foreach (array_chunk($missing, self::CHUNK) as $chunk) {
            foreach ($chunk as $id) {
                $this->inDegrees[$id] = 0;
                $this->inheritanceInDegrees[$id] = 0;
                $this->outDegrees[$id] = 0;
            }
            $this->countInbound($chunk);
            $this->countOutbound($chunk);
        }

        $degrees = [];
        foreach ($ids as $id) {
            $degrees[$id] = ['in_degree' => $this->inDegrees[$id], 'inheritance_in_degree' => $this->inheritanceInDegrees[$id], 'out_degree' => $this->outDegrees[$id]];
        }

        return $degrees;
    }

    /** Inbound edges of the selected kinds at or above the floor. */
    public function inDegree(string $id): int
    {
        $this->load($id);

        return $this->inDegrees[$id];
    }

    /** Inbound `extends` and `implements` edges, a subset of the in-degree. */
    public function inheritanceInDegree(string $id): int
    {
        $this->load($id);

        return $this->inheritanceInDegrees[$id];
    }

    /** Outbound edges of the selected kinds at or above the floor. */
    public function outDegree(string $id): int
    {
        $this->load($id);

        return $this->outDegrees[$id];
    }

    /**
     * Loads one node's degrees unless a batch already did.
     *
     * The memo is checked directly rather than through degrees(), whose result
     * is built per call: asked once per candidate, that made a large project
     * quadratic.
     */
    private function load(string $id): void
    {
        if (!isset($this->inDegrees[$id])) {
            $this->degrees([$id]);
        }
    }

    /**
     * Member names the scanners saw called on a receiver they could not type,
     * from every node that lists them (a module, or a calling declaration).
     *
     * @return array<string, true>
     */
    public function untypedMemberNames(): array
    {
        if ($this->untyped === null) {
            $this->untyped = [];
            $statement = $this->pdo->prepare("SELECT language, attributes_json FROM nodes WHERE project_id = ? AND attributes_json LIKE '%unresolved_member_calls%'");
            $statement->execute([$this->projectId]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $calls = json_decode((string) $row['attributes_json'], true)['unresolved_member_calls'] ?? null;
                foreach (is_array($calls) ? $calls : [] as $name) {
                    if (is_string($name)) {
                        $this->untyped[$name] = true;
                        $this->untypedByLanguage[(string) $row['language']][$name] = true;
                        if ($row['language'] === 'php') {
                            $this->untypedCaseless[strtolower($name)] = true;
                        }
                    }
                }
            }
            ksort($this->untyped, SORT_STRING);
        }

        return $this->untyped;
    }

    /**
     * Whether a member of this name, in this language, is called somewhere on
     * a receiver no scanner could type: by its own spelling, or for PHP, which
     * reads method names without regard to case, by a PHP call in any case.
     */
    public function mayBeCalledUntyped(string $language, string $name): bool
    {
        $this->untypedMemberNames();

        return isset($this->untypedByLanguage[$language][$name])
            || ($language === 'php' && isset($this->untypedCaseless[strtolower($name)]));
    }

    /**
     * An interface the type `$typeId` meets by shape, with a member `$name`
     * something calls, or null.
     *
     * A class need not say `implements` to be passed where an interface is
     * expected: one with every member the interface names, its own or
     * inherited, fits it, and a call through the interface then reaches the
     * class's member of that name with no edge to it. Shape is read by public
     * member names alone, so this says the class may be reached, never that
     * it is. The first interface by name, of the same language, answers.
     */
    public function shapeContractOf(string $language, string $typeId, string $name): ?string
    {
        foreach ($this->interfacesOf($language) as $interface) {
            if ($interface['id'] === $typeId || !isset($interface['members'][$name])) {
                continue;
            }
            if (array_diff_key($interface['members'], $this->membersOf($typeId)) !== []) {
                continue;
            }
            if ($this->inDegree($interface['members'][$name]) - $this->inheritanceInDegree($interface['members'][$name]) > 0) {
                return $interface['name'];
            }
        }

        return null;
    }

    /**
     * The project's interfaces in `$language`, by name.
     *
     * @return list<array{id: string, name: string, members: array<string, string>}>
     */
    private function interfacesOf(string $language): array
    {
        if ($this->interfaces === null) {
            $this->interfaces = [];
            $statement = $this->pdo->prepare(
                'SELECT i.id, i.language, i.display_name, m.id AS member_id, m.display_name AS member_name FROM nodes i '
                . "JOIN edges e ON e.project_id = i.project_id AND e.source_id = i.id AND e.kind = 'contains' AND " . EnrichedFacts::excludeSql('e') . ' '
                . 'JOIN nodes m ON m.id = e.target_id '
                . "WHERE i.project_id = ? AND i.kind = 'interface' AND " . EnrichedFacts::excludeSql('i') . ' ORDER BY i.display_name, i.id',
            );
            $statement->execute([$this->projectId]);
            $byId = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (string) $row['id'];
                $byId[$id] ??= ['language' => (string) $row['language'], 'id' => $id, 'name' => (string) $row['display_name'], 'members' => []];
                $byId[$id]['members'][(string) $row['member_name']] ??= (string) $row['member_id'];
            }
            // An interface carries what the interfaces it extends declare too.
            $parents = EdgeWalk::reach($this->pdo, $this->projectId, ['extends'], Direction::Outgoing, array_keys($byId), self::MAX_SHAPE_DEPTH);
            foreach ($byId as $id => $interface) {
                $members = $interface['members'];
                foreach (EdgeWalk::closure((string) $id, $parents) as $parent) {
                    $members += $byId[$parent]['members'] ?? [];
                }
                $this->interfaces[$interface['language']][] = ['id' => $interface['id'], 'name' => $interface['name'], 'members' => $members];
            }
        }

        return $this->interfaces[$language] ?? [];
    }

    /**
     * The member names a value of a type answers to from outside: those it
     * declares or takes from the classes it extends, private, protected and
     * static members left out, since none of them can meet an interface's.
     *
     * @return array<string, true>
     */
    private function membersOf(string $typeId): array
    {
        if (!isset($this->typeMembers[$typeId])) {
            $bases = EdgeWalk::reach($this->pdo, $this->projectId, ['extends'], Direction::Outgoing, [$typeId], self::MAX_SHAPE_DEPTH);
            $types = [$typeId, ...EdgeWalk::closure($typeId, $bases)];
            $members = [];
            foreach (array_chunk($types, self::CHUNK) as $chunk) {
                $statement = $this->pdo->prepare(
                    'SELECT n.display_name, n.attributes_json FROM edges e JOIN nodes n ON n.id = e.target_id '
                    . "WHERE e.project_id = ? AND e.kind = 'contains' AND " . EnrichedFacts::excludeSql('e') . ' AND e.source_id IN (' . self::placeholders($chunk) . ')',
                );
                $statement->execute([$this->projectId, ...$chunk]);
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $attributes = json_decode((string) ($row['attributes_json'] ?? ''), true);
                    $hidden = is_array($attributes) && (isset($attributes['visibility']) || ($attributes['static'] ?? false) === true);
                    if (!$hidden) {
                        $members[(string) $row['display_name']] = true;
                    }
                }
            }
            $this->typeMembers[$typeId] = $members;
        }

        return $this->typeMembers[$typeId];
    }

    /**
     * Canonical names the project's own configuration suppresses, exactly or by prefix.
     *
     * @return list<string>
     */
    public function deadCodeSuppressions(): array
    {
        if ($this->suppressions === null) {
            $config = (new ProjectRepository($this->pdo))->config($this->projectId);
            $list = $config['dead_code_suppressions'] ?? [];
            $this->suppressions = is_array($list) && array_is_list($list) ? array_values(array_filter($list, 'is_string')) : [];
        }

        return $this->suppressions;
    }

    /**
     * Durable agent judgements recorded against a component.
     *
     * @return array<string, array{kind: string, value: string}> keyed by canonical name; a person's judgement wins over a model's whatever its kind, and within one author false_positive wins over intentional, which wins over confirmed_dead
     */
    public function componentAnnotations(): array
    {
        if ($this->annotations === null) {
            $statement = $this->pdo->prepare(
                "SELECT canonical_name, kind, value FROM annotations WHERE project_id = :project AND kind IN ('false_positive', 'intentional', 'confirmed_dead') " .
                "ORDER BY canonical_name, CASE author WHEN 'user' THEN 0 ELSE 1 END, CASE kind WHEN 'false_positive' THEN 0 WHEN 'intentional' THEN 1 ELSE 2 END",
            );
            $statement->execute(['project' => $this->projectId]);
            $this->annotations = [];
            foreach ($statement->fetchAll() as $row) {
                $this->annotations[$row['canonical_name']] ??= ['kind' => $row['kind'], 'value' => $row['value']];
            }
        }

        return $this->annotations;
    }

    /**
     * Adds the inbound edge counts of `$ids` to their degrees.
     *
     * @param list<string> $ids
     */
    private function countInbound(array $ids): void
    {
        $statement = $this->pdo->prepare(
            'SELECT target_id, kind, COUNT(*) AS edge_count FROM edges WHERE project_id = ? ' . $this->edgeFilter() .
            sprintf(' AND target_id IN (%s) GROUP BY target_id, kind', self::placeholders($ids)),
        );
        $statement->execute([$this->projectId, ...$this->edgeKinds, $this->minConfidenceRank, ...$ids]);
        foreach ($statement->fetchAll() as $row) {
            $id = (string) $row['target_id'];
            $this->inDegrees[$id] += (int) $row['edge_count'];
            if (in_array((string) $row['kind'], ['implements', 'extends'], true)) {
                $this->inheritanceInDegrees[$id] += (int) $row['edge_count'];
            }
        }
    }

    /**
     * Records the outbound edge counts of `$ids` in their degrees.
     *
     * @param list<string> $ids
     */
    private function countOutbound(array $ids): void
    {
        $statement = $this->pdo->prepare(
            'SELECT source_id, COUNT(*) AS edge_count FROM edges WHERE project_id = ? ' . $this->edgeFilter() .
            sprintf(' AND source_id IN (%s) GROUP BY source_id', self::placeholders($ids)),
        );
        $statement->execute([$this->projectId, ...$this->edgeKinds, $this->minConfidenceRank, ...$ids]);
        foreach ($statement->fetchAll() as $row) {
            $this->outDegrees[(string) $row['source_id']] = (int) $row['edge_count'];
        }
    }

    /** The kind and confidence conditions every degree counts under, as SQL, over native edges only. */
    private function edgeFilter(): string
    {
        return 'AND ' . EnrichedFacts::excludeSql('edges') . ' AND ' . EdgeQueries::kindFilteredIn('kind', $this->edgeKinds) . ' '
            . 'AND ' . ConfidenceRank::atLeast('confidence');
    }

    /**
     * One `?` per value, comma-separated, for an `IN (…)` list.
     *
     * @param list<string> $values
     */
    private static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
