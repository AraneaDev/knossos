<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use InvalidArgumentException;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\TestFidelity\FindingCategory;
use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\TestFidelityAnalysis;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\TestFidelity\TestFidelityRule;
use Knossos\TestFidelity\TestFinding;
use Knossos\Tests\Phpunit\KnossosTestCase;

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
            $analysis->analyze([]),
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
