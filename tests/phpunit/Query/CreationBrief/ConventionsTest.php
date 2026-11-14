<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\CreationBrief;

use Knossos\Query\CreationBrief\Conventions;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A convention is a claim about a folder, so it is shown only where enough
 * files back it: at least five files, and at least four in five of them.
 */
#[Group('creation-brief')]
final class ConventionsTest extends KnossosTestCase
{
    /** Six of eleven is a majority, not a convention. */
    public function testAPatternInSixOfElevenIsNotShown(): void
    {
        $shown = Conventions::shown(11, ['modifiers' => ['final' => 6]], 'source');

        self::assertSame([], self::ids($shown, 'modifier:final'));
    }

    /** Nine of ten passes and carries its own evidence. */
    public function testAPatternInNineOfTenIsShown(): void
    {
        $shown = Conventions::shown(10, ['modifiers' => ['final' => 9]], 'source');

        self::assertContains(['id' => 'modifier:final', 'text' => 'final', 'files' => 9, 'of' => 10], $shown);
    }

    /** Below five files nothing is a pattern, even when all four agree. */
    public function testFourFilesShowNothingAtAll(): void
    {
        self::assertSame([], Conventions::shown(4, [
            'kinds' => ['one_class' => 4],
            'modifiers' => ['final' => 4],
            'lines' => ['median' => 10, 'max' => 20],
        ], 'source'));
    }

    /** The share is compared as integers, so exactly four in five is in. */
    public function testExactlyEightyPercentIsShown(): void
    {
        $shown = Conventions::shown(10, ['modifiers' => ['final' => 8, 'readonly' => 7]], 'source');

        self::assertSame(['modifier:final'], array_column(self::without($shown, 'lines'), 'id'));
    }

    /** Kinds, modifiers (alphabetical), suffix, two injects, two uses, then lines. */
    public function testTheOrderIsFixed(): void
    {
        $shown = Conventions::shown(10, [
            'lines' => ['median' => 180, 'max' => 590],
            'uses' => [
                ['type' => 'App\\A', 'files' => 8],
                ['type' => 'App\\ResultEnvelope', 'files' => 10],
                ['type' => 'App\\B', 'files' => 9],
                ['type' => 'App\\Rare', 'files' => 3],
            ],
            'injects' => [
                ['type' => 'Foo::Bar', 'files' => 8],
                ['type' => 'PDO', 'files' => 10],
                ['type' => 'Other', 'files' => 9],
            ],
            'suffix' => ['value' => 'Service', 'files' => 9],
            'modifiers' => ['readonly' => 9, 'final' => 10],
            'kinds' => ['one_class' => 10],
        ], 'source');

        self::assertSame([
            'kind',
            'modifier:final',
            'modifier:readonly',
            'suffix',
            'injects:PDO',
            'injects:Other',
            'uses:App\\ResultEnvelope',
            'uses:App\\B',
            'lines',
        ], array_column($shown, 'id'));
        self::assertSame(
            ['one class per file', 'final', 'readonly', 'named *Service', 'inject PDO', 'inject Other', 'use ResultEnvelope', 'use B', 'median 180 lines, largest 590'],
            array_column($shown, 'text'),
        );
    }

    /** The short name is the part after the last backslash or double colon. */
    public function testATypeIsShownByItsShortName(): void
    {
        $shown = Conventions::shown(5, ['injects' => [['type' => 'Foo::Bar', 'files' => 5]]], 'source');

        self::assertSame('inject Bar', $shown[0]['text']);
    }

    /** Line counts describe any folder with enough files, and cover all of them. */
    public function testLinesAreAlwaysShownForAPopulatedFolder(): void
    {
        $shown = Conventions::shown(5, ['lines' => ['median' => 40, 'max' => 90]], 'source');

        self::assertSame([['id' => 'lines', 'text' => 'median 40 lines, largest 90', 'files' => 5, 'of' => 5]], $shown);
    }

