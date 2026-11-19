<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use Knossos\Result\ResultEnvelope;
use Knossos\TestFidelity\{FindingCategory, FindingConfidence};
use PDO;

/**
 * The test-fidelity findings of the active graph: test doubles that no longer
 * match the code they replace and assertions that cannot fail.
 *
 * Read-only: the findings were stored by the scan that judged them, and this
 * only lists them, in file and line order.
 */
final readonly class TestFidelityQueryService extends AbstractArchitectureQueryService
{
    /** What `by_language` says of a packaged language whose worker emits no test facts. */
    private const NO_TEST_FACTS = 'no test facts';

    /** Every language with a packaged worker, and whether that worker emits test facts yet. */
    private const PACKAGED_LANGUAGES = ['php' => false, 'python' => false, 'rust' => false, 'typescript' => true];

    /** The language a node's local-id prefix names, where it differs from the language's name here. */
    private const LANGUAGE_OF_PREFIX = ['ts' => 'typescript'];

    /** A page of the project's test-fidelity findings, ordered by file, line, category, rule and id. */
    public function auditTests(
        string $projectId,
        ?string $pathPrefix = null,
        ?string $confidence = null,
        ?string $category = null,
        int $limit = 50,
        int $offset = 0,
    ): ResultEnvelope {
        $project = $this->project($projectId);
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('limit must be between 1 and 200.');
        }
        if ($offset < 0 || $offset > 100_000) {
            throw new InvalidArgumentException('offset must be between 0 and 100000.');
        }
        $confidences = array_map(static fn(FindingConfidence $case): string => $case->value, FindingConfidence::cases());
        if ($confidence !== null && !in_array($confidence, $confidences, true)) {
            throw new InvalidArgumentException('confidence must be one of: ' . implode(', ', $confidences) . '.');
        }
        $categories = array_map(static fn(FindingCategory $case): string => $case->value, FindingCategory::cases());
        if ($category !== null && !in_array($category, $categories, true)) {
            throw new InvalidArgumentException('category must be one of: ' . implode(', ', $categories) . '.');
        }
        $where = 'tf.project_id = :project';
        $parameters = ['project' => $projectId];
        if ($pathPrefix !== null && $pathPrefix !== '') {
            // Compared as bytes: LIKE would fold ASCII case and read `_` and `%` as wildcards.
            $where .= ' AND substr(f.relative_path, 1, length(:prefix)) = :prefix';
            $parameters['prefix'] = $pathPrefix;
        }
        if ($confidence !== null) {
            $where .= ' AND tf.confidence = :confidence';
            $parameters['confidence'] = $confidence;
        }
        if ($category !== null) {
            $where .= ' AND tf.category = :category';
            $parameters['category'] = $category;
        }
        $from = 'FROM test_findings tf JOIN nodes n ON n.id = tf.node_id LEFT JOIN nodes t ON t.id = tf.target_node_id LEFT JOIN files f ON f.id = tf.file_id';
        $count = $this->pdo->prepare("SELECT COUNT(*) {$from} WHERE {$where}");
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            "SELECT tf.id, tf.category, tf.confidence, tf.rule_id, tf.sub_rule, tf.message, f.relative_path, tf.start_line, n.display_name AS double_name, t.display_name AS target_name {$from} WHERE {$where} "
            . 'ORDER BY f.relative_path IS NULL, f.relative_path, tf.start_line, tf.category, tf.rule_id, tf.id LIMIT :limit OFFSET :offset',
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $items = array_map(static fn(array $row): array => [
            'id' => (string) $row['id'],
            'category' => (string) $row['category'],
            'confidence' => (string) $row['confidence'],
            'rule_id' => (string) $row['rule_id'],
            'sub_rule' => (string) $row['sub_rule'],
            'message' => (string) $row['message'],
            'file' => $row['relative_path'] === null ? null : (string) $row['relative_path'],
            'line' => $row['start_line'] === null ? null : (int) $row['start_line'],
            'double' => (string) $row['double_name'],
            'target' => $row['target_name'] === null ? null : (string) $row['target_name'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
        $next = $offset + count($items) < $total ? $offset + count($items) : null;

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('%d test-fidelity finding%s.', $total, $total === 1 ? '' : 's'),
            [
                'items' => $items,
                'total' => $total,
                'summary' => ['by_language' => $this->byLanguage($projectId)],
                'pagination' => ['offset' => $offset, 'next_offset' => $next],
            ],
            [],
            [],
            $next !== null,
        );
    }

    /**
     * The project's finding counts per confidence for each language whose worker
     * emits test facts, and a note for each packaged language whose worker does
     * not. Unfiltered: a filter narrows the items, not what the project holds.
     *
     * @return array<string, array<string, int>|string>
     */
    private function byLanguage(string $projectId): array
    {
        $statement = $this->pdo->prepare('SELECT n.language, tf.confidence, COUNT(*) AS findings FROM test_findings tf JOIN nodes n ON n.id = tf.node_id WHERE tf.project_id = :project GROUP BY n.language, tf.confidence');
        $statement->execute(['project' => $projectId]);
        $zeros = array_fill_keys(array_map(static fn(FindingConfidence $case): string => $case->value, FindingConfidence::cases()), 0);
        $byLanguage = [];
        foreach (self::PACKAGED_LANGUAGES as $language => $emitsTestFacts) {
            $byLanguage[$language] = $emitsTestFacts ? $zeros : self::NO_TEST_FACTS;
        }
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $language = self::LANGUAGE_OF_PREFIX[(string) $row['language']] ?? (string) $row['language'];
            if (!is_array($byLanguage[$language] ?? $zeros)) {
                continue;
            }
            $byLanguage[$language] ??= $zeros;
            $byLanguage[$language][(string) $row['confidence']] += (int) $row['findings'];
        }
        ksort($byLanguage);

        return $byLanguage;
    }
}
