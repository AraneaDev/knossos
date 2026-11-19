<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\TestFidelity\TestFindingSuppressions;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/** What a project's `test_finding_suppressions` cover, in PHP and in SQL. */
#[Group('test-fidelity')]
final class TestFindingSuppressionsTest extends KnossosTestCase
{
    private const GHOST = 'test.fidelity.ghost_member.v1';

    public function testEachRuleFormMatches(): void
    {
        $category = $this->suppressions([['rule' => 'GHOST_MEMBER']]);
        assertTrue($category->suppresses(self::GHOST, 'GHOST_MEMBER', 'missing_member', 'tests/a.test.ts'));
        assertFalse($category->suppresses('test.fidelity.tautology.v1', 'TAUTOLOGY', 'mock_echo', 'tests/a.test.ts'));

        $sub = $this->suppressions([['rule' => 'GHOST_MEMBER/missing_member']]);
        assertTrue($sub->suppresses(self::GHOST, 'GHOST_MEMBER', 'missing_member', 'tests/a.test.ts'));
        assertFalse($sub->suppresses(self::GHOST, 'GHOST_MEMBER', 'missing_export', 'tests/a.test.ts'));

        $full = $this->suppressions([['rule' => self::GHOST]]);
        assertTrue($full->suppresses(self::GHOST, 'GHOST_MEMBER', 'missing_member', 'tests/a.test.ts'));
        assertFalse($full->suppresses('test.fidelity.ghost_member.v2', 'GHOST_MEMBER', 'missing_member', 'tests/a.test.ts'));

        // Case-sensitive.
        assertFalse($this->suppressions([['rule' => 'ghost_member']])->suppresses(self::GHOST, 'GHOST_MEMBER', 'missing_member', 'tests/a.test.ts'));
    }

    public function testAPathIsAPlainPrefix(): void
    {
        $scoped = $this->suppressions([['rule' => 'GHOST_MEMBER', 'path' => 'tests/legacy/']]);
        assertTrue($scoped->suppresses(self::GHOST, 'GHOST_MEMBER', 'missing_member', 'tests/legacy/a.test.ts'));
        assertFalse($scoped->suppresses(self::GHOST, 'GHOST_MEMBER', 'missing_member', 'tests/modern/a.test.ts'));
    }

    public function testAMissingOrInvalidConfigurationSuppressesNothing(): void
    {
        $root = self::scratchPath('knossos-suppress');
        mkdir($root, 0755, true);
        try {
            $none = TestFindingSuppressions::fromProjectRoot($root);
            assertNull($none->invalid());
            assertFalse($none->suppresses(self::GHOST, 'GHOST_MEMBER', 'missing_member', 'a'));

            file_put_contents($root . '/knossos.json', json_encode(['version' => 1, 'test_finding_suppressions' => [['rule' => '']]]));
            $bad = TestFindingSuppressions::fromProjectRoot($root);
            assertSame('PROJECT_CONFIG_INVALID: test_finding_suppressions entries need a rule.', $bad->invalid());
            assertFalse($bad->suppresses(self::GHOST, 'GHOST_MEMBER', 'missing_member', 'a'));

            file_put_contents($root . '/knossos.json', json_encode(['version' => 1, 'test_finding_suppressions' => [['rule' => 'TAUTOLOGY', 'path' => 'x/']]]));
            $good = TestFindingSuppressions::fromProjectRoot($root);
            assertNull($good->invalid());
            assertTrue($good->suppresses('test.fidelity.tautology.v1', 'TAUTOLOGY', 'mock_echo', 'x/a.ts'));
        } finally {
            @unlink($root . '/knossos.json');
            @rmdir($root);
        }
    }

    public function testTheSqlConditionAgreesWithSuppresses(): void
    {
        $suppressions = $this->suppressions([
            ['rule' => 'TAUTOLOGY'],
            ['rule' => 'GHOST_MEMBER/missing_export', 'path' => 'tests/a'],
            ['rule' => 'test.fidelity.arity_mismatch.v1', 'path' => 'tests/legacy/'],
            ['rule' => 'RETURN_DRIFT', 'path' => 'tests/_'],
        ]);
        $rows = [];
        $id = 0;
        foreach ([
            ['test.fidelity.ghost_member.v1', 'GHOST_MEMBER', 'missing_member'],
            ['test.fidelity.ghost_member.v1', 'GHOST_MEMBER', 'missing_export'],
            ['test.fidelity.arity_mismatch.v1', 'ARITY_MISMATCH', 'extra_argument'],
            ['test.fidelity.tautology.v1', 'TAUTOLOGY', 'mock_echo'],
            ['test.fidelity.return_drift.v1', 'RETURN_DRIFT', 'wrong_type'],
        ] as [$rule, $category, $sub]) {
            foreach (['tests/a.test.ts', 'tests/b.test.ts', 'tests/legacy/c.test.ts', 'tests/_x.test.ts', 'Tests/a.test.ts', null] as $file) {
                $rows[] = [++$id, $rule, $category, $sub, $file];
            }
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE files (id INTEGER PRIMARY KEY, relative_path TEXT)');
        $pdo->exec('CREATE TABLE test_findings (id INTEGER PRIMARY KEY, rule_id TEXT, category TEXT, sub_rule TEXT, file_id INTEGER)');
        foreach ($rows as [$rowId, $rule, $category, $sub, $file]) {
            if ($file !== null) {
                $pdo->prepare('INSERT INTO files (id, relative_path) VALUES (?, ?)')->execute([$rowId, $file]);
            }
            $pdo->prepare('INSERT INTO test_findings (id, rule_id, category, sub_rule, file_id) VALUES (?, ?, ?, ?, ?)')
                ->execute([$rowId, $rule, $category, $sub, $file === null ? null : $rowId]);
        }

        [$condition, $values] = $suppressions->sqlCondition('tf');
        $statement = $pdo->prepare("SELECT tf.id FROM test_findings tf WHERE {$condition} ORDER BY tf.id");
        $statement->execute($values);
        $kept = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $expected = [];
        foreach ($rows as [$rowId, $rule, $category, $sub, $file]) {
            if (!$suppressions->suppresses($rule, $category, $sub, $file ?? '')) {
                $expected[] = $rowId;
            }
        }

        assertSame($expected, $kept);
        self::assertNotSame([], array_diff(array_column($rows, 0), $kept), 'the table must suppress something');
        self::assertNotSame([], $kept, 'the table must keep something');
        assertSame(['1 = 1', []], $this->suppressions([])->sqlCondition('tf'));
    }

    /** @param list<array<string, string>> $entries */
    private function suppressions(array $entries): TestFindingSuppressions
    {
        return new TestFindingSuppressions(array_map(
            static fn(array $entry): array => ['rule' => $entry['rule'], 'path' => $entry['path'] ?? null, 'reason' => null],
            $entries,
        ));
    }
}
