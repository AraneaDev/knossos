<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use Knossos\Result\ResultEnvelope;
use Knossos\TestFidelity\{ActiveTestFindings, FindingCategory, FindingConfidence, TestFindingSuppressions};
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
    private const PACKAGED_LANGUAGES = ['php' => true, 'python' => true, 'rust' => false, 'typescript' => true];

    /** The language a node's local-id prefix names, where it differs from the language's name here. */
    private const LANGUAGE_OF_PREFIX = ['py' => 'python', 'ts' => 'typescript'];

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
        $suppressions = ActiveTestFindings::suppressionsOf((string) $project['root_realpath']);
        $findings = new ActiveTestFindings($this->pdo, $projectId, $suppressions);
        $filters = ['path_prefix' => $pathPrefix, 'confidence' => $confidence, 'category' => $category];
        $total = $findings->count($filters);
        $items = array_map(static fn(array $finding): array => [
            'id' => $finding['id'],
            'key' => $finding['key'],
            'category' => $finding['category'],
            'confidence' => $finding['confidence'],
            'rule_id' => $finding['rule_id'],
            'sub_rule' => $finding['sub_rule'],
            'message' => $finding['message'],
            'file' => $finding['file'],
            'line' => $finding['line'],
            'double' => $finding['double_name'],
            'target' => $finding['target_name'],
        ], $findings->find($filters, $limit, $offset));
        $next = $offset + count($items) < $total ? $offset + count($items) : null;

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('%d test-fidelity finding%s.', $total, $total === 1 ? '' : 's'),
            [
                'items' => $items,
                'total' => $total,
                'summary' => ['by_language' => $this->byLanguage($projectId, $suppressions)],
                'suppressions_invalid' => $suppressions->invalid(),
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
     * not. A filter narrows the items, not what the project holds; what the
     * project suppresses is not held.
     *
     * @return array<string, array<string, int>|string>
     */
    private function byLanguage(string $projectId, TestFindingSuppressions $suppressions): array
    {
        $parameters = [];
        [$suppressed, $values] = $suppressions->sqlCondition('tf');
        $condition = ActiveTestFindings::named($suppressed, $values, $parameters);
        $statement = $this->pdo->prepare("SELECT n.language, tf.confidence, COUNT(*) AS findings FROM test_findings tf JOIN nodes n ON n.id = tf.node_id WHERE tf.project_id = :project AND {$condition} GROUP BY n.language, tf.confidence");
        $statement->execute($parameters + ['project' => $projectId]);
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
