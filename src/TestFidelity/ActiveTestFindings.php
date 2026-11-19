<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

use Knossos\Roots\RootNotFoundException;
use PDO;

/**
 * The active scan's test-fidelity findings that the project does not suppress,
 * each with its {@see TestFindingKey}, in file and line order.
 *
 * The one reader the gate, `audit_tests` and the dashboard share, so the
 * mapping from a stored row to a keyed finding, and the suppressions applied
 * while reading, exist once.
 *
 * Filters are `path_prefix` (compared as bytes: LIKE would fold ASCII case and
 * read `_` and `%` as wildcards), `confidence` and `category`.
 *
 * @phpstan-type Filters array{path_prefix?: ?string, confidence?: ?string, category?: ?string}
 * @phpstan-type Finding array{id: string, double_id: string, attributes: array<string, mixed>, key: string, category: string, confidence: string, rule_id: string, sub_rule: string, message: string, file: ?string, line: ?int, double_name: string, target_name: ?string, target_canonical: ?string, target_file: ?string}
 */
final readonly class ActiveTestFindings
{
    private const FROM = 'FROM test_findings tf JOIN nodes n ON n.id = tf.node_id LEFT JOIN nodes t ON t.id = tf.target_node_id LEFT JOIN files f ON f.id = tf.file_id LEFT JOIN files tfile ON tfile.id = t.file_id';

    public function __construct(
        private PDO $pdo,
        private string $projectId,
        private TestFindingSuppressions $suppressions,
    ) {}

    /**
     * The suppressions of the project at `$root`; none when its folder is gone,
     * as a project that has no knossos.json to suppress with still has its
     * stored findings. An invalid configuration suppresses nothing and says why
     * in {@see TestFindingSuppressions::invalid()}.
     */
    public static function suppressionsOf(string $root): TestFindingSuppressions
    {
        try {
            return TestFindingSuppressions::fromProjectRoot($root);
        } catch (RootNotFoundException) {
            return new TestFindingSuppressions([]);
        }
    }

    /**
     * A suppression fragment's positional placeholders as named ones, so it can
     * sit beside the named parameters of the rest of a query; the names and
     * their values are added to `$parameters`.
     *
     * @param list<string> $values
     * @param array<string, string> $parameters
     */
    public static function named(string $fragment, array $values, array &$parameters): string
    {
        $index = 0;

        return (string) preg_replace_callback('/\?/', static function () use (&$index, $values, &$parameters): string {
            $name = 'suppressed_' . $index;
            $parameters[$name] = $values[$index++];

            return ':' . $name;
        }, $fragment);
    }

    /**
     * How many findings match.
     *
     * @param Filters $filters
     */
    public function count(array $filters = []): int
    {
        [$where, $parameters] = $this->where($filters);
        $statement = $this->pdo->prepare('SELECT COUNT(*) ' . self::FROM . " WHERE {$where}");
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /**
     * The matching findings from `$offset` on, `$limit` of them when one is given.
     *
     * @param Filters $filters
     * @return list<Finding>
     */
    public function find(array $filters = [], ?int $limit = null, int $offset = 0): array
    {
        [$where, $parameters] = $this->where($filters);
        $statement = $this->pdo->prepare(
            'SELECT tf.id, tf.node_id, tf.attributes_json, tf.category, tf.confidence, tf.rule_id, tf.sub_rule, tf.message, f.relative_path, tf.start_line, n.display_name AS double_name, '
            . 't.display_name AS target_name, t.canonical_name AS target_canonical, tfile.relative_path AS target_file '
            . self::FROM . " WHERE {$where} ORDER BY f.relative_path IS NULL, f.relative_path, tf.start_line, tf.category, tf.rule_id, tf.id"
            . ($limit === null ? '' : ' LIMIT :limit OFFSET :offset'),
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value);
        }
        if ($limit !== null) {
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        }
        $statement->execute();

        return array_map(static fn(array $row): array => [
            'id' => (string) $row['id'],
            'double_id' => (string) $row['node_id'],
            'attributes' => self::attributes($row['attributes_json']),
            'key' => TestFindingKey::of((string) $row['rule_id'], (string) $row['sub_rule'], (string) $row['relative_path'], $row['target_canonical'] === null ? null : (string) $row['target_canonical'], (string) $row['message']),
            'category' => (string) $row['category'],
            'confidence' => (string) $row['confidence'],
            'rule_id' => (string) $row['rule_id'],
            'sub_rule' => (string) $row['sub_rule'],
            'message' => (string) $row['message'],
            'file' => $row['relative_path'] === null ? null : (string) $row['relative_path'],
            'line' => $row['start_line'] === null ? null : (int) $row['start_line'],
            'double_name' => (string) $row['double_name'],
            'target_name' => $row['target_name'] === null ? null : (string) $row['target_name'],
            'target_canonical' => $row['target_canonical'] === null ? null : (string) $row['target_canonical'],
            'target_file' => $row['target_file'] === null ? null : (string) $row['target_file'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed> */
    private static function attributes(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param Filters $filters
     * @return array{string, array<string, string>}
     */
    private function where(array $filters): array
    {
        [$suppressed, $values] = $this->suppressions->sqlCondition('tf');
        $parameters = [];
        $where = 'tf.project_id = :project AND ' . self::named($suppressed, $values, $parameters);
        $parameters['project'] = $this->projectId;
        $prefix = $filters['path_prefix'] ?? null;
        if ($prefix !== null && $prefix !== '') {
            $where .= ' AND substr(f.relative_path, 1, length(:prefix)) = :prefix';
            $parameters['prefix'] = $prefix;
        }
        if (($filters['confidence'] ?? null) !== null) {
            $where .= ' AND tf.confidence = :confidence';
            $parameters['confidence'] = $filters['confidence'];
        }
        if (($filters['category'] ?? null) !== null) {
            $where .= ' AND tf.category = :category';
            $parameters['category'] = $filters['category'];
        }

        return [$where, $parameters];
    }
}
