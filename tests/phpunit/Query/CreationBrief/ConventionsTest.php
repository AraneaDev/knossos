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
        $shown = Conventions::shown(11, ['modifiers' => ['final' => 6]]);

        self::assertSame([], self::ids($shown, 'modifier:final'));
    }

    /** Nine of ten passes and carries its own evidence. */
    public function testAPatternInNineOfTenIsShown(): void
    {
        $shown = Conventions::shown(10, ['modifiers' => ['final' => 9]]);

        self::assertContains(['id' => 'modifier:final', 'text' => 'final', 'files' => 9, 'of' => 10], $shown);
    }

    /** Below five files nothing is a pattern, even when all four agree. */
    public function testFourFilesShowNothingAtAll(): void
    {
        self::assertSame([], Conventions::shown(4, [
            'kinds' => ['one_class' => 4],
            'modifiers' => ['final' => 4],
            'lines' => ['median' => 10, 'max' => 20],
        ]));
    }

    /** The share is compared as integers, so exactly four in five is in. */
    public function testExactlyEightyPercentIsShown(): void
    {
        $shown = Conventions::shown(10, ['modifiers' => ['final' => 8, 'readonly' => 7]]);

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
        ]);

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
        $shown = Conventions::shown(5, ['injects' => [['type' => 'Foo::Bar', 'files' => 5]]]);

        self::assertSame('inject Bar', $shown[0]['text']);
    }

    /** Line counts describe any folder with enough files, and cover all of them. */
    public function testLinesAreAlwaysShownForAPopulatedFolder(): void
    {
        $shown = Conventions::shown(5, ['lines' => ['median' => 40, 'max' => 90]]);

        self::assertSame([['id' => 'lines', 'text' => 'median 40 lines, largest 90', 'files' => 5, 'of' => 5]], $shown);
    }

    /** A mixed folder is a finding about the folder, never a convention to follow. */
    public function testMixedKindIsNeverShown(): void
    {
        self::assertSame([], Conventions::shown(10, ['kinds' => ['mixed' => 10]]));
    }

    /** Every kind has its own wording. */
    public function testEachKindHasItsWording(): void
    {
        $texts = [];
        foreach (['one_class', 'one_interface', 'one_trait', 'one_enum', 'functions_only'] as $kind) {
            $texts[] = Conventions::shown(5, ['kinds' => [$kind => 5]])[0]['text'];
        }

        self::assertSame(['one class per file', 'one interface per file', 'one trait per file', 'one enum per file', 'functions only'], $texts);
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
