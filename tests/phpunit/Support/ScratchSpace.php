<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\AfterClass;
use PHPUnit\Framework\Attributes\Before;

/**
 * Gives a test class temporary paths that are removed after the test.
 *
 * KnossosTestCase uses this, so every test that extends it has
 * scratchPath(), scratchDirectory() and scratchFile(); a class that extends
 * PHPUnit's TestCase directly uses the trait itself. See Scratch for where
 * the paths live and when they go.
 *
 * The hooks run first before a test and last after it: before setUp(), so a
 * path setUp() asks for belongs to the test, and after tearDown(), so
 * tearDown() can still stop a process or close a database that uses one.
 * PHPUnit runs after-test hooks when the test failed, errored or was skipped
 * part way through as well, which is what a cleanup at the end of the test
 * body, or in a `finally` the test never reached, did not do.
 */
trait ScratchSpace
{
    /** A fresh path under this test's scratch root that nothing has created yet. */
    public static function scratchPath(string $prefix = 'scratch'): string
    {
        return Scratch::path($prefix, static::class);
    }

    /** A fresh, empty directory under this test's scratch root. */
    public static function scratchDirectory(string $prefix = 'scratch'): string
    {
        return Scratch::directory($prefix, static::class);
    }

    /** A fresh, empty file under this test's scratch root, the way tempnam() makes one. */
    public static function scratchFile(string $prefix = 'scratch'): string
    {
        return Scratch::file($prefix, static::class);
    }

    /** Opens this test's scratch root, before any other set-up. */
    #[Before(PHP_INT_MAX)]
    public function enterScratchOfTest(): void
    {
        Scratch::enterTest(static::class);
    }

    /** Removes this test's scratch root, after every other after-test hook, tearDown() included. */
    #[After(PHP_INT_MIN)]
    public function removeScratchOfTest(): void
    {
        Scratch::leaveTest();
    }

    /** Removes the root of paths asked for outside a test, after the class's last test. */
    #[AfterClass(PHP_INT_MIN)]
    public static function removeScratchOfClass(): void
    {
        Scratch::leaveClass(static::class);
    }
}
