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
    /** Files are summed; kinds and modifiers do not transfer between folders and are left out. */
    public function testFilesAreSummedAndKindsAndModifiersLeftOut(): void
    {
        $merged = SubtreeFacts::merge([
            self::row(2, ['kinds' => ['one_class' => 2], 'modifiers' => ['final' => 2]]),
            self::row(3, ['kinds' => ['one_class' => 2, 'mixed' => 1], 'modifiers' => ['readonly' => 1, 'final' => 3]]),
            self::row(3, []),
        ]);

        self::assertSame(['files' => 8, 'facts' => []], $merged);
    }

    /** The suffix most files have across the rows wins, the name breaking a tie. */
    public function testTheSuffixWithTheMostFilesWinsAndTiesGoByName(): void
    {
        $merged = SubtreeFacts::merge([
            self::row(5, ['suffix' => ['value' => 'Service', 'files' => 3]]),
            self::row(5, ['suffix' => ['value' => 'Command', 'files' => 4]]),
            self::row(5, ['suffix' => ['value' => 'Service', 'files' => 1]]),
        ]);

        self::assertSame(['value' => 'Command', 'files' => 4], $merged['facts']['suffix'], 'four and four: Command sorts first');
    }

    /** Three folders of two PascalCase files each store no name case of their own, and together have one. */
    public function testSmallFoldersTogetherHaveANameCase(): void
    {
        $merged = SubtreeFacts::merge([
            self::row(2, ['name_styles' => ['pascal' => 2]]),
            self::row(2, ['name_styles' => ['pascal' => 2]]),
            self::row(2, ['name_styles' => ['pascal' => 2]]),
        ]);

        self::assertSame(['value' => 'pascal', 'files' => 6], $merged['facts']['name_case']);
        self::assertArrayNotHasKey('name_styles', $merged['facts']);
    }

    /** Small kebab folders with single-word files beside them: the words count with kebab case once it has three files. */
    public function testSingleWordsCountWithKebabCaseAcrossFolders(): void
    {
        $merged = SubtreeFacts::merge([
            self::row(3, ['name_styles' => ['kebab' => 2, 'word' => 1]]),
            self::row(3, ['name_styles' => ['kebab' => 1, 'word' => 1, 'pascal' => 1]]),
        ]);
        $two = SubtreeFacts::merge([self::row(4, ['name_styles' => ['kebab' => 2, 'word' => 2]])]);

        self::assertSame(['value' => 'kebab', 'files' => 5], $merged['facts']['name_case']);
        self::assertArrayNotHasKey('name_case', $two['facts'], 'two kebab names are not enough, however many words agree');
    }

    /** A row's own name case is not summed: the counts per style are, so a folder is never counted twice. */
    public function testTheRowsNameCaseIsNotSummed(): void
    {
        $merged = SubtreeFacts::merge([self::row(5, ['name_case' => ['value' => 'kebab', 'files' => 5]])]);

        self::assertArrayNotHasKey('name_case', $merged['facts']);
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
