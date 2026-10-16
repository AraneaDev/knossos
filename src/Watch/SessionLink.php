<?php

declare(strict_types=1);

namespace Knossos\Watch;

use Closure;

/**
 * How a shared watcher knows the session that started it.
 *
 * On the host the session is the watcher's parent: `knossos-run.sh` execs the
 * binary, so the process that spawned the shell becomes its parent, and the
 * watcher stops once it is re-parented. In a container neither holds. The
 * parent is the container's init process, which never changes, and the
 * watcher's own process id names some other process on the host. The
 * container hook therefore passes the docker client's id in
 * `KNOSSOS_WATCH_PID`, for the session to signal, and keeps the watcher's
 * stdin open through `docker run -i` with `KNOSSOS_WATCH_ALIVE=stdin`: the
 * session is gone when that stdin closes.
 */
final readonly class SessionLink
{
    /**
     * @param int|null $reportedPid the id the session should signal, or null for the watcher's own
     * @param (Closure(): bool)|null $alive whether the session is still there, or null when that cannot be told
     */
    private function __construct(public ?int $reportedPid, public ?Closure $alive) {}

    /**
     * How this watcher reaches its session: from a container, through the ids
     * the hook named and the stdin it holds open; elsewhere, through its parent.
     *
     * @param array<string, string> $environment
     * @param resource $stdin
     */
    public static function fromEnvironment(array $environment, $stdin): self
    {
        if (self::inContainer($environment)) {
            return new self(self::pid($environment['KNOSSOS_WATCH_PID'] ?? ''), self::untilClosed($stdin));
        }
        $parent = function_exists('posix_getppid') ? posix_getppid() : null;

        return new self(null, $parent === null ? null : static fn(): bool => posix_getppid() === $parent);
    }

    /**
     * The process that started this watcher, by which two watchers tell
     * whether one session started both: the hook names it in a container,
     * where every watcher's parent is init, and it is the parent elsewhere.
     *
     * @param array<string, string>|null $environment null for this process's own
     */
    public static function sessionProcess(?array $environment = null): ?int
    {
        $environment ??= getenv();
        if (self::inContainer($environment) && ($named = self::pid($environment['KNOSSOS_WATCH_SESSION'] ?? '')) !== null) {
            return $named;
        }

        return function_exists('posix_getppid') ? posix_getppid() : null;
    }

    /**
     * The id this watcher goes by where a session can see it: the docker
     * client's in a container, its own elsewhere.
     *
     * @param array<string, string>|null $environment null for this process's own
     */
    public static function ownProcess(?array $environment = null): int
    {
        $environment ??= getenv();
        $named = self::inContainer($environment) ? self::pid($environment['KNOSSOS_WATCH_PID'] ?? '') : null;

        return $named ?? (int) getmypid();
    }

    /**
     * Whether the container hook started this watcher. Only then are the
     * ids it hands over trusted: on the host the session would signal
     * whatever a stray variable named.
     *
     * @param array<string, string> $environment
     */
    private static function inContainer(array $environment): bool
    {
        return ($environment['KNOSSOS_WATCH_ALIVE'] ?? null) === 'stdin';
    }

    /** A plain process id above 1, or null. */
    private static function pid(string $value): ?int
    {
        return preg_match('/^[1-9]\d{0,9}$/D', $value) === 1 && (int) $value > 1 ? (int) $value : null;
    }

    /**
     * True until the stream reaches its end, or null when it is closed from
     * the start and so says nothing about the session. Whatever arrives on it
     * is read and dropped, so a client that writes cannot fill the pipe.
     *
     * @param resource $stream
     * @return (Closure(): bool)|null
     */
    private static function untilClosed($stream): ?Closure
    {
        stream_set_blocking($stream, false);
        $open = static function () use ($stream): bool {
            while (($chunk = fread($stream, 8192)) !== false && $chunk !== '') {
                // Drained: input is not a sign of life or death.
            }

            // A read that failed is as final as the end of the stream.
            return $chunk !== false && !feof($stream);
        };

        return $open() ? $open : null;
    }
}
