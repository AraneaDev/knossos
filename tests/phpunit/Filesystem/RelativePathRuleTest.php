<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Filesystem;

use Knossos\Filesystem\RelativePathRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** A path stays inside the project unless it is absolute, carries a NUL or climbs out. */
#[Group('filesystem')]
final class RelativePathRuleTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function paths(): iterable
    {
        yield 'plain' => ['src/App.php', true];
        yield 'root' => ['', true];
        yield 'dot segments and trailing slash' => ['./src//lib/', true];
        yield 'dots inside a name' => ['src/..hidden/a..b', true];
        yield 'bare name with two dots' => ['a..b', true];
        yield 'backslash separators inside' => ['src\\lib', true];
        yield 'leading slash' => ['/etc/passwd', false];
        yield 'leading backslash' => ['\\etc\\passwd', false];
        yield 'backslash-led ignore pattern' => ['\\build', false];
        yield 'drive letter' => ['C:/Windows', false];
        yield 'drive-relative' => ['c:Windows', false];
        yield 'drive-relative glob' => ['x:*.log', false];
        yield 'parent segment' => ['src/../../etc', false];
        yield 'bare parent' => ['..', false];
        yield 'parent behind a backslash' => ['src\\..\\..\\etc', false];
        yield 'NUL byte' => ["src/a\0b", false];
    }

    #[DataProvider('paths')]
    public function testIsContained(string $path, bool $contained): void
    {
        self::assertSame($contained, RelativePathRule::isContained($path));
    }

    /** One leading `./` is dropped and nothing else is touched. */
    public function testWithoutDotSlash(): void
    {
        assertSame('a/b', RelativePathRule::withoutDotSlash('./a/b'));
        assertSame('./a', RelativePathRule::withoutDotSlash('././a'));
        assertSame('../a', RelativePathRule::withoutDotSlash('../a'));
        assertSame('.a', RelativePathRule::withoutDotSlash('.a'));
    }
}
