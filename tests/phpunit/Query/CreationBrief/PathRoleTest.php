<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\CreationBrief;

use Knossos\Query\CreationBrief\PathRole;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The paths a learnt test-path pattern could produce, as the role rule matches them. */
#[Group('query')]
final class PathRoleTest extends KnossosTestCase
{
    public function testADirectoryHoleAndANameHoleMatchAnyFolderAndAnyNonEmptyName(): void
    {
        $regex = PathRole::regex('<dir>/<name>Test.php');

        self::assertSame(1, preg_match($regex, 'a/b/FooTest.php'));
        self::assertSame(1, preg_match($regex, 'FooTest.php'), 'the root has no directory to hold');
        self::assertSame(0, preg_match($regex, 'a/Foo.php'));
        self::assertSame(0, preg_match($regex, 'a/Test.php'), 'the name hole is not empty');
        self::assertSame(0, preg_match($regex, 'a/FooTestXphp'), 'dots are literal');
    }

    public function testALiteralDirectoryPatternMatchesOnlyThatDirectory(): void
    {
        $regex = PathRole::regex('tests/a/<name>.spec.ts');

        self::assertSame(1, preg_match($regex, 'tests/a/x.spec.ts'));
        self::assertSame(0, preg_match($regex, 'tests/b/x.spec.ts'));
        self::assertSame(0, preg_match($regex, 'tests/a/deep/x.spec.ts'), 'a name has no slash');
    }

    /** A `<dir>` not followed by a slash is not a hole: patterns are never learnt that way, so it stays literal. */
    public function testADirWithoutASlashIsLiteral(): void
    {
        $regex = PathRole::regex('<dir><name>.php');

        self::assertSame(1, preg_match($regex, '<dir>x.php'));
        self::assertSame(0, preg_match($regex, 'src/x.php'));
    }
}
