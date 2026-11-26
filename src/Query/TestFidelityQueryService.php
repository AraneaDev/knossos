<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use Knossos\Git\GitWorkingTreeProvider;
use Knossos\Query\TestFidelity\{FixtureCheckService, OnRequestFindings, ScanTierItems};
use Knossos\Result\ResultEnvelope;
use Knossos\Scanner\PackagedLanguages;
use Knossos\TestFidelity\{ActiveTestFindings, OnRequestCategory, TestFindingSuppressions, TestRuleCatalog};
use Knossos\TestFidelity\OnRequest\{OnRequestFinding, OnRequestInput, OverMocking, StaleSince};
use PDO;
use Throwable;

/**
 * The test-fidelity findings of the active graph: test doubles that no longer
 * match the code they replace, assertions that cannot fail, and fixtures
 * whose records no longer fit their type.
 *
 * Two tiers. The scan tier's findings were stored by the scan that judged
 * them, and are only read here. The on-request tier's are computed from the
 * graph when asked (`tier` `on_request` or `all`) and never stored, so no
 * other surface sees them. In `all`, they are merged with the stored ones in
 * file and line order, then paged in memory.
 *
 * In scope `git-diff`, git names the files changed since `base_ref` (staged,
 * unstaged and untracked; the working tree against `HEAD` when no ref is
 * given), `STALE_SINCE` reads them, and the stored findings are narrowed to
 * those whose own file or target's file changed, as Momus's `diffRelevant`
 * narrows its drift rules. A git failure is an error, never an empty answer.
 *
 * @phpstan-import-type Item from ScanTierItems
 * @phpstan-type Filters array{path_prefix: ?string, confidence: ?string, category: ?string}
 */
