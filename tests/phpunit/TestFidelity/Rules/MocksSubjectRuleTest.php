<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Rules;

use Knossos\Scanner\Protocol\NodeFact;
use Knossos\TestFidelity\FindingCategory;
use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\Rules\MocksSubjectRule;
use Knossos\TestFidelity\TestFinding;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

#[Group('test-fidelity')]
final class MocksSubjectRuleTest extends KnossosTestCase
{
    private const MODULE_MOCK = ['kind' => 'module', 'specifier' => '../src/db'];

    public function testMockingTheModuleUnderTestWarns(): void
    {
        $db = DriftFixtures::node('module', 'src/db.ts', 'src/db.ts');
        $double = CaseFixtures::double(3, 'vi.mock', self::MODULE_MOCK);

        $findings = self::judge([CaseFixtures::testCase([], 2, ['src/db.ts'])], $double, $db);

        assertCount(1, $findings);
        assertSame(FindingCategory::MocksSubject, $findings[0]->category);
        assertSame(FindingConfidence::Warning, $findings[0]->confidence);
        assertSame('test.fidelity.mocks_subject.v1', $findings[0]->ruleId);
        assertSame('mock_of_self', $findings[0]->subRule);
        assertSame("vi.mock replaces src/db.ts, which the test 'does a thing' exercises", $findings[0]->message);
        assertSame($double->localId, $findings[0]->doubleOrCaseReference);
        assertSame($db->localId, $findings[0]->targetReference);
        assertSame([CaseFixtures::FILE, 3], [$findings[0]->evidence->relativePath, $findings[0]->evidence->startLine]);
        assertSame(['module' => 'src/db.ts', 'test_case' => CaseFixtures::FILE . '#test:10'], $findings[0]->attributes);
    }

    public function testMockingAModuleTheTestDoesNotExerciseIsSilent(): void
    {
        $db = DriftFixtures::node('module', 'src/db.ts', 'src/db.ts');

        assertSame([], self::judge([CaseFixtures::testCase([], 2, ['src/ledger.ts'])], CaseFixtures::double(3, 'vi.mock', self::MODULE_MOCK), $db));
        // A test in another file exercising the module says nothing of this file's mock.
        assertSame([], self::judge([CaseFixtures::testCase([], 2, ['src/db.ts'], 10, 'tests/b.test.ts')], CaseFixtures::double(3, 'vi.mock', self::MODULE_MOCK), $db));
    }

    public function testMockingADependencyTheTestAlsoBuildsIsSilent(): void
    {
        // `ledger.test.ts` mocks `db` wholesale and builds `new Db()` to hand the ledger: its subject is the ledger.
        $db = DriftFixtures::node('module', 'src/db.ts', 'src/db.ts');
        $file = 'tests/ledger.test.ts';

        assertSame([], self::judge([CaseFixtures::testCase([], 2, ['src/db.ts', 'src/ledger.ts'], 10, $file)], CaseFixtures::double(3, 'vi.mock', self::MODULE_MOCK, file: $file), $db));
    }

    public function testAmongSeveralSubjectsTheOneTheFileIsNamedForCounts(): void
    {
        $db = DriftFixtures::node('module', 'src/db.ts', 'src/db.ts');
        $file = 'tests/db.spec.tsx';

        $findings = self::judge([CaseFixtures::testCase([], 2, ['src/db.ts', 'src/ledger.ts'], 10, $file)], CaseFixtures::double(3, 'vi.mock', self::MODULE_MOCK, file: $file), $db);

        assertCount(1, $findings);
        assertSame(['module' => 'src/db.ts', 'test_case' => $file . '#test:10'], $findings[0]->attributes);
        // Named for the module, but its calls never land there.
        assertSame([], self::judge([CaseFixtures::testCase([], 2, ['src/ledger.ts'], 10, $file)], CaseFixtures::double(3, 'vi.mock', self::MODULE_MOCK, file: $file), $db));
    }

    public function testAManualMockSubjectDoesNotCrowdTheRealOne(): void
    {
        $db = DriftFixtures::node('module', 'src/db.ts', 'src/db.ts');

        assertCount(1, self::judge([CaseFixtures::testCase([], 2, ['src/__mocks__/http.ts', 'src/db.ts'])], CaseFixtures::double(3, 'vi.mock', self::MODULE_MOCK), $db));
    }

    public function testADoubleMadeInATestIsJudgedByThatTestAlone(): void
    {
        $db = DriftFixtures::node('module', 'src/db.ts', 'src/db.ts');
        $tests = [CaseFixtures::testCase([], 2, ['src/db.ts'], 10), CaseFixtures::testCase([], 2, ['src/ledger.ts'], 20)];

        assertSame([], self::judge($tests, CaseFixtures::double(22, 'jest.doMock', self::MODULE_MOCK, testCase: CaseFixtures::FILE . '#test:20'), $db));
        assertCount(1, self::judge($tests, CaseFixtures::double(12, 'jest.doMock', self::MODULE_MOCK, testCase: CaseFixtures::FILE . '#test:10'), $db));
    }

