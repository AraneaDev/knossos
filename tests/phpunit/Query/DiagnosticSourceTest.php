<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\DiagnosticSource;
use Knossos\Reconciliation\EndpointRouteBinder;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final class DiagnosticSourceTest extends KnossosTestCase
{
    public function testTheSourceIsWhatPrecedesTheFirstColonOfTheOwnerKey(): void
    {
        assertSame('knossos.typescript', DiagnosticSource::of('knossos.typescript:file:src/a.ts'));
        assertSame('knossos.reconciliation', DiagnosticSource::of(EndpointRouteBinder::OWNER));
    }

    public function testAnOwnerKeyThatNamesNoSourceHasNone(): void
    {
        assertNull(DiagnosticSource::of(''));
        assertNull(DiagnosticSource::of(':file:x'));
    }
}
