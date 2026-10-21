<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery\Manifest;

use Knossos\Discovery\Manifest\ManifestPaths;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** The path helpers manifest readers share. */
#[Group('discovery')]
final class ManifestPathsTest extends TestCase
{
    public function testLayoutPathKeepsARelativeDirectory(): void
    {
        self::assertSame('src', ManifestPaths::layoutPath('./src/'));
        self::assertSame('src/lib', ManifestPaths::layoutPath('src\\lib'));
        self::assertSame('', ManifestPaths::layoutPath('.'));
        self::assertNull(ManifestPaths::layoutPath(42));
    }

    /** A drive letter, a leading backslash and a NUL byte once passed as relative directories. */
    public function testLayoutPathRefusesADirectoryOutsideTheProject(): void
    {
        foreach (['/abs', '../out', 'src/../../out', 'C:/src', '\\abs', "sr\0c"] as $value) {
            self::assertNull(ManifestPaths::layoutPath($value), $value);
        }
    }

    public function testWithinProjectFoldsDotSegments(): void
    {
        self::assertSame('src/Handler', ManifestPaths::withinProject('config/../src/./Handler/'));
        self::assertNull(ManifestPaths::withinProject('config/../../src'));
        self::assertNull(ManifestPaths::withinProject('./'));
    }
}
