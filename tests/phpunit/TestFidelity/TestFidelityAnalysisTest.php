<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use InvalidArgumentException;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\TestFidelity\FindingCategory;
use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\TestFidelityAnalysis;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\TestFidelity\TestFidelityRule;
use Knossos\TestFidelity\TestFinding;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

#[Group('test-fidelity')]
final class TestFidelityAnalysisTest extends KnossosTestCase
{
    /**
     * A rule that reports exactly the findings it was given, whatever the graph holds.
     *
     * @param list<TestFinding> $findings
     */
    private static function rule(string $id, array $findings): TestFidelityRule
    {
        return new class ($id, $findings) implements TestFidelityRule {
            /** @param list<TestFinding> $findings */
            public function __construct(private readonly string $id, private readonly array $findings) {}

            public function id(): string
            {
                return $this->id;
            }

            public function findings(TestFidelityGraph $graph): iterable
            {
                yield from $this->findings;
            }
        };
    }

    /**
     * One contribution emitting a node under each reference, so findings that name them are kept.
     *
     * @return list<ScanContribution>
     */
    private static function emitting(string ...$references): array
    {
        return [new ScanContribution('knossos.typescript:file:a.test.ts', array_map(
            static fn(string $reference): NodeFact => new NodeFact($reference, 'test_double', substr($reference, 15), 'double', Origin::Ast, Confidence::Certain, new Evidence('a.test.ts', 1, 1)),
            $references,
        ))];
    }

    private static function finding(string $file, int $line, string $ruleId, FindingCategory $category = FindingCategory::Tautology): TestFinding
    {
        return new TestFinding(
            'ts:test_double:' . $file . '#double:' . $line . ':1',
            null,
            $category,
            FindingConfidence::Warning,
            $ruleId,
            'mock_only',
            'stub',
            new Evidence($file, $line, $line),
        );
    }

    public function testRunsEveryRuleAndSortsFindings(): void
    {
        $tautology = 'test.fidelity.tautology.v1';
        $ghost = 'test.fidelity.ghost_member.v1';
        $analysis = new TestFidelityAnalysis(
            self::rule($tautology, [self::finding('b.test.ts', 3, $tautology), self::finding('a.test.ts', 9, $tautology)]),
            self::rule($ghost, [
                self::finding('a.test.ts', 9, $ghost, FindingCategory::GhostMember),
                self::finding('a.test.ts', 10, $ghost, FindingCategory::GhostMember),
                self::finding('a.test.ts', 2, $ghost, FindingCategory::GhostMember),
            ]),
        );

        $order = array_map(
            static fn(TestFinding $finding): string => $finding->evidence->relativePath . ':' . $finding->evidence->startLine . ' ' . $finding->ruleId,
            $analysis->analyze(self::emitting(
                'ts:test_double:b.test.ts#double:3:1',
                'ts:test_double:a.test.ts#double:9:1',
                'ts:test_double:a.test.ts#double:10:1',
                'ts:test_double:a.test.ts#double:2:1',
            )),
        );

        // Line 10 after line 9: numeric, not string, order.
        assertSame([
            'a.test.ts:2 ' . $ghost,
            'a.test.ts:9 ' . $ghost,
            'a.test.ts:9 ' . $tautology,
            'a.test.ts:10 ' . $ghost,
            'b.test.ts:3 ' . $tautology,
        ], $order);
    }

    public function testFindingsNamingNodesOutsideTheScanAreDroppedOrDetachedWithAWarning(): void
    {
        $kept = self::finding('a.test.ts', 1, 'test.fidelity.tautology.v1');
        $detached = new TestFinding($kept->doubleOrCaseReference, 'ts:class:src/gone.ts#Gone', $kept->category, $kept->confidence, $kept->ruleId, $kept->subRule, 'detached', $kept->evidence);
        $dropped = self::finding('a.test.ts', 4, 'test.fidelity.tautology.v1');
        $report = (new TestFidelityAnalysis(self::rule('test.fidelity.tautology.v1', [$kept, $detached, $dropped])))
            ->report(self::emitting($kept->doubleOrCaseReference));

        assertSame(['detached', 'stub'], array_map(static fn(TestFinding $finding): string => $finding->message, $report->findings));
        assertNull($report->findings[0]->targetReference);
        assertSame([
            'TEST_FINDING_DROPPED: 1 test-fidelity finding(s) named a test double or test case not in the scan and were dropped (test.fidelity.tautology.v1).',
            'TEST_FINDING_TARGET_UNRESOLVED: 1 test-fidelity finding(s) named a target not in the scan and were kept without one (test.fidelity.tautology.v1).',
        ], $report->warnings);
    }

    public function testARuleThatThrowsOnABadFactLosesItsOutputWithAWarning(): void
    {
        $kept = self::finding('a.test.ts', 1, 'test.fidelity.tautology.v1');
        $throwing = new class implements TestFidelityRule {
            public function id(): string
            {
                return 'test.fidelity.ghost_member.v1';
            }

            public function findings(TestFidelityGraph $graph): iterable
            {
                yield new TestFinding('ts:test_double:a.test.ts#double:1:1', null, FindingCategory::GhostMember, FindingConfidence::Warning, 'test.fidelity.ghost_member.v1', 'missing_member', 'partial', new Evidence('a.test.ts', 1, 1));
                throw new InvalidArgumentException('bad worker text');
            }
        };

        $report = (new TestFidelityAnalysis($throwing, self::rule('test.fidelity.tautology.v1', [$kept])))
            ->report(self::emitting($kept->doubleOrCaseReference));

        assertSame(['stub'], array_map(static fn(TestFinding $finding): string => $finding->message, $report->findings));
        assertSame(['TEST_FINDING_RULE_FAILED: 1 test-fidelity rule(s) failed on this scan\'s facts and their findings were dropped (test.fidelity.ghost_member.v1).'], $report->warnings);
    }

    public function testACoreLogicErrorInARuleStillSurfaces(): void
    {
        $broken = new class implements TestFidelityRule {
            public function id(): string
            {
                return 'test.fidelity.ghost_member.v1';
            }

            public function findings(TestFidelityGraph $graph): iterable
            {
                throw new \DomainException('programming error');
            }
        };

        $this->expectException(\DomainException::class);
        (new TestFidelityAnalysis($broken))->report([]);
    }

    public function testRuleIdsAreSorted(): void
    {
        $analysis = new TestFidelityAnalysis(self::rule('test.fidelity.tautology.v1', []), self::rule('test.fidelity.ghost_member.v1', []));

        assertSame(['test.fidelity.ghost_member.v1', 'test.fidelity.tautology.v1'], $analysis->ruleIds());
    }

    public function testNoRulesFindNothing(): void
    {
        assertSame([], (new TestFidelityAnalysis())->analyze([]));
    }

    public function testAFindingNamesARuleOfTheConvention(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::finding('a.test.ts', 1, 'core.naming.roles.v1');
    }

    public function testAFindingsRuleNamesItsOwnCategory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::finding('a.test.ts', 1, 'test.fidelity.ghost_member.v1', FindingCategory::Tautology);
    }

    public function testConfidenceIsDefiniteOrWarning(): void
    {
        assertSame(['definite', 'warning'], array_map(static fn(FindingConfidence $case): string => $case->value, FindingConfidence::cases()));
        assertSame(
            ['GHOST_MEMBER', 'ARITY_MISMATCH', 'RETURN_DRIFT', 'VISIBILITY_BREACH', 'TAUTOLOGY', 'MOCKS_SUBJECT'],
            array_map(static fn(FindingCategory $case): string => $case->value, FindingCategory::cases()),
        );
    }
}
