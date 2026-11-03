<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Scanner\Worker\WorkerException;
use Knossos\Store\ScanBusyException;
use Throwable;

/**
 * What kind of failure ended a scan, and how to word it for a person.
 *
 * `killed`, `lock`, `worker` and `other` are told apart here from the
 * exception. `timed-out` and `brief-timeout` are only known to the shell
 * wrappers, which see a process that ran too long, and reach the log through
 * `knossos record-scan-failure`.
 */
final class ScanFailureClass
{
    public const KILLED = 'killed';
    public const LOCK = 'lock';
    public const WORKER = 'worker';
    public const OTHER = 'other';
    public const TIMED_OUT = 'timed-out';
    public const BRIEF_TIMEOUT = 'brief-timeout';

    /** Every class the log accepts. */
    public const ALL = [self::KILLED, self::LOCK, self::WORKER, self::OTHER, self::TIMED_OUT, self::BRIEF_TIMEOUT];

    /** The class of `$failure`: a signal-ended worker is killed, any other worker fault a worker failure. */
    public static function of(Throwable $failure): string
    {
        return match (true) {
            $failure instanceof ScanBusyException => self::LOCK,
            $failure instanceof WorkerException => $failure->terminatingSignal !== null ? self::KILLED : self::WORKER,
            default => self::OTHER,
        };
    }

    /** `$message` without the project id the lock names: a 70-character id leaves no room for the rest in a pane header. */
    private static function withoutProjectId(string $message): string
    {
        return (string) preg_replace(['/ for project project_[0-9a-f]+/', '/ \(project project_[0-9a-f]+\)/'], '', $message);
    }

    /**
     * Whether `$signal` is one a host memory guard sends: SIGKILL from the kernel, SIGTERM from earlyoom and its kind.
     * A scan ended by one is worth another try; any other signal is somebody's decision.
     */
    public static function isMemoryGuard(?int $signal): bool
    {
        return $signal === null || $signal === 9 || $signal === 15;
    }

    /**
     * What to tell the person for a failure of `$class`.
     *
     * A scan ended by SIGKILL or SIGTERM says what usually sends those and no
     * more: the signal came from outside the scan, and which of several things
     * sent it is not known. Any other signal is named, since low memory is no
     * likelier an explanation for it than anything else.
     */
    public static function wording(string $class, string $message, ?int $signal): string
    {
        return match ($class) {
            self::KILLED => self::isMemoryGuard($signal) ? 'Stopped by the system, probably low on memory' : sprintf('Ended by signal %d', $signal),
            self::LOCK => 'Another scan is using the graph; ' . self::withoutProjectId($message),
            self::TIMED_OUT => 'Took longer than 5 minutes',
            default => $message,
        };
    }
}
