<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\TestFidelity\ActiveTestFindings;
use Knossos\TestFidelity\TestFindingSuppressions;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The stored-findings reader's `doubles` filter (G6-08c): `verify_test_doubles`
 * asks for the findings of the doubles of one type, and the store answers
 * that, not every finding the project holds for PHP to sift.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class ActiveTestFindingsTest extends KnossosTestCase
{
    public function testTheDoublesFilterKeepsTheirFindingsOnly(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('test-fidelity/ts-audit');
        try {
            $findings = new ActiveTestFindings($pdo, $projectId, new TestFindingSuppressions([]));
            $all = $findings->find();
            self::assertCount(3, $all);
            $ghost = array_values(array_filter($all, static fn(array $finding): bool => $finding['category'] === 'GHOST_MEMBER'))[0]['double_id'];

            self::assertSame([$ghost], array_column($findings->find(['doubles' => [$ghost]]), 'double_id'));
            self::assertSame(1, $findings->count(['doubles' => [$ghost]]));
            // None asked for, none given; one of no finding, none either.
            self::assertSame([], $findings->find(['doubles' => []]));
            self::assertSame([], $findings->find(['doubles' => ['no-such-double']]));
            // Beside another filter, both hold.
            self::assertSame([], $findings->find(['doubles' => [$ghost], 'category' => 'TAUTOLOGY']));
        } finally {
            $this->removeTempTree($root);
        }
    }
}