final readonly class TestFidelityQueryService extends AbstractArchitectureQueryService
{
    /** The tiers `audit_tests` lists. */
    public const TIERS = [TestRuleCatalog::SCAN, TestRuleCatalog::ON_REQUEST, 'all'];

    /** The scopes `audit_tests` reads in. */
    public const SCOPES = ['all', 'git-diff'];

    /** The ref a git-diff without `base_ref` is read against, as the result names it. */
    private const DEFAULT_REF = 'HEAD';

    /** How many changed files git may name; more is an error (the working-tree reader's own cap). */
    private const MAX_CHANGED = 1000;

    /** The longest a git call may take, the working-tree reader's own bound. */
    private const GIT_TIMEOUT_MS = 5000;

    /**
     * @param ?GitWorkingTreeProvider $gitWorkingTree where scope `git-diff` reads the changed files; null makes that scope an error
     * @param ?FixtureCheckService $fixtures what computes `FIXTURE_DRIFT`; one over the same database by default
     */
    public function __construct(PDO $pdo, ?Closure $clock = null, private ?GitWorkingTreeProvider $gitWorkingTree = null, private ?FixtureCheckService $fixtures = null)
    {
        parent::__construct($pdo, $clock);
    }

    /**
     * A page of the project's test-fidelity findings of the asked tier,
     * ordered by file, line, category, rule and id.
     *
     * A category implies its tier; scope `git-diff` implies tier `all`
     * unless a tier is given. `timeout_ms` bounds the on-request tier, and is
     * checked between its phases (the git call, then each graph read), not
     * within one.
     */
    public function auditTests(
        string $projectId,
        ?string $pathPrefix = null,
        ?string $confidence = null,
        ?string $category = null,
        int $limit = 50,
        int $offset = 0,
        ?string $tier = null,
        string $scope = 'all',
        ?string $baseRef = null,
        int $timeoutMs = 2000,
    ): ResultEnvelope {
        $project = $this->project($projectId);
        $tier = self::validated($limit, $offset, $confidence, $category, $tier, $scope, $baseRef, $timeoutMs);
        $deadline = $this->now() + $timeoutMs * 1_000_000;
        $ref = $baseRef ?? self::DEFAULT_REF;
        $changed = $scope === 'git-diff' ? $this->changed((string) $project['root_realpath'], $baseRef, $deadline) : null;
        $suppressions = ActiveTestFindings::suppressionsOf((string) $project['root_realpath']);
        $filters = ['path_prefix' => $pathPrefix, 'confidence' => $confidence, 'category' => $category];
        $scan = $tier !== TestRuleCatalog::ON_REQUEST;
        $input = $tier === TestRuleCatalog::SCAN ? new OnRequestInput([], [], [], []) : (new OnRequestFindings($this->pdo, fn(): int => $this->now()))->load($projectId, $deadline, $changed);
        // The fixture walk reads files, so it runs only when its category may be listed.
        $fixtures = $tier === TestRuleCatalog::SCAN || ($category !== null && $category !== OnRequestCategory::FixtureDrift->value) ? ['findings' => [], 'languages' => [], 'partial' => false, 'factless' => []]
            : ($this->fixtures ?? new FixtureCheckService($this->pdo, $this->clock))->findings($projectId, (string) $project['root_realpath'], $deadline);
        $onRequest = array_values(array_filter(
            [...OverMocking::findings($input), ...StaleSince::findings($input, $ref), ...$fixtures['findings']],
            static fn(OnRequestFinding $finding): bool => !$suppressions->suppresses($finding->ruleId, $finding->category->value, $finding->subRule, $finding->file),
        ));
        $findings = new ActiveTestFindings($this->pdo, $projectId, $suppressions);
        $matching = array_map(
            static fn(OnRequestFinding $finding): array => $finding->toItem(),
            array_values(array_filter($onRequest, static fn(OnRequestFinding $finding): bool => self::matches($finding, $filters))),
        );
        [$items, $total] = self::page($findings, $filters, $scan, $changed, $matching, $limit, $offset);
        $next = $offset + count($items) < $total ? $offset + count($items) : null;
        $data = [
            'items' => $items,
            'total' => $total,
            'partial' => $input->partial || $fixtures['partial'],
            'summary' => ['by_language' => $this->byLanguage($projectId, $suppressions, $scan, $onRequest, $input->testFiles + $fixtures['languages'], OverMocking::languagesWithoutImports($input), $fixtures['factless'])],
            'suppressions_invalid' => $suppressions->invalid(),
            'pagination' => ['offset' => $offset, 'next_offset' => $next],
        ];

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('%d test-fidelity finding%s.', $total, $total === 1 ? '' : 's'),
            $changed === null ? $data : $data + ['git' => ['base_ref' => $ref, 'changed_files' => count($changed)]],
            [],
            [...self::warnings($input, $scan, $tier !== TestRuleCatalog::SCAN, $timeoutMs, $category, $changed), ...($fixtures['partial'] ? [sprintf('FIXTURE_DRIFT was cut short by timeout_ms %d or a bound on the walk; the list holds what was checked by then.', $timeoutMs)] : [])],
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
     * asked category's, else the asked one, else `all` in scope `git-diff`
     * and `scan` in any other.
     */
    private static function validated(int $limit, int $offset, ?string $confidence, ?string $category, ?string $tier, string $scope, ?string $baseRef, int $timeoutMs): string
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
        if ($baseRef !== null && $scope !== 'git-diff') {
            throw new InvalidArgumentException('base_ref requires scope git-diff.');
        }
        if ($timeoutMs < 1 || $timeoutMs > 10_000) {
            throw new InvalidArgumentException('timeout_ms must be between 1 and 10000.');
        }

        return $category !== null ? (string) TestRuleCatalog::tierOf($category) : ($tier ?? ($scope === 'git-diff' ? 'all' : TestRuleCatalog::SCAN));
    }

    /**
     * The project files changed since `$baseRef` (the working tree against
     * `HEAD` when null), staged, unstaged and untracked alike. Every failure
     * to read them is an error: an empty set would read as a clean diff.
     *
     * @return list<string>
     */
    private function changed(string $root, ?string $baseRef, int $deadline): array
    {
        if ($this->gitWorkingTree === null) {
            throw new InvalidArgumentException('scope git-diff is unavailable: this server reads no git working tree.');
        }
        $remaining = intdiv($deadline - $this->now(), 1_000_000);
        try {
            $changes = $this->gitWorkingTree->changes($root, $baseRef, self::MAX_CHANGED, max(1, min(self::GIT_TIMEOUT_MS, $remaining)));
        } catch (Throwable $error) {
            throw new InvalidArgumentException(sprintf('git-diff scope failed against %s: %s', $baseRef ?? self::DEFAULT_REF, substr($error->getMessage(), 0, 500)), previous: $error);
        }
        if ($changes['truncated']) {
            throw new InvalidArgumentException(sprintf('git-diff scope failed against %s: more than %d files changed; pick a nearer base_ref.', $baseRef ?? self::DEFAULT_REF, self::MAX_CHANGED));
        }

        return $changes['paths'];
    }

    /**
     * The page of items and the total: the stored findings of the tier
     * (narrowed to the changed files in scope `git-diff`) merged with every
     * matching on-request one, in the stored order. With no on-request item
     * outside `git-diff`, the page is read from the store as it is.
     *
     * @param Filters $filters
     * @param ?list<string> $changed
     * @param list<Item> $matching
     * @return array{list<Item>, int}
     */
    private static function page(ActiveTestFindings $findings, array $filters, bool $scan, ?array $changed, array $matching, int $limit, int $offset): array
    {
        if (!$scan) {
            $stored = [];
        } elseif ($changed !== null) {
            $relevant = array_fill_keys($changed, true);
            $stored = ScanTierItems::of(array_values(array_filter(
                $findings->find($filters),
                static fn(array $finding): bool => isset($relevant[(string) $finding['file']]) || isset($relevant[(string) $finding['target_file']]),
            )));
        } elseif ($matching === []) {
            return [ScanTierItems::of($findings->find($filters, $limit, $offset)), $findings->count($filters)];
        } else {
            // The stored findings that could fall on this page, merged with every on-request one.
            $stored = ScanTierItems::of($findings->find($filters, $offset + $limit));
        }
        $total = ($scan && $changed === null ? $findings->count($filters) : count($stored)) + count($matching);
        $merged = [...$stored, ...$matching];
        usort($merged, self::order(...));

        return [array_slice($merged, $offset, $limit), $total];
    }

    /**
     * What the result could not hold, and why: a load cut short by the
     * deadline or a row cap, and a `STALE_SINCE` the on-request tier would
     * list, asked for by name or by no category, outside the one scope that
     * computes it: its zero is the scope's, not the project's.
     *
     * @param ?list<string> $changed
     * @return list<string>
     */
    private static function warnings(OnRequestInput $input, bool $scan, bool $onRequest, int $timeoutMs, ?string $category, ?array $changed): array
    {
        $warnings = [];
        if ($input->partial) {
            $warnings[] = sprintf(
                'On-request findings were cut short by %s; the list holds %s.',
                $input->cutBy === 'timeout_ms' ? sprintf('timeout_ms %d', $timeoutMs) : 'the row cap on a graph read',
                $scan ? 'the stored findings only' : 'none of them',
            );
        }
        if ($onRequest && $changed === null && ($category === null || $category === OnRequestCategory::StaleSince->value)) {
            $warnings[] = 'STALE_SINCE is computed only in scope git-diff.';
        }

        return $warnings;
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
     * @param list<string> $factless the languages whose worker sent `FIXTURE_DRIFT` no field facts
     * @return array<string, array<string, int|list<string>>>
     */
    private function byLanguage(string $projectId, TestFindingSuppressions $suppressions, bool $scan, array $onRequest, array $languages, array $silent, array $factless = []): array
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
        foreach ($factless as $language) {
            $byLanguage[$language] ??= $zeros;
            $byLanguage[$language]['notes'] = [...($byLanguage[$language]['notes'] ?? []), 'FIXTURE_DRIFT: no field facts from worker ' . PackagedLanguages::scannerOf($language)];
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
