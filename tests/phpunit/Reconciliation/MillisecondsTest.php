<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use Knossos\Reconciliation\Milliseconds;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The stage and phase timings every scan reports. Fixed nanosecond spans pin
 * the divisor and the precision exactly, which a live clock never could.
 */
#[Group('reconciliation')]
final class MillisecondsTest extends TestCase
{
    public function testOneSecondIsExactlyOneThousandMilliseconds(): void
    {
        // A divisor one off in either direction lands on 999.999 or 1000.001.
        self::assertSame(1000.0, Milliseconds::fromNanoseconds(1_000_000_000));
    }

    public function testRoundsToTheMicrosecond(): void
    {
        // Three decimals, rounded half up: not 1.2346 or 1.23, and not
        // truncated to 1.234 or raised to the ceiling's 1.235 on the second.
        self::assertSame(1.235, Milliseconds::fromNanoseconds(1_234_567));
        self::assertSame(1.234, Milliseconds::fromNanoseconds(1_234_467));
    }

    public function testAcceptsTheFloatHrtimeReturnsOnThirtyTwoBitBuilds(): void
    {
        self::assertSame(2.5, Milliseconds::fromNanoseconds(2_500_000.0));
    }

    public function testMeasuresForwardFromTheMark(): void
    {
        $mark = hrtime(true);
        usleep(2000);
        $elapsed = Milliseconds::since($mark);

        // Adding the mark instead of subtracting it reads as days, and a
        // reversed subtraction reads as negative.
        self::assertGreaterThanOrEqual(2.0, $elapsed);
        self::assertLessThan(60_000.0, $elapsed);
    }
}
