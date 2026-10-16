<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Watch;

use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Watch\SessionLink;
use PHPUnit\Framework\Attributes\Group;

/** How a shared watcher knows the session that started it, on the host and in a container. */
final class SessionLinkTest extends KnossosTestCase
{
    /** On the host the watcher is its own process, and the session is its parent. */
    #[Group('watch')]
    public function testOnTheHostTheWatcherNamesItselfAndWatchesItsParent(): void
    {
        $link = SessionLink::fromEnvironment([], STDIN);

        self::assertNull($link->reportedPid);
        if (function_exists('posix_getppid')) {
            self::assertNotNull($link->alive);
            self::assertTrue(($link->alive)());
        }
    }

    /**
     * In a container the parent is the container's init process, which never
     * changes. The docker client holds the watcher's stdin instead, so the
     * session is gone when stdin closes.
     */
    #[Group('watch')]
    public function testInAContainerTheSessionIsGoneWhenStdinCloses(): void
    {
        [$read, $write] = self::pipe();
        $link = SessionLink::fromEnvironment(['KNOSSOS_WATCH_PID' => '4242', 'KNOSSOS_WATCH_ALIVE' => 'stdin'], $read);

        self::assertSame(4242, $link->reportedPid);
        self::assertNotNull($link->alive);
        self::assertTrue(($link->alive)());
        fwrite($write, "ignored\n");
        self::assertTrue(($link->alive)(), 'Input on stdin is drained, not taken for the end.');
        fclose($write);
        self::assertFalse(($link->alive)());
    }

    /**
     * A stdin that is closed from the start (the session spawned the hook with
     * none) says nothing about the session, so it is not taken for its end:
     * the watcher then runs until it is signalled.
     */
    #[Group('watch')]
    public function testAStdinClosedFromTheStartIsNoSignOfAnything(): void
    {
        [$read, $write] = self::pipe();
        fclose($write);

        self::assertNull(SessionLink::fromEnvironment(['KNOSSOS_WATCH_ALIVE' => 'stdin'], $read)->alive);
    }

    /** Anything but a plain positive number is no process id. */
    #[Group('watch')]
    public function testAPidThatIsNotAPlainNumberIsIgnored(): void
    {
        foreach (['', '0', '1', '-5', '12a', ' 7'] as $value) {
            self::assertNull(SessionLink::fromEnvironment(['KNOSSOS_WATCH_PID' => $value], STDIN)->reportedPid, var_export($value, true));
        }
    }

    /** @return array{resource, resource} */
    private static function pipe(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        return [$pair[0], $pair[1]];
    }
}
