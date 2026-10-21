<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\DiscoveryException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('discovery-exception')]
final class DiscoveryExceptionTest extends TestCase
{
    public function testExtendsRuntimeException(): void
    {
        $reflection = new \ReflectionClass(DiscoveryException::class);

        $this->assertTrue($reflection->isSubclassOf(RuntimeException::class));
    }

    public function testIsFinalNowThatRootRefusalsHaveTheirOwnType(): void
    {
        // Root refusals have their own type in Roots, so nothing narrows this
        // one any more.
        $this->assertTrue((new \ReflectionClass(DiscoveryException::class))->isFinal());
    }

    public function testCanBeThrownWithMessageAndConstructedFromPrevious(): void
    {
        $previous = new \LogicException('boom');

        $error = captureThrows(
            static fn() => throw new DiscoveryException('wrap', 7, $previous),
            DiscoveryException::class,
        );

        assertSame('wrap', $error->getMessage());
        assertSame(7, $error->getCode());
        assertSame($previous, $error->getPrevious());
    }

    public function testCanBeCaughtAsRuntimeException(): void
    {
        $caught = null;

        try {
            throw new DiscoveryException('discovery failed');
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        assertSame('discovery failed', $caught->getMessage());
        assertSame('Knossos\\Discovery\\DiscoveryException', $caught::class);
    }
}
