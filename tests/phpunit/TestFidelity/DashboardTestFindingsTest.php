<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Query\DashboardService;
use Knossos\Scan\ProjectScanService;
use Knossos\TestFidelity\DashboardTestFindings;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\QueryCountingStatement;
use PDO;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The dashboard's `test_findings` section over the Nemesis TypeScript fixture:
 * `UserService.spec.ts` holds one definite ghost member against
 * `UserService`, a warning return drift, a warning visibility breach and a
 * definite self comparison that replaces no production code.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class DashboardTestFindingsTest extends KnossosTestCase
{
    private const FIXTURE = '/tests/Fixtures/test-fidelity/typescript/nemesis';

    /** @return array<string, mixed> the dashboard's `test_findings`, for a project holding the Nemesis tests, a knossos.json of `$config` when scanned and of `$editedAfterTheScan` when read */
    private function section(?string $config = null, ?string $editedAfterTheScan = null): array
    {
        $root = self::scratchPath('dashboard-test-findings');
        $this->copyTree(self::repositoryRoot() . self::FIXTURE . '/src', $root . '/src');
        $this->copyTree(self::repositoryRoot() . self::FIXTURE . '/tests', $root . '/tests');
        if ($config !== null) {
            file_put_contents($root . '/knossos.json', $config);
        }
        $pdo = $this->freshTestDatabase();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

        if ($editedAfterTheScan !== null) {
            file_put_contents($root . '/knossos.json', $editedAfterTheScan);
        }

        return (new DashboardService($pdo))->dashboard($root)['test_findings'];
    }

    public function testFindingsAreGroupedByTheProductionFileTheyStandIn(): void
    {
        $section = $this->section();

        assertSame([3, 2], [$section['definite'], $section['warning']]);
        // The ghost and the arity mismatch are stale mocks; the self comparison is another test finding.
        assertSame(2, $section['stale']);
        assertSame(['src/UserService.ts'], array_keys($section['by_production_file']));
        $group = $section['by_production_file']['src/UserService.ts'];
        assertSame([2, 'UserService'], [$group['doubles'], $group['target']]);
        assertCount(2, $group['keys']);
        assertSame(['getProfil(): ghost', 'updateProfile(): arity'], $group['reasons']);
        assertSame(false, $section['by_production_file_truncated']);
    }

    public function testOnlyStaleDoublesAreGroupedByProductionFile(): void
    {
        $grouped = $this->grouped([
            self::finding('k1', 'src/a.ts#A::go', 'A', [], 'm', 'ARITY_MISMATCH'),
            self::finding('k2', 'src/a.ts#A::go', 'A', [], 'm', 'TAUTOLOGY'),
            self::finding('k3', 'src/b.ts#B::go', 'B', [], 'm', 'VISIBILITY_BREACH', 'src/b.ts'),
        ]);

        assertSame(['src/a.ts'], array_keys($grouped));
        assertSame(1, $grouped['src/a.ts']['doubles']);
        assertCount(1, $grouped['src/a.ts']['keys']);
    }

    /** The grouping is a part of the section, not a public helper: a warning-only project groups nothing. */
    public function testAWarningNeverFormsAProductionGroup(): void
    {
        $grouped = $this->grouped([self::finding('w', 'src/a.ts#A::go', 'A', [], 'm', 'GHOST_MEMBER', 'src/a.ts', 'warning')]);

        assertSame([], $grouped);
    }

    /** The section reads the findings in three queries, however many stale categories there are, and the stale count is the grouped one. */
    public function testTheSectionCountsInOneGroupedQuery(): void
    {
        $this->grouped([
            self::finding('a', 'src/a.ts#A::go', 'A', [], 'm1', 'GHOST_MEMBER'),
            self::finding('b', 'src/a.ts#A::go', 'A', [], 'm2', 'ARITY_MISMATCH'),
            self::finding('c', 'src/a.ts#A::go', 'A', [], 'm3', 'RETURN_DRIFT'),
            self::finding('d', 'src/a.ts#A::go', 'A', [], 'm4', 'TAUTOLOGY'),
            self::finding('e', 'src/a.ts#A::go', 'A', [], 'm5', 'GHOST_MEMBER', 'src/a.ts', 'warning'),
        ]);
        $section = $this->lastSection;

        assertSame([4, 3, 1], [$section['definite'], $section['stale'], $section['warning']]);
        // One grouped count, the definite page and the warning page.
        assertSame(3, QueryCountingStatement::count('FROM test_findings'));
    }

    public function testWarningsStayOutOfTheProductionGroups(): void
    {
        $section = $this->section();
        $grouped = array_merge(...array_column($section['by_production_file'], 'keys'));
        $warnings = array_column(array_filter($section['list'], static fn(array $item): bool => $item['confidence'] === 'warning'), 'key');

        assertCount(2, $warnings);
        assertSame([], array_values(array_intersect($grouped, $warnings)));
    }

    public function testTheListPutsDefiniteFirst(): void
    {
        $section = $this->section();

        assertSame(['definite', 'definite', 'definite', 'warning', 'warning'], array_column($section['list'], 'confidence'));
        assertSame([['tests/UserService.spec.ts', 7], ['tests/UserService.spec.ts', 29], ['tests/UserService.spec.ts', 32], ['tests/UserService.spec.ts', 17], ['tests/UserService.spec.ts', 23]], array_map(static fn(array $item): array => [$item['file'], $item['line']], $section['list']));
        assertSame(['src/UserService.ts', 'src/UserService.ts', null, 'src/UserService.ts', 'src/UserService.ts'], array_column($section['list'], 'target_file'));
        assertSame(false, $section['list_truncated']);
        assertSame(['key', 'category', 'sub_rule', 'confidence', 'message', 'file', 'line', 'target_file'], array_keys($section['list'][0]));
    }

    /**
     * One definite finding of a double of `$target`, in the shape the shared reader returns.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private static function finding(string $key, string $canonical, string $name, array $attributes, string $message = 'm', string $category = 'GHOST_MEMBER', string $file = 'src/a.ts', string $confidence = 'definite'): array
    {
        return ['key' => $key, 'double_id' => 'double-' . $key, 'confidence' => $confidence, 'category' => $category, 'message' => $message, 'attributes' => $attributes, 'target_canonical' => $canonical, 'target_name' => $name, 'target_file' => $file];
    }

    /** @var array<string, mixed> the section the last {@see self::grouped()} read */
    private array $lastSection = [];

    /**
     * The dashboard section's `by_production_file` over findings stored as given, each a double of its target in `tests/a.spec.ts`.
     *
     * @param list<array<string, mixed>> $findings
     * @return array<string, array{doubles: int, target: string, reasons: list<string>, keys: list<string>}>
     */
    private function grouped(array $findings): array
    {
        $root = self::scratchPath('dashboard-test-findings-seeded');
        $pdo = $this->freshTestDatabase();
        $pdo->exec("INSERT INTO projects(id, name, root_realpath, config_json, created_at, updated_at) VALUES ('p', 'P', '{$root}-gone', '{}', 'now', 'now')");
        $pdo->exec("INSERT INTO scans(id, project_id, mode, status, scanner_set_hash, started_at) VALUES ('s', 'p', 'full', 'complete', 'h', 'now')");
        $file = $pdo->prepare("INSERT OR IGNORE INTO files(id, project_id, relative_path, content_hash, size, mtime, language, scanner_version, last_scan_id) VALUES (?, 'p', ?, 'h', 1, 1, 'typescript', '1', 's')");
        $node = $pdo->prepare("INSERT OR IGNORE INTO nodes(id, project_id, language, kind, canonical_name, display_name, file_id, origin, confidence, owner_key, last_scan_id) VALUES (?, 'p', 'typescript', 'class', ?, ?, ?, 'ast', 'certain', 'o', 's')");
        $insert = $pdo->prepare("INSERT INTO test_findings(id, project_id, node_id, target_node_id, file_id, attributes_json, category, origin, confidence, rule_id, sub_rule, message, start_line, last_scan_id) VALUES (?, 'p', ?, ?, 'file-tests/a.spec.ts', ?, ?, 'ast', ?, ?, 's', ?, 3, 's')");
        $file->execute(['file-tests/a.spec.ts', 'tests/a.spec.ts']);
        foreach ($findings as $at => $finding) {
            $file->execute(['file-' . $finding['target_file'], $finding['target_file']]);
            $node->execute(['target-' . $finding['target_canonical'], $finding['target_canonical'], $finding['target_name'], 'file-' . $finding['target_file']]);
            $node->execute([$finding['double_id'], 'double-' . $finding['key'], 'double', 'file-tests/a.spec.ts']);
            $insert->execute(['f' . $at, $finding['double_id'], 'target-' . $finding['target_canonical'], json_encode($finding['attributes'], JSON_FORCE_OBJECT), $finding['category'], $finding['confidence'], 'rule.' . $finding['category'], $finding['message']]);
        }
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [QueryCountingStatement::class, []]);
        QueryCountingStatement::reset();
        $this->lastSection = DashboardTestFindings::of($pdo, 'p', $root . '-gone');

        return $this->lastSection['by_production_file'];
    }

    public function testTheReasonsMemberIsTheStoredOneThenTheCanonicalLastSegmentThenTheMessage(): void
    {
        $group = $this->grouped([
            // The stored member wins over a canonical name that has a member segment and over the message.
            self::finding('a', 'src/a.ts#Gateway::refund', 'Gateway', ['member' => 'charge'], 'x stubs void, which Gateway no longer has'),
            // No stored member: the canonical name's last segment.
            self::finding('b', 'src/a.ts#Gateway::capture', 'Gateway', [], 'x stubs void, which Gateway no longer has', 'ARITY_MISMATCH'),
            // Neither: the member the message names.
            self::finding('c', 'src/a.ts#Gateway', 'Gateway', [], 'x stubs settle, which Gateway no longer has', 'RETURN_DRIFT'),
            // None at all: the category alone.
            self::finding('d', 'src/b.ts#Gateway', 'Gateway', [], 'm', 'GHOST_MEMBER', 'src/b.ts'),
        ]);

        assertSame(['capture(): arity', 'charge(): ghost', 'settle(): return'], $group['src/a.ts']['reasons']);
        assertSame(['ghost'], $group['src/b.ts']['reasons']);
    }

    public function testTheMostCommonOwnerNamesTheFileAndATieGoesToTheFirstAlphabetically(): void
    {
        $owners = fn(array ...$findings): string => $this->grouped($findings)['src/a.ts']['target'];

        assertSame('Beta', $owners(
            self::finding('1', 'src/a.ts#Alpha::x', 'Alpha', []),
            self::finding('2', 'src/a.ts#Beta::x', 'Beta', []),
            self::finding('3', 'src/a.ts#Beta::y', 'Beta', []),
        ));
        assertSame('Alpha', $owners(
            self::finding('1', 'src/a.ts#Beta::x', 'Beta', []),
            self::finding('2', 'src/a.ts#Alpha::x', 'Alpha', []),
        ));
    }

    public function testSuppressionsAreApplied(): void
    {
        $suppressed = $this->section((string) json_encode(['version' => 1, 'test_finding_suppressions' => [['rule' => 'GHOST_MEMBER']]]));
        assertSame([2, 2, null], [$suppressed['definite'], $suppressed['warning'], $suppressed['suppressions_invalid']]);
        assertSame(['updateProfile(): arity'], $suppressed['by_production_file']['src/UserService.ts']['reasons']);
    }

    public function testAnInvalidConfigIsSaidNotSwallowed(): void
    {
        $section = $this->section(null, (string) json_encode(['version' => 1, 'test_finding_suppressions' => [['rule' => '']]]));

        assertSame('PROJECT_CONFIG_INVALID: test_finding_suppressions entries need a rule.', $section['suppressions_invalid']);
        assertSame([3, 2], [$section['definite'], $section['warning']]);
    }
}
