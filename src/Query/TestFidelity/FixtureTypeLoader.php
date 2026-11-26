<?php

declare(strict_types=1);

namespace Knossos\Query\TestFidelity;

use Knossos\Query\QueryBounds;
use Knossos\Query\ReportableComponent;
use Knossos\Scanner\PackagedLanguages;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\TestFidelity\Facts\EnumCases;
use Knossos\TestFidelity\Facts\FieldLists;
use Knossos\TestFidelity\Fixtures\FixtureType;
use Knossos\TestFidelity\Fixtures\FixtureTypes;
use Knossos\TestFidelity\TestFidelityGraph;
use PDO;

/**
 * The types a project's fixtures are checked against, read from its active
 * graph (Decision 6): every type and enum outside test code, each type's
 * whole field list composed over its lineage ({@see FieldLists}), and what
 * its lineage says of magic members and constructors.
 *
 * The graph is rebuilt from the stored nodes under the references the
 * workers emitted (`scanner_local_id`), so it answers as the scan's own
 * {@see TestFidelityGraph} does. A node the scan left unresolved is no node
 * here: an edge to it names an ancestor the scan does not hold. Only the
 * members the check reads are loaded: the magic and constructor methods, and
 * PHP's promoted properties.
 *
 * @phpstan-import-type Enum from FixtureTypes
 */
