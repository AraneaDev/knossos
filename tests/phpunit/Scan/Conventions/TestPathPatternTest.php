<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan\Conventions;

use Knossos\Scan\Conventions\TestPathPattern;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The pattern that says where a new file's test goes, learnt from the pairs that exist.
 */
#[Group('scan')]
final class TestPathPatternTest extends KnossosTestCase
{
    /** Four pairs with one mirrored shape give that shape and its count. */
    public function testAMirroredTestTreeGivesOnePattern(): void
    {
        $pairs = array_map(
            static fn(string $name): array => ['source' => 'src/Query/' . $name . '.php', 'test' => 'tests/phpunit/Query/' . $name . 'Test.php'],
            ['A', 'B', 'C', 'D'],
        );

        self::assertSame(['pattern' => 'tests/phpunit/Query/<name>Test.php', 'files' => 4], TestPathPattern::learn($pairs));
    }

    /** A test beside its source is described with the directory hole, not a literal path. */
    public function testColocatedTestsUseTheDirectoryHole(): void
    {
        $pairs = [
            ['source' => 'web/a.ts', 'test' => 'web/a.spec.ts'],
            ['source' => 'web/b.ts', 'test' => 'web/b.spec.ts'],
        ];

        self::assertSame(['pattern' => '<dir>/<name>.spec.ts', 'files' => 2], TestPathPattern::learn($pairs));
    }

    /** Where shapes compete, the one most files follow wins. */
    public function testTheShapeWithMostFilesWins(): void
    {
        $pairs = [
            ['source' => 'src/A.php', 'test' => 'tests/ATest.php'],
            ['source' => 'src/B.php', 'test' => 'tests/BTest.php'],
            ['source' => 'src/C.php', 'test' => 'tests/CTest.php'],
            ['source' => 'src/D.php', 'test' => 'spec/D.spec.php'],
            ['source' => 'src/E.php', 'test' => 'spec/E.spec.php'],
        ];

        self::assertSame(['pattern' => 'tests/<name>Test.php', 'files' => 3], TestPathPattern::learn($pairs));
    }

    /** A pair whose test name does not contain the source name says nothing about the shape. */
    public function testUnrelatedNamesAreIgnored(): void
    {
        self::assertNull(TestPathPattern::learn([['source' => 'src/A.php', 'test' => 'tests/SmokeTest.php']]));
    }

    /** No pairs, no pattern. */
    public function testNothingToLearnFromGivesNull(): void
    {
        self::assertNull(TestPathPattern::learn([]));
    }

    /** Applying a pattern fills both holes from the source path. */
    public function testApplyFillsTheNameAndTheDirectory(): void
    {
        self::assertSame('tests/phpunit/Query/FooTest.php', TestPathPattern::apply('tests/phpunit/Query/<name>Test.php', 'src/Query/Foo.php'));
        self::assertSame('web/foo.spec.ts', TestPathPattern::apply('<dir>/<name>.spec.ts', 'web/foo.ts'));
        self::assertSame('foo.spec.ts', TestPathPattern::apply('<dir>/<name>.spec.ts', 'foo.ts'));
    }

    /** A file at the root has no directory to put in front of the name. */
    public function testRootFilesLearnWithoutADirectoryPrefix(): void
    {
        self::assertSame(['pattern' => '<name>_test.go', 'files' => 1], TestPathPattern::learn([['source' => 'main.go', 'test' => 'main_test.go']]));
    }
}
