<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\ScannerClient;
use Knossos\Scanner\Worker\ScannerProtocolSession;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scanner-client')]
final class ScannerClientTest extends TestCase
{
    public function testInterfaceExists(): void
    {
        $reflection = new \ReflectionClass(ScannerClient::class);
        $this->assertTrue($reflection->isInterface());
    }

    public function testDeclaresRequiredMethods(): void
    {
        $reflection = new \ReflectionClass(ScannerClient::class);
        $methods = array_map(
            static fn(\ReflectionMethod $m) => $m->getName(),
            $reflection->getMethods(),
        );

        assertArrayContains('initialize', $methods);
        assertArrayContains('scan', $methods);
        assertArrayContains('shutdown', $methods);
    }

    public function testScannerClientDeclaresNoDiscoverMethod(): void
    {
        // The scan pipeline discovers files itself; the worker-side discover was
        // three implementations with no caller.
        self::assertFalse(method_exists(ScannerClient::class, 'discover'));
    }

    public function testScannerClientDeclaresNoCancelMethod(): void
    {
        // A scan is cancelled through the callback scan() takes; the client's
        // own cancel had no caller outside a test.
        self::assertFalse(method_exists(ScannerClient::class, 'cancel'));
    }

    public function testTheSessionCancelAcceptsAnIntegerRequestId(): void
    {
        // The session sends the id verbatim: an int scan id must not be
        // stringified, or a type-strict worker never matches it.
        $parameter = (new \ReflectionMethod(ScannerProtocolSession::class, 'cancel'))->getParameters()[0];
        self::assertStringContainsString('int', (string) $parameter->getType());
    }
}