    public function testAFileLevelMockNamesTheFirstTestItCatches(): void
    {
        $db = DriftFixtures::node('module', 'src/db.ts', 'src/db.ts');
        $tests = [CaseFixtures::testCase([], 1, ['src/ledger.ts'], 10), CaseFixtures::testCase([], 1, ['src/db.ts', 'src/ledger.ts'], 30), CaseFixtures::testCase([], 1, ['src/db.ts'], 20)];

        $findings = self::judge($tests, CaseFixtures::double(3, 'vi.mock', self::MODULE_MOCK), $db);

        assertCount(1, $findings);
        assertSame(CaseFixtures::FILE . '#test:20', $findings[0]->attributes['test_case']);
    }

    public function testASpyOnTheSubjectIsAPartialMockAndSilent(): void
    {
        $class = DriftFixtures::node('class', 'src/db.ts#Db', 'Db');
        $module = DriftFixtures::node('module', 'src/db.ts', 'src/db.ts');
        $test = [CaseFixtures::testCase([], 2, ['src/db.ts'])];

        assertSame([], self::judge($test, CaseFixtures::double(3, 'vi.spyOn', ['kind' => 'instance-member', 'export' => 'Db', 'member' => 'query']), $class));
        assertSame([], self::judge($test, CaseFixtures::double(3, 'vi.spyOn', ['kind' => 'module', 'specifier' => '../src/db', 'member' => 'query']), $module));
        assertSame([], self::judge($test, CaseFixtures::double(3, 'vi.mocked-instance', ['kind' => 'class', 'export' => 'Db']), $class));
    }

    public function testManualMockInMocksDirectoryIsNotTheSubject(): void
    {
        // Jest resolves `jest.mock('../src/db')` to the manual mock beside it; that stand-in is never the module under test.
        $manual = DriftFixtures::node('module', 'src/__mocks__/db.ts', 'src/__mocks__/db.ts');

        assertSame([], self::judge([CaseFixtures::testCase([], 2, ['src/__mocks__/db.ts'])], CaseFixtures::double(3, 'jest.mock', self::MODULE_MOCK), $manual));
    }

    public function testADoubleDefinedInAManualMockIsNotJudged(): void
    {
        $db = DriftFixtures::node('module', 'src/db.ts', 'src/db.ts');
        $file = 'src/__mocks__/db.ts';

        assertSame([], self::judge(
            [CaseFixtures::testCase([], 1, ['src/db.ts'], 10, $file)],
            CaseFixtures::double(3, 'jest.mock', self::MODULE_MOCK, file: $file),
            $db,
        ));
    }

    public function testATargetOutsideTheScanOrTheProjectIsSilent(): void
    {
        $external = DriftFixtures::node('module', 'src/db.ts', 'src/db.ts', ['external' => true]);
        $test = [CaseFixtures::testCase([], 2, ['src/db.ts'])];

        assertSame([], self::judge($test, CaseFixtures::double(3, 'vi.mock', self::MODULE_MOCK), $external));
        assertSame([], self::judge($test, CaseFixtures::double(3, 'vi.mock', self::MODULE_MOCK)));
    }

    public function testASkippedDoubleDoesNotHideTheNextOne(): void
    {
        $db = DriftFixtures::node('module', 'src/db.ts', 'src/db.ts');
        $spy = CaseFixtures::double(2, 'vi.spyOn', ['kind' => 'module', 'specifier' => '../src/db', 'member' => 'query']);
        $mock = CaseFixtures::double(3, 'vi.mock', self::MODULE_MOCK);
        $manual = CaseFixtures::double(1, 'jest.mock', self::MODULE_MOCK, file: 'src/__mocks__/db.ts');
        $graph = CaseFixtures::graph(
            [CaseFixtures::testCase([], 2, ['src/db.ts']), CaseFixtures::testCase([], 2, ['src/db.ts'], 9, 'src/__mocks__/db.ts'), $manual, $spy, $mock],
            [$db],
            [DriftFixtures::edge('doubles', $manual, $db->localId), DriftFixtures::edge('doubles', $spy, $db->localId), DriftFixtures::edge('doubles', $mock, $db->localId)],
        );

        $findings = [...(new MocksSubjectRule())->findings($graph)];

        assertSame([$mock->localId], array_map(static fn(TestFinding $finding): string => $finding->doubleOrCaseReference, $findings));
    }

    public function testIdNamesTheCategory(): void
    {
        assertSame('test.fidelity.mocks_subject.v1', (new MocksSubjectRule())->id());
    }

    /**
     * The rule's findings over test cases and one double whose `doubles` edge points at `$target`.
     *
     * @param list<NodeFact> $tests
     * @return list<TestFinding>
     */
    private static function judge(array $tests, NodeFact $double, ?NodeFact $target = null): array
    {
        $graph = CaseFixtures::graph([...$tests, $double], $target === null ? [] : [$target], $target === null ? [] : [DriftFixtures::edge('doubles', $double, $target->localId)]);

        return [...(new MocksSubjectRule())->findings($graph)];
    }
}
