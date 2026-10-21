<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Roots;

use Knossos\Roots\RootException;
use Knossos\Roots\RootNotFoundException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('roots')]
final class RootExceptionTest extends TestCase
{
    public function testIsARuntimeExceptionOpenOnlySoFarAsRootNotFoundExceptionNeeds(): void
    {
        // Not final, and the only class in Roots that is not: RootGuard has two
        // refusals to report and every caller catches this one type, so the
        // narrower one has to be reachable through it. The narrower one is
        // final, so the opening stops here rather than becoming a hierarchy.
        assertSame(RuntimeException::class, get_parent_class(RootException::class));
        $this->assertFalse((new \ReflectionClass(RootException::class))->isFinal());
        $this->assertTrue((new \ReflectionClass(RootNotFoundException::class))->isFinal());
        assertSame(RootException::class, get_parent_class(RootNotFoundException::class));
    }

    public function testARootNotFoundExceptionIsCaughtByEveryRootCatch(): void
    {
        $caught = null;

        try {
            throw new RootNotFoundException('no such directory');
        } catch (RootException $error) {
            $caught = $error;
        }

        assertSame('no such directory', $caught->getMessage());
        assertSame(RootNotFoundException::class, $caught::class);
    }
}
