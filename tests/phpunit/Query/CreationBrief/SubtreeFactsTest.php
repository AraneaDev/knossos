<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\CreationBrief;

use Knossos\Query\CreationBrief\SubtreeFacts;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The facts of a directory and everything below it, added up from each folder's row. */
#[Group('creation-brief')]
final class SubtreeFactsTest extends KnossosTestCase
{
    /** Files, kinds and modifiers are summed per key. */
    public function testCountsAreSummed(): void
    {
        $merged = SubtreeFacts::merge([
            self::row(2, ['kinds' => ['one_class' => 2], 'modifiers' => ['final' => 2]]),
            self::row(3, ['kinds' => ['one_class' => 2, 'mixed' => 1], 'modifiers' => ['readonly' => 1, 'final' => 3]]),
            self::row(3, []),
        ]);

        self::assertSame(['files' => 8, 'facts' => ['kinds' => ['mixed' => 1, 'one_class' => 4], 'modifiers' => ['final' => 5, 'readonly' => 1]]], $merged);
    }

    /** The suffix and the name case most files have across the rows win, the name breaking a tie. */
    public function testTheValueWithTheMostFilesWinsAndTiesGoByName(): void
    {
        $merged = SubtreeFacts::merge([
            self::row(5, ['suffix' => ['value' => 'Service', 'files' => 3], 'name_case' => ['value' => 'pascal', 'files' => 4]]),
            self::row(5, ['suffix' => ['value' => 'Command', 'files' => 4], 'name_case' => ['value' => 'kebab', 'files' => 2]]),
            self::row(5, ['suffix' => ['value' => 'Service', 'files' => 1], 'name_case' => ['value' => 'camel', 'files' => 2]]),
        ]);

        self::assertSame(['value' => 'Command', 'files' => 4], $merged['facts']['suffix'], 'four and four: Command sorts first');
        self::assertSame(['value' => 'pascal', 'files' => 4], $merged['facts']['name_case']);
    }

    /** A `<dir>` test pattern merges across the rows; a literal test folder is one folder's and is dropped. */
    public function testOnlyDirectoryRelativeTestPatternsMerge(): void
    {
        $merged = SubtreeFacts::merge([
            self::row(3, ['test_path' => ['pattern' => 'tests/A/<name>Test.php', 'files' => 3]]),
            self::row(3, ['test_path' => ['pattern' => '<dir>/<name>.spec.ts', 'files' => 2]]),
            self::row(3, ['test_path' => ['pattern' => '<dir>/<name>.spec.ts', 'files' => 1]]),
        ]);
        $literal = SubtreeFacts::merge([self::row(5, ['test_path' => ['pattern' => 'tests/A/<name>Test.php', 'files' => 5]])]);

        self::assertSame(['pattern' => '<dir>/<name>.spec.ts', 'files' => 3], $merged['facts']['test_path']);
        self::assertSame(['files' => 5, 'facts' => []], $literal);
    }

    /** What each row keeps only its top of, and what does not add up, is not merged at all. */
    public function testUsesInjectsAndLinesAreLeftOut(): void
    {
        $merged = SubtreeFacts::merge([self::row(6, [
            'uses' => [['type' => 'A', 'files' => 6]],
            'injects' => [['type' => 'PDO', 'files' => 6]],
            'lines' => ['median' => 10, 'max' => 20],
        ])]);

        self::assertSame(['files' => 6, 'facts' => []], $merged);
    }

    /**
     * @param array<string, mixed> $facts
     * @return array{directory: string, files: int, facts: array<string, mixed>}
     */
    private static function row(int $files, array $facts): array
    {
        return ['directory' => 'd', 'files' => $files, 'facts' => $facts];
    }
}
