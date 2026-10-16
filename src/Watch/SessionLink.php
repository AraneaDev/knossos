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
     * @param array<string, string> $environment
     * @param resource $stdin
     */
    public static function fromEnvironment(array $environment, $stdin): self
    {
        $pid = $environment['KNOSSOS_WATCH_PID'] ?? '';
        $reportedPid = preg_match('/^[1-9]\d{0,9}$/D', $pid) === 1 && (int) $pid > 1 ? (int) $pid : null;
        if (($environment['KNOSSOS_WATCH_ALIVE'] ?? null) === 'stdin') {
            return new self($reportedPid, self::untilClosed($stdin));
        }
        $parent = function_exists('posix_getppid') ? posix_getppid() : null;

        return new self($reportedPid, $parent === null ? null : static fn(): bool => posix_getppid() === $parent);
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

            return !feof($stream);
        };

        return $open() ? $open : null;
    }
}