    /** A mixed folder is a finding about the folder, never a convention to follow. */
    public function testMixedKindIsNeverShown(): void
    {
        self::assertSame([], Conventions::shown(10, ['kinds' => ['mixed' => 10]], 'source'));
    }

    /** Every kind has its own wording. */
    public function testEachKindHasItsWording(): void
    {
        $texts = [];
        foreach (['one_class', 'one_interface', 'one_trait', 'one_enum', 'functions_only'] as $kind) {
            $texts[] = Conventions::shown(5, ['kinds' => [$kind => 5]], 'source')[0]['text'];
        }

        self::assertSame(['one class per file', 'one interface per file', 'one trait per file', 'one enum per file', 'functions only'], $texts);
    }

    /** How a source folder's file names are written comes after the suffix and before what files inject. */
    public function testTheNameCaseOfASourceFolderIsShownAfterTheSuffix(): void
    {
        $facts = ['suffix' => ['value' => 'Actions', 'files' => 5], 'name_case' => ['value' => 'kebab', 'files' => 5], 'injects' => [['type' => 'PDO', 'files' => 5]]];

        $shown = Conventions::shown(5, $facts, 'source');

        self::assertSame(['suffix', 'name_case', 'injects:PDO'], array_column($shown, 'id'));
        self::assertSame(['id' => 'name_case', 'text' => 'kebab-case file names', 'files' => 5, 'of' => 5], $shown[1]);
    }

    /** Each style has its wording, and a style below four in five is not shown. */
    public function testEachNameCaseHasItsWording(): void
    {
        $texts = [];
        foreach (['kebab', 'snake', 'pascal', 'camel'] as $style) {
            $texts[] = Conventions::shown(5, ['name_case' => ['value' => $style, 'files' => 4]], 'source')[0]['text'];
        }

        self::assertSame(['kebab-case file names', 'snake_case file names', 'PascalCase file names', 'camelCase file names'], $texts);
        self::assertSame([], Conventions::shown(10, ['name_case' => ['value' => 'kebab', 'files' => 7]], 'source'));
    }

    /** A test folder's names are fixed by its test runner, so they are never stated as a convention. */
    public function testTheNameCaseOfATestFolderIsNotShown(): void
    {
        self::assertSame([], Conventions::shown(5, ['name_case' => ['value' => 'kebab', 'files' => 5]], 'test'));
    }

    /** A used or injected type needs nine in ten files: eight is not enough, though it is for a modifier. */
    public function testAUsedTypeNeedsNineInTen(): void
    {
        $eight = Conventions::shown(10, ['uses' => [['type' => 'A', 'files' => 8]], 'injects' => [['type' => 'B', 'files' => 8]], 'modifiers' => ['final' => 8]], 'source');
        $nine = Conventions::shown(10, ['uses' => [['type' => 'A', 'files' => 9]], 'injects' => [['type' => 'B', 'files' => 9]]], 'source');

        self::assertSame(['modifier:final'], array_column(self::without($eight, 'lines'), 'id'));
        self::assertSame(['injects:B', 'uses:A'], array_column($nine, 'id'));
    }

    /** A folder of interface and type-alias files says so. */
    public function testTypesOnlyHasItsWording(): void
    {
        self::assertSame([['id' => 'kind', 'text' => 'types only', 'files' => 5, 'of' => 5]], Conventions::shown(5, ['kinds' => ['types_only' => 5]], 'source'));
    }

    /**
     * @param list<array{id: string, text: string, files: int, of: int}> $shown
     * @return list<array{id: string, text: string, files: int, of: int}>
     */
    private static function ids(array $shown, string $id): array
    {
        return array_values(array_filter($shown, static fn(array $row): bool => $row['id'] === $id));
    }

    /**
     * @param list<array{id: string, text: string, files: int, of: int}> $shown
     * @return list<array{id: string, text: string, files: int, of: int}>
     */
    private static function without(array $shown, string $id): array
    {
        return array_values(array_filter($shown, static fn(array $row): bool => $row['id'] !== $id));
    }
}
