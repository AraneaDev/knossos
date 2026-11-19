<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\TestFidelity\TestFindingKey;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** The key a test finding is recognised by, across scans and line moves. */
#[Group('test-fidelity')]
final class TestFindingKeyTest extends TestCase
{
    private const RULE = 'test.fidelity.ghost_member.v1';

    public function testTheKeyIgnoresTheLine(): void
    {
        // The line is not an input: the same finding a few lines lower is the same key.
        $before = TestFindingKey::of(self::RULE, 'missing_member', 'tests/a.test.ts', 'Billing', 'double stubs charge, which Billing lacks');
        $after = TestFindingKey::of(self::RULE, 'missing_member', 'tests/a.test.ts', 'Billing', 'double stubs charge, which Billing lacks');

        assertSame($before, $after);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/D', $before);
    }

    public function testTheKeySeparatesMembers(): void
    {
        $charge = TestFindingKey::of(self::RULE, 'missing_member', 'tests/a.test.ts', 'Billing', 'double stubs charge, which Billing lacks');
        $refund = TestFindingKey::of(self::RULE, 'missing_member', 'tests/a.test.ts', 'Billing', 'double stubs refund, which Billing lacks');

        assertNotSame($charge, $refund);
    }

    public function testANullTargetKeysAsEmpty(): void
    {
        assertSame(
            TestFindingKey::of(self::RULE, 'missing_member', 'tests/a.test.ts', '', 'm'),
            TestFindingKey::of(self::RULE, 'missing_member', 'tests/a.test.ts', null, 'm'),
        );
        assertSame(sha1(self::RULE . '|missing_member|tests/a.test.ts||m'), TestFindingKey::of(self::RULE, 'missing_member', 'tests/a.test.ts', null, 'm'));
    }
}
