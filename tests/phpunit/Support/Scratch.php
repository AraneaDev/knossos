<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

use RuntimeException;

/**
 * Temporary paths that are removed after the test that asked for them.
 *
 * A test asks for a path here instead of building one from
 * sys_get_temp_dir(). Every such path lives under one scratch root per test,
 * which ScratchSpace's after-test hook removes once the test is over, whether
 * it passed, failed, errored or was skipped part way through. A path asked
 * for outside a test (in setUpBeforeClass() or a data provider) goes under a
 * root for its class, removed after the class's last test.
 *
 * Test classes reach this through ScratchSpace (scratchPath() and friends);
 * a support class that makes fixtures for a test calls it directly.
 */
final class Scratch
{
    /** The running test's class, or null between tests. */
    private static ?string $test = null;

    /** The running test's scratch root, made on first use. */
    private static ?string $testRoot = null;

    /** @var array<string, string> Scratch roots of paths asked for outside a test, by class. */
    private static array $classRoots = [];

    /**
     * A fresh path that nothing has created yet: the test makes it a file, a
     * directory or a database as it needs. The name keeps $prefix, so a
     * failure message still says which fixture it was.
     *
     * $owner is the class asking, used only when no test is running.
     */
    public static function path(string $prefix = 'scratch', string $owner = 'Unowned'): string
    {
        return self::root($owner) . '/' . $prefix . '-' . bin2hex(random_bytes(6));
    }

    /** A fresh, empty directory, readable only by its owner. */
    public static function directory(string $prefix = 'scratch', string $owner = 'Unowned'): string
    {
        $path = self::path($prefix, $owner);
        if (!@mkdir($path, 0o700)) {
            throw new RuntimeException('Unable to create the scratch directory ' . $path . '.');
        }

        return $path;
    }

    /** A fresh, empty file, the way tempnam() makes one. */
    public static function file(string $prefix = 'scratch', string $owner = 'Unowned'): string
    {
        $path = self::path($prefix, $owner);
        if (@file_put_contents($path, '') === false) {
            throw new RuntimeException('Unable to create the scratch file ' . $path . '.');
        }

        return $path;
    }

    /** Called before a test's set-up: what it asks for from now on goes under its own root. */
    public static function enterTest(string $class): void
    {
        self::$test = $class;
        self::$testRoot = null;
    }

    /** Called after a test's tear-down: removes everything it asked for. */
    public static function leaveTest(): void
    {
        if (self::$testRoot !== null) {
            self::remove(self::$testRoot);
        }
        self::$test = self::$testRoot = null;
    }

    /** Called after a class's last test: removes what was asked for outside its tests. */
    public static function leaveClass(string $class): void
    {
        if (isset(self::$classRoots[$class])) {
            self::remove(self::$classRoots[$class]);
            unset(self::$classRoots[$class]);
        }
    }

    /**
     * Removes $path and everything below it; a missing path is not an error.
     *
     * Three things defeat a plain recursive delete, and each left directories
     * in the temp directory for good: a link to a directory, which a delete
     * that follows it empties and which rmdir() refuses; a directory a test
     * made unreadable or unwritable to provoke a failure, whose entries cannot
     * be listed or unlinked until its permissions come back; and a read-only
     * file in such a directory. This walks the tree with lstat(), so a link is
     * only ever unlinked and never entered, and gives each directory back its
     * owner's permissions before reading it.
     */
    public static function remove(string $path): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            return;
        }
        if (($stat['mode'] & 0o170000) !== 0o040000) {
            @unlink($path);

            return;
        }
        @chmod($path, 0o700);
        foreach (@scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    /**
     * The root the next path goes under, made on first use and named after the
     * class that asked, so a root found left behind says which test made it.
     * It is a canonical path, so a test that compares a path it was given
     * with what realpath() makes of it sees the same string.
     */
    private static function root(string $owner): string
    {
        $class = self::$test ?? $owner;
        $existing = self::$test !== null ? self::$testRoot : (self::$classRoots[$class] ?? null);
        if ($existing !== null) {
            return $existing;
        }
        $short = substr((string) strrchr('\\' . $class, '\\'), 1);
        $root = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/' . $short . (self::$test !== null ? '-' : '-class-') . bin2hex(random_bytes(4));
        if (!@mkdir($root, 0o700)) {
            throw new RuntimeException('Unable to create the scratch root ' . $root . '.');
        }
        if (self::$test !== null) {
            self::$testRoot = $root;
        } else {
            self::$classRoots[$class] = $root;
        }

        return $root;
    }
}
