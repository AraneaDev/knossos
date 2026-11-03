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

    /**
     * What to tell the person for a failure of `$class`.
     *
     * A killed scan says what usually kills one and no more: the signal came
     * from outside the scan, and which of several things sent it is not known.
     */
    public static function wording(string $class, string $message, ?int $signal): string
    {
        return match ($class) {
            self::KILLED => 'Stopped by the system, probably low on memory',
            self::LOCK => 'Another scan is using the graph; ' . $message,
            self::TIMED_OUT => 'Took longer than 5 minutes',
            default => $message,
        };
    }
}
