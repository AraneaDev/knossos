<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\ScanFailureClass;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Store\ScanBusyException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('scan')]
final class ScanFailureClassTest extends KnossosTestCase
{
    public function testClassifiesByTheException(): void
    {
        assertSame('lock', ScanFailureClass::of(new ScanBusyException('busy')));
        assertSame('killed', ScanFailureClass::of(new WorkerException('WORKER_EXITED', 'gone', terminatingSignal: 9)));
        assertSame('worker', ScanFailureClass::of(new WorkerException('WORKER_EXITED', 'gone', exitCode: 7)));
        assertSame('other', ScanFailureClass::of(new RuntimeException('no')));
    }

    public function testWordingFollowsTheTable(): void
    {
        assertSame('Stopped by the system, probably low on memory', ScanFailureClass::wording('killed', 'ignored', 9));
        assertSame('Another scan is using the graph; held 40s', ScanFailureClass::wording('lock', 'held 40s', null));
        assertSame('Took longer than 5 minutes', ScanFailureClass::wording('timed-out', '', null));
        assertSame('parse error', ScanFailureClass::wording('worker', 'parse error', null));
        assertSame('parse error', ScanFailureClass::wording('other', 'parse error', null));
        assertSame('The session brief did not finish in time.', ScanFailureClass::wording('brief-timeout', 'The session brief did not finish in time.', null));
    }
}
