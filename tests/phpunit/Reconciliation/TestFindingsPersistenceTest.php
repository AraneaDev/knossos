<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use Knossos\Reconciliation\FullScanRequest;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanOptions;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\TestFidelity\FindingCategory;
use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\TestFidelityAnalysis;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\TestFidelity\TestFidelityRule;
use Knossos\TestFidelity\TestFinding;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

use PHPUnit\Framework\Attributes\Group;

/**
 * Findings ride the scan into `test_findings`, and leave it when the code they
 * describe is fixed. The rule here is a stand-in for the real ghost-member
 * rule, which later work supplies: it reports each stubbed member its target
 * does not declare.
 */
#[Group('test-fidelity')]
final class TestFindingsPersistenceTest extends KnossosTestCase
{
    private static function ghostRule(): TestFidelityRule
    {
        return new class implements TestFidelityRule {
            public function id(): string
            {
                return 'test.fidelity.ghost_member.v1';
            }

            public function findings(TestFidelityGraph $graph): iterable
            {
                foreach ($graph->doubles() as $double) {
                    $target = $graph->target($double);
                    if (!$target instanceof NodeFact) {
                        continue;
                    }
                    foreach ($double->members as $member) {
                        if ($graph->member($target, $member->name) === null) {
                            $node = $graph->nodeOf($double);
                            yield new TestFinding(
                                $node->localId,
                                $target->localId,
                                FindingCategory::GhostMember,
                                FindingConfidence::Definite,
                                $this->id(),
                                'missing_member',
                                sprintf('%s stubs %s, which %s no longer has', $node->displayName, $member->name, $target->displayName),
                                $node->evidence,
                                ['member' => $member->name],
                            );
                        }
                    }
                }
            }
        };
    }

    /**
     * A rule whose findings name nodes no worker emitted: one a target that is
     * not in the scan, one a double that is not.
     */
    private static function danglingRule(): TestFidelityRule
    {
        return new class implements TestFidelityRule {
            public function id(): string
            {
                return 'test.fidelity.ghost_member.v1';
            }

            public function findings(TestFidelityGraph $graph): iterable
            {
                $node = $graph->nodeOf($graph->doubles()[0]);
                $finding = static fn(string $double, string $target, string $message): TestFinding => new TestFinding(
                    $double,
                    $target,
                    FindingCategory::GhostMember,
                    FindingConfidence::Definite,
                    'test.fidelity.ghost_member.v1',
                    'missing_member',
                    $message,
                    $node->evidence,
                );
                yield $finding($node->localId, 'ts:class:src/Gone.ts#Gone', 'target gone');
                yield $finding('ts:test_double:tests/Gone.test.ts#double:1:1', 'ts:class:src/UserService.ts#UserService', 'double gone');
            }
        };
    }

