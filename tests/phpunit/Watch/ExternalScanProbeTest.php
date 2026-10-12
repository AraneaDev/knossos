<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Watch;

use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Watch\ExternalScanProbe;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

use function PHPUnit\Framework\assertSame;

/**
 * What the watcher says about scans another process runs, poll by poll: a
 * start once, a new start when another process takes the lease between two
 * polls, and an end once.
 */
final class ExternalScanProbeTest extends KnossosTestCase
{
    /**
     * The events a probe emits over polls that see these holders in turn.
     *
     * @param list<array{pid: ?int, held_seconds: int}|null|RuntimeException> $holders
     * @return list<array<string, mixed>>
     */
    private static function events(array $holders): array
    {
        $poll = 0;
        $probe = new ExternalScanProbe(static function () use (&$poll, $holders): ?array {
            $holder = $holders[$poll++];
            if ($holder instanceof RuntimeException) {
                throw $holder;
            }
            return $holder;
        });
        $events = [];
        foreach ($holders as $ignored) {
            $probe->poll(static function (array $event) use (&$events): void {
                $events[] = $event;
            });
        }

        return $events;
    }

    #[Group('watch')]
    public function testAStartAndAnEndAreEachSaidOnce(): void
    {
        $a = ['pid' => 11, 'held_seconds' => 0];
        assertSame(
            [['event' => 'external_scan', 'holder_pid' => 11, 'held_seconds' => 0], ['event' => 'external_scan_ended']],
            self::events([null, $a, ['pid' => 11, 'held_seconds' => 1], null, null]),
        );
    }

    /** One scan let go and another taken between two polls is a new start, naming the new holder. */
    #[Group('watch')]
    public function testAnotherHolderBetweenTwoPollsIsANewStart(): void
    {
        assertSame(
            [
                ['event' => 'external_scan', 'holder_pid' => 11, 'held_seconds' => 3],
                ['event' => 'external_scan', 'holder_pid' => 22, 'held_seconds' => 0],
                ['event' => 'external_scan_ended'],
            ],
            self::events([['pid' => 11, 'held_seconds' => 3], ['pid' => 22, 'held_seconds' => 0], null]),
        );
    }

    /** A database too busy to read says nothing new. */
    #[Group('watch')]
    public function testAFailedReadKeepsWhatWasSaid(): void
    {
        assertSame(
            [['event' => 'external_scan', 'holder_pid' => 11, 'held_seconds' => 0], ['event' => 'external_scan_ended']],
            self::events([['pid' => 11, 'held_seconds' => 0], new RuntimeException('busy'), null]),
        );
    }
}