final readonly class FixtureTypeLoader
{
    /** The node kinds that declare a type a fixture may hold, or an enum. */
    private const TYPE_KINDS = "('class', 'interface', 'trait', 'type_alias', 'enum')";

    /** The kinds a fixture's records may be of: an enum's are cases, a trait's are no record. */
    private const RECORD_KINDS = ['class', 'interface', 'type_alias'];

    /** The members the check reads, by lower-case name: the magic methods, and the constructors. */
    private const MEMBERS = "('__get', '__set', '__getattr__', '__getattribute__', '__construct', 'constructor')";

    /** The magic methods per language: a type whose lineage declares one answers to any name. */
    private const MAGIC = ['php' => ['__get', '__set'], 'python' => ['__getattr__', '__getattribute__']];

    /** The constructor per language: a type whose lineage declares one may fill a field itself. */
    private const CONSTRUCTOR = ['php' => '__construct', 'typescript' => 'constructor'];

    public function __construct(private PDO $pdo) {}

    /**
     * The project's fixture types, and the languages whose worker sent no
     * field facts; null when the graph holds more nodes than a read takes.
     *
     * @return array{types: FixtureTypes, factless: list<string>}|null
     */
    public function load(string $projectId): ?array
    {
        $types = $this->rows(
            'SELECT n.kind, n.language, n.canonical_name, n.display_name, n.start_line, n.end_line, n.attributes_json, f.relative_path, '
            . 'EXISTS (SELECT 1 FROM classifications c WHERE c.project_id = n.project_id AND c.node_id = n.id AND c.role = :role) AS test '
            . 'FROM nodes n JOIN files f ON f.id = n.file_id WHERE n.project_id = :project AND n.kind IN ' . self::TYPE_KINDS . ' ORDER BY n.canonical_name, n.id',
            $projectId,
            QueryBounds::MAX_NODES,
            ['role' => ReportableComponent::TEST_ROLE],
        );
        $members = $this->rows(
            'SELECT n.kind, n.language, n.canonical_name, n.display_name, n.start_line, n.end_line, n.attributes_json, f.relative_path, 0 AS test '
            . 'FROM nodes n JOIN files f ON f.id = n.file_id WHERE n.project_id = :project AND ((n.kind = \'method\' AND lower(n.display_name) IN ' . self::MEMBERS . ') '
            . "OR (n.kind = 'property' AND json_extract(n.attributes_json, '$.promoted') = 1)) ORDER BY n.canonical_name, n.id",
            $projectId,
            QueryBounds::MAX_NODES,
        );
        $edges = $this->rows(
            'SELECT e.kind, s.attributes_json AS source, t.attributes_json AS target, sf.relative_path, e.start_line FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id JOIN files sf ON sf.id = s.file_id '
            . 'WHERE e.project_id = :project AND s.kind IN ' . self::TYPE_KINDS . " AND e.kind IN ('contains', 'extends', 'implements', 'uses_trait') ORDER BY e.id",
            $projectId,
            QueryBounds::MAX_EDGES,
        );
        if ($types === null || $members === null || $edges === null) {
            return null;
        }
        $typeNodes = [];
        foreach ($types as $row) {
            $node = self::node($row);
            if ($node !== null) {
                $typeNodes[] = [$node, PackagedLanguages::languageOf((string) $row['language']), (bool) $row['test']];
            }
        }
        $memberNodes = array_values(array_filter(array_map(self::node(...), $members)));
        $graph = TestFidelityGraph::fromContributions([new ScanContribution('knossos.fixtures:graph', [...array_column($typeNodes, 0), ...$memberNodes], self::edges($edges))]);

        return self::index($graph, $typeNodes);
    }

    /**
     * The fixture types of the type nodes, the test code's left out.
     *
     * @param list<array{NodeFact, string, bool}> $typeNodes each type node, its language, and whether it is test code
     * @return array{types: FixtureTypes, factless: list<string>}
     */
    private static function index(TestFidelityGraph $graph, array $typeNodes): array
    {
        $records = $enums = $names = [];
        $languages = $withFacts = [];
        foreach ($typeNodes as [$node, $language, $test]) {
            $languages[$language] = true;
            if (array_key_exists('fields', $node->attributes) || array_key_exists('cases', $node->attributes)) {
                $withFacts[$language] = true;
            }
            if ($test) {
                continue;
            }
            $names[$node->displayName] = ($names[$node->displayName] ?? 0) + 1;
            if (array_key_exists('cases', $node->attributes)) {
                $enums[] = ['language' => $language, 'name' => $node->displayName, 'canonical' => $node->canonicalName, 'cases' => EnumCases::ofNode($node)];
            } elseif (in_array($node->kind, self::RECORD_KINDS, true) && ($fields = FieldLists::of($graph, $node)) !== null) {
                $records[] = self::type($graph, $node, $language, $fields->fields());
            }
        }
        $factless = array_values(array_diff(array_keys($languages), array_keys($withFacts)));
        sort($factless);

        return ['types' => new FixtureTypes($records, $enums, $names, $graph), 'factless' => $factless];
    }

    /**
     * One record type, with what its lineage says of magic and constructors.
     *
     * @param array<string, array{type: ?string, required: bool, line: int}> $fields
     */
    private static function type(TestFidelityGraph $graph, NodeFact $node, string $language, array $fields): FixtureType
    {
        $magic = false;
        foreach (self::MAGIC[$language] ?? [] as $method) {
            $magic = $magic || $graph->member($node, $method) !== null;
        }
        $constructor = isset(self::CONSTRUCTOR[$language]) ? $graph->member($node, self::CONSTRUCTOR[$language]) : null;
        $promoted = [];
        $renamed = false;
        if ($constructor !== null && $language === 'php') {
            foreach (array_keys($fields) as $field) {
                if (($graph->member($node, '$' . $field)?->attributes['promoted'] ?? false) === true) {
                    $promoted[] = (string) $field;
                }
            }
            $params = $constructor->attributes['signature']['params'] ?? null;
            $renamed = !is_array($params) || array_filter($params, static fn(mixed $param): bool => !is_array($param) || !isset($fields[(string) ($param['name'] ?? '')])) !== [];
        }

        return new FixtureType($node->displayName, $node->canonicalName, $language, $node->evidence->relativePath, $fields, $magic, $constructor !== null, $renamed, $promoted);
    }

    /**
     * A stored node as the worker emitted it; null for one the scan left unresolved.
     *
     * @param array<string, mixed> $row
     */
    private static function node(array $row): ?NodeFact
    {
        $attributes = json_decode((string) $row['attributes_json'], true);
        $attributes = is_array($attributes) ? $attributes : [];
        $reference = $attributes['scanner_local_id'] ?? null;
        if (!is_string($reference) || $reference === '' || ($attributes['unresolved'] ?? false) === true) {
            return null;
        }
        $start = max(1, (int) ($row['start_line'] ?? 1));

        return new NodeFact($reference, (string) $row['kind'], (string) $row['canonical_name'], (string) $row['display_name'], Origin::Ast, Confidence::Certain, new Evidence((string) $row['relative_path'], $start, max($start, (int) ($row['end_line'] ?? $start))), $attributes);
    }

    /**
     * Stored edges under the references the worker emitted: a resolved
     * node's `scanner_local_id`, or the reference an unresolved one keeps.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<EdgeFact>
     */
    private static function edges(array $rows): array
    {
        $edges = [];
        foreach ($rows as $row) {
            $source = self::reference((string) $row['source']);
            $target = self::reference((string) $row['target']);
            if ($source !== null && $target !== null) {
                $line = max(1, (int) ($row['start_line'] ?? 1));
                $edges[] = new EdgeFact((string) $row['kind'], $source, $target, Origin::Ast, Confidence::Certain, new Evidence((string) $row['relative_path'], $line, $line));
            }
        }

        return $edges;
    }

    /** The reference a stored node is known by to the worker that emitted or named it. */
    private static function reference(string $attributesJson): ?string
    {
        $attributes = json_decode($attributesJson, true);
        $reference = is_array($attributes) ? ($attributes['scanner_local_id'] ?? $attributes['reference'] ?? null) : null;

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /**
     * A query's rows, at most `$cap` of them; null when there are more.
     *
     * @param array<string, string> $parameters
     * @return list<array<string, mixed>>|null
     */
    private function rows(string $sql, string $projectId, int $cap, array $parameters = []): ?array
    {
        $statement = $this->pdo->prepare($sql . ' LIMIT :cap');
        $statement->bindValue(':project', $projectId);
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value);
        }
        $statement->bindValue(':cap', $cap + 1, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return count($rows) > $cap ? null : $rows;
    }
}
