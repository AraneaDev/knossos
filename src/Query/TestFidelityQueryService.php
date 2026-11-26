<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use Knossos\Query\TestFidelity\OnRequestFindings;
use Knossos\Result\ResultEnvelope;
use Knossos\Scanner\PackagedLanguages;
use Knossos\TestFidelity\{ActiveTestFindings, TestFindingSuppressions, TestRuleCatalog};
use Knossos\TestFidelity\OnRequest\{OnRequestFinding, OverMocking};
use PDO;

/**
 * The test-fidelity findings of the active graph: test doubles that no longer
 * match the code they replace and assertions that cannot fail.
 *
 * Two tiers. The scan tier's findings were stored by the scan that judged
 * them, and are only read here. The on-request tier's are computed from the
 * graph when asked (`tier` `on_request` or `all`) and never stored, so no
 * other surface sees them. In `all`, they are merged with the stored ones in
 * file and line order, then paged in memory.
 *
 * @phpstan-type Item array{id: string, key: string, category: string, confidence: string, rule_id: string, sub_rule: string, message: string, file: ?string, line: ?int, double: ?string, target: ?string, tier: string}
 * @phpstan-type Filters array{path_prefix: ?string, confidence: ?string, category: ?string}
 */
final readonly class TestFidelityQueryService extends AbstractArchitectureQueryService
{
    /** The tiers `audit_tests` lists. */
    public const TIERS = [TestRuleCatalog::SCAN, TestRuleCatalog::ON_REQUEST, 'all'];

    /** The scopes `audit_tests` reads in. */
    public const SCOPES = ['all', 'git-diff'];

    /**
     * A page of the project's test-fidelity findings of the asked tier,
     * ordered by file, line, category, rule and id.
     *
     * A category implies its tier. `scope: "git-diff"` and `base_ref` arrive
     * with `STALE_SINCE`, in Task 2.
     */
    public function auditTests(
        string $projectId,
        ?string $pathPrefix = null,
        ?string $confidence = null,
        ?string $category = null,
        int $limit = 50,
        int $offset = 0,
        string $tier = TestRuleCatalog::SCAN,
        string $scope = 'all',
        ?string $baseRef = null,
        int $timeoutMs = 2000,
    ): ResultEnvelope {
        $project = $this->project($projectId);
        $tier = self::validated($limit, $offset, $confidence, $category, $tier, $scope, $baseRef, $timeoutMs);
        $deadline = $this->now() + $timeoutMs * 1_000_000;
        $suppressions = ActiveTestFindings::suppressionsOf((string) $project['root_realpath']);
        $filters = ['path_prefix' => $pathPrefix, 'confidence' => $confidence, 'category' => $category];
        $scan = $tier !== TestRuleCatalog::ON_REQUEST;
        $partial = false;
        $onRequest = [];
        $languages = [];
        $silent = [];
        if ($tier !== TestRuleCatalog::SCAN) {
            $input = (new OnRequestFindings($this->pdo, fn(): int => $this->now()))->load($projectId, $deadline);
            $partial = $input->partial;
            $languages = $input->testFiles;
            $silent = OverMocking::languagesWithoutImports($input);
            $onRequest = array_values(array_filter(
                OverMocking::findings($input),
                static fn(OnRequestFinding $finding): bool => !$suppressions->suppresses($finding->ruleId, $finding->category->value, $finding->subRule, $finding->file),
            ));
        }
        $findings = new ActiveTestFindings($this->pdo, $projectId, $suppressions);
        $matching = array_map(
            static fn(OnRequestFinding $finding): array => $finding->toItem(),
            array_values(array_filter($onRequest, static fn(OnRequestFinding $finding): bool => self::matches($finding, $filters))),
        );
        $total = ($scan ? $findings->count($filters) : 0) + count($matching);
        if ($matching === []) {
            $items = $scan ? self::items($findings->find($filters, $limit, $offset)) : [];
        } else {
            // The stored findings that could fall on this page, merged with every on-request one.
            $merged = [...($scan ? self::items($findings->find($filters, $offset + $limit)) : []), ...$matching];
            usort($merged, self::order(...));
            $items = array_slice($merged, $offset, $limit);
        }
        $next = $offset + count($items) < $total ? $offset + count($items) : null;

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('%d test-fidelity finding%s.', $total, $total === 1 ? '' : 's'),
            [
                'items' => $items,
                'total' => $total,
                'partial' => $partial,
                'summary' => ['by_language' => $this->byLanguage($projectId, $suppressions, $scan, $onRequest, $languages, $silent)],
                'suppressions_invalid' => $suppressions->invalid(),
                'pagination' => ['offset' => $offset, 'next_offset' => $next],
            ],
            [],
            $partial ? [sprintf('On-request findings were cut short at timeout_ms %d; the list holds the stored findings only.', $timeoutMs)] : [],
            $next !== null,
        );
    }

    /** Every test-fidelity rule, both tiers, and where a finding is suppressed. */
    public function listTestRules(): ResultEnvelope
    {
        $rules = TestRuleCatalog::all();

        return new ResultEnvelope('catalog', '', sprintf('%d test-fidelity rules.', count($rules)), [
            'rules' => $rules,
            'suppression' => 'knossos.json test_finding_suppressions',
        ]);
    }

    /**
     * Refuse an argument out of its bounds, and give the tier to list: the
     * asked one, or the asked category's.
     */
    private static function validated(int $limit, int $offset, ?string $confidence, ?string $category, string $tier, string $scope, ?string $baseRef, int $timeoutMs): string
    {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('limit must be between 1 and 200.');
        }
        if ($offset < 0 || $offset > 100_000) {
            throw new InvalidArgumentException('offset must be between 0 and 100000.');
        }
        $lists = [
            'confidence' => [$confidence, TestRuleCatalog::confidences()],
            'category' => [$category, TestRuleCatalog::categories()],
            'tier' => [$tier, self::TIERS],
            'scope' => [$scope, self::SCOPES],
        ];
        foreach ($lists as $name => [$value, $allowed]) {
            if ($value !== null && !in_array($value, $allowed, true)) {
                throw new InvalidArgumentException(sprintf('%s must be one of: %s.', $name, implode(', ', $allowed)));
            }
        }
        if ($scope !== 'all' || $baseRef !== null) {
            throw new InvalidArgumentException('scope git-diff arrives in Task 2');
        }
        if ($timeoutMs < 1 || $timeoutMs > 10_000) {
            throw new InvalidArgumentException('timeout_ms must be between 1 and 10000.');
        }

        return $category === null ? $tier : (string) TestRuleCatalog::tierOf($category);
    }

    /**
     * Whether an on-request finding passes the filters a stored one is read through.
     *
     * @param Filters $filters
     */
    private static function matches(OnRequestFinding $finding, array $filters): bool
    {
        $prefix = $filters['path_prefix'];

        return ($prefix === null || $prefix === '' || str_starts_with($finding->file, $prefix))
            && ($filters['confidence'] === null || $filters['confidence'] === $finding->confidence->value)
            && ($filters['category'] === null || $filters['category'] === $finding->category->value);
    }

    /**
     * Stored findings as items of the scan tier.
     *
     * @param list<array<string, mixed>> $findings
     * @return list<Item>
     */
    private static function items(array $findings): array
    {
        return array_map(static fn(array $finding): array => [
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
            'tier' => TestRuleCatalog::SCAN,
        ], $findings);
    }

    /**
     * The stored findings' order ({@see ActiveTestFindings::find()}): a finding
     * with no file last, then file and line (none first), category, rule and id,
     * each compared as bytes, as SQLite compares them.
     *
     * @param Item $a
     * @param Item $b
     */
    private static function order(array $a, array $b): int
    {
        // strcmp, not <=>: PHP compares two numeric strings as numbers.
        return [$a['file'] === null, strcmp((string) $a['file'], (string) $b['file']), $a['line'] ?? -1, strcmp($a['category'], $b['category']), strcmp($a['rule_id'], $b['rule_id']), strcmp($a['id'], $b['id'])]
            <=> [$b['file'] === null, 0, $b['line'] ?? -1, 0, 0, 0];
    }

    /**
     * The finding counts per confidence of the tiers listed, for each packaged
     * language and any other a finding's node names. A filter narrows the
     * items, not what the project holds; what the project suppresses is not
     * held. A language whose test files the on-request tier could not judge
     * says why under `notes`.
     *
     * @param list<OnRequestFinding> $onRequest the on-request findings the project holds
     * @param array<string, string> $languages each test file's language
     * @param list<string> $silent the languages `OVER_MOCKING` could not judge
     * @return array<string, array<string, int|list<string>>>
     */
    private function byLanguage(string $projectId, TestFindingSuppressions $suppressions, bool $scan, array $onRequest, array $languages, array $silent): array
    {
        $zeros = array_fill_keys(TestRuleCatalog::confidences(), 0);
        $byLanguage = array_fill_keys(PackagedLanguages::languages(), $zeros);
        $counts = $scan ? $this->storedCounts($projectId, $suppressions) : [];
        foreach ($onRequest as $finding) {
            $counts[] = ['language' => $languages[$finding->file], 'confidence' => $finding->confidence->value, 'findings' => 1];
        }
        foreach ($counts as $row) {
            $language = PackagedLanguages::languageOf($row['language']);
            $byLanguage[$language] ??= $zeros;
            $byLanguage[$language][$row['confidence']] += $row['findings'];
        }
        foreach ($silent as $language) {
            $byLanguage[$language] ??= $zeros;
            $byLanguage[$language]['notes'] = ['OVER_MOCKING: no imports from worker ' . PackagedLanguages::scannerOf($language)];
        }
        ksort($byLanguage);

        return $byLanguage;
    }

    /**
     * The stored findings the project holds, counted per node language and confidence.
     *
     * @return list<array{language: string, confidence: string, findings: int}>
     */
    private function storedCounts(string $projectId, TestFindingSuppressions $suppressions): array
    {
        $parameters = [];
        [$suppressed, $values] = $suppressions->sqlCondition('tf');
        $condition = ActiveTestFindings::named($suppressed, $values, $parameters);
        $statement = $this->pdo->prepare("SELECT n.language, tf.confidence, COUNT(*) AS findings FROM test_findings tf JOIN nodes n ON n.id = tf.node_id WHERE tf.project_id = :project AND {$condition} GROUP BY n.language, tf.confidence");
        $statement->execute($parameters + ['project' => $projectId]);

        return array_map(static fn(array $row): array => ['language' => (string) $row['language'], 'confidence' => (string) $row['confidence'], 'findings' => (int) $row['findings']], $statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