    /** @return array{0: PDO, 1: ProjectScanService, 2: string, 3: \Knossos\Result\ResultEnvelope} */
    private function scanned(?TestFidelityAnalysis $analysis = null): array
    {
        $root = self::scratchPath('ts-ghost');
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/ts-ghost', $root);
        $pdo = $this->freshTestDatabase();
        $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root], testFidelity: $analysis ?? new TestFidelityAnalysis(self::ghostRule()));
        $result = $service->scan($root);

        return [$pdo, $service, $root, $result];
    }

    /** @return list<array<string, mixed>> */
    private static function findings(PDO $pdo): array
    {
        return $pdo->query(
            'SELECT f.category, f.confidence, f.rule_id, f.sub_rule, f.origin, f.start_line, f.message, f.attributes_json, d.canonical_name AS double_name, '
            . 't.canonical_name AS target_name, fl.relative_path, f.last_scan_id = p.active_scan_id AS on_active_scan '
            . 'FROM test_findings f JOIN projects p ON p.id = f.project_id JOIN nodes d ON d.id = f.node_id '
            . 'LEFT JOIN nodes t ON t.id = f.target_node_id LEFT JOIN files fl ON fl.id = f.file_id',
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testFindingsAreSavedWithTheScan(): void
    {
        [$pdo] = $this->scanned();

        assertSame([[
            'category' => 'GHOST_MEMBER',
            'confidence' => 'definite',
            'rule_id' => 'test.fidelity.ghost_member.v1',
            'sub_rule' => 'missing_member',
            'origin' => 'derived',
            'start_line' => 6,
            'message' => 'vi.spyOn UserService.getProfil stubs getProfil, which UserService no longer has',
            'attributes_json' => '{"member":"getProfil"}',
            'double_name' => 'tests/UserService.test.ts#double:6:3',
            'target_name' => 'src/UserService.ts#UserService',
            'relative_path' => 'tests/UserService.test.ts',
            'on_active_scan' => 1,
        ]], self::findings($pdo));
    }

    public function testAnUnchangedRescanKeepsTheFinding(): void
    {
        [$pdo, $service, $root] = $this->scanned();
        $this->backdateDirectories($root, 10);

        $result = $service->scan($root);

        assertArrayHasKey('fast_path', $result->data);
        assertSame(1, count(self::findings($pdo)));
        assertSame(1, self::findings($pdo)[0]['on_active_scan']);
    }

    public function testFixingTheTargetClearsTheFinding(): void
    {
        [$pdo, $service, $root] = $this->scanned();
        // Only the production file changes; the test file is left as it was.
        file_put_contents($root . '/src/UserService.ts', <<<'TS'
            export class UserService {
              getProfile(id: string): string {
                return id;
              }

              getProfil(id: string): string {
                return id;
              }
            }

            TS);

        $service->scan($root);

        assertSame([], self::findings($pdo));
    }

    public function testARebuiltGraphKeepsTheFindingId(): void
    {
        [$pdo, $service, $root] = $this->scanned();
        $ids = static fn(): array => $pdo->query('SELECT id FROM test_findings')->fetchAll(PDO::FETCH_COLUMN);
        $before = $ids();

        $service->scan($root, new ScanOptions(mode: 'full'));

        assertSame(1, count($before));
        assertSame($before, $ids());
        // Rewritten by the new scan, not left over from the first.
        assertSame(1, self::findings($pdo)[0]['on_active_scan']);
    }

    public function testADanglingReferenceNeverFailsTheScan(): void
    {
        [$pdo, , , $result] = $this->scanned(new TestFidelityAnalysis(self::danglingRule()));

        $rows = self::findings($pdo);
        assertSame(1, count($rows));
        assertSame('target gone', $rows[0]['message']);
        assertNull($rows[0]['target_name']);
        $warnings = implode("\n", $result->warnings);
        assertStringContainsString('TEST_FINDING_DROPPED: 1 test-fidelity finding', $warnings);
        assertStringContainsString('TEST_FINDING_TARGET_UNRESOLVED: 1 test-fidelity finding', $warnings);
    }

    public function testReconciliationDropsWhatItCannotResolveInsteadOfFailing(): void
    {
        [$pdo, $reconciler, $request] = $this->reconciliationFixture();
        $finding = static fn(string $node, ?string $target, string $message, string $path = 'src/CheckoutService.php'): TestFinding => new TestFinding(
            $node,
            $target,
            FindingCategory::Tautology,
            FindingConfidence::Warning,
            'test.fidelity.tautology.v1',
            'mock_only',
            $message,
            new Evidence($path, 7, 9),
        );
        $request = new FullScanRequest(
            $request->projectIdentity,
            $request->projectName,
            $request->discovery,
            $request->scanners,
            $request->contributions,
            testFindings: [
                $finding('php:class:Fixture\\CheckoutService', 'php:class:Vendor\\Gone', 'kept without a target'),
                $finding('php:class:Vendor\\Gone', null, 'dropped: no such double'),
                $finding('php:class:Fixture\\CheckoutService', null, 'dropped: evidence outside the scan', 'src/Gone.php'),
            ],
        );

        $reconciler->reconcile($request);

        assertSame(
            [['message' => 'kept without a target', 'target_node_id' => null]],
            $pdo->query('SELECT message, target_node_id FROM test_findings')->fetchAll(PDO::FETCH_ASSOC),
        );
        assertSame(
            ['reconciler.test_finding_unresolved' => 3],
            array_map('intval', $pdo->query("SELECT code, COUNT(*) FROM diagnostics WHERE code = 'reconciler.test_finding_unresolved' GROUP BY code")->fetchAll(PDO::FETCH_KEY_PAIR)),
        );
    }

    public function testAddingARuleRebuildsAnUnchangedTree(): void
    {
        [$pdo, , $root] = $this->scanned(new TestFidelityAnalysis());
        $this->backdateDirectories($root, 10);
        assertSame([], self::findings($pdo));

        $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root], testFidelity: new TestFidelityAnalysis(self::ghostRule())))->scan($root);

        assertArrayNotHasKey('fast_path', $result->data);
        assertSame(1, count(self::findings($pdo)));
    }
}
