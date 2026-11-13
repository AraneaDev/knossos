<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\CreationBrief;

use Knossos\Query\CreationBrief\Neighbours;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The order of the files a new file is read beside, and why it does not depend on how they arrive. */
#[Group('query')]
final class NeighboursTest extends KnossosTestCase
{
    /** @param list<string> $uses */
    private static function file(string $path, array $uses = []): array
    {
        return ['path' => $path, 'uses' => $uses];
    }

    /** A file whose name ends in the same word comes before one that merely looks like the folder. */
    public function testASharedSuffixBeatsSimilarity(): void
    {
        $candidates = [
            self::file('src/a/Alpha.php', ['PDO', 'Clock']),
            self::file('src/a/Beta.php', ['PDO', 'Clock']),
            self::file('src/a/ZetaService.php', []),
        ];

        self::assertSame(['src/a/ZetaService.php', 'src/a/Alpha.php', 'src/a/Beta.php'], Neighbours::rank('FooService', $candidates));
    }

    /** With no suffix to share, the file closest to the folder's usual dependencies comes first. */
    public function testSimilarityBeatsPath(): void
    {
        $candidates = [
            self::file('src/a/A.php', ['X']),
            self::file('src/a/B.php', ['PDO', 'Clock']),
            self::file('src/a/C.php', ['PDO', 'Clock', 'Other']),
            self::file('src/a/D.php', ['PDO']),
        ];

        // The folder's usual set is PDO, Clock, Other, X. C holds three of the four (3/4), B two (2/4);
        // A and D hold one each (1/4) and are ordered by path.
        self::assertSame(['src/a/C.php', 'src/a/B.php', 'src/a/A.php'], Neighbours::rank('Foo', $candidates));
    }

    /** Equal files are ordered by path, whatever order they came in, and no more than three are named. */
    public function testTiesBreakByPathAndAtMostThreeAreNamed(): void
    {
        $candidates = [
            self::file('src/a/E.php'),
            self::file('src/a/B.php'),
            self::file('src/a/D.php'),
            self::file('src/a/A.php'),
            self::file('src/a/C.php'),
        ];

        self::assertSame(['src/a/A.php', 'src/a/B.php', 'src/a/C.php'], Neighbours::rank('Foo', $candidates));
        self::assertSame(Neighbours::rank('Foo', $candidates), Neighbours::rank('Foo', array_reverse($candidates)));
    }

    /** Nothing to rank gives nothing, and a name with no word shares with no one. */
    public function testNoCandidatesGiveNoNeighbours(): void
    {
        self::assertSame([], Neighbours::rank('Foo', []));
        self::assertSame(['src/a/A.php'], Neighbours::rank('', [self::file('src/a/A.php')]));
    }

    /** A snake_case stem shares its last word with a snake_case neighbour, which then comes first. */
    public function testASnakeCaseStemSharesItsLastWord(): void
    {
        $candidates = [
            self::file('app/a.py', ['db', 'log']),
            self::file('app/bar_service.py'),
            self::file('app/b.py', ['db', 'log']),
        ];

        self::assertSame('app/bar_service.py', Neighbours::rank('foo_service', $candidates)[0]);
    }

    /** `new-thing` and `NewThing` end in the same word, so they rank the same neighbours the same way. */
    public function testKebabAndCamelCaseStemsAgree(): void
    {
        $candidates = [
            self::file('web/a.ts', ['react']),
            self::file('web/other-thing.ts'),
            self::file('web/OldThing.ts'),
            self::file('web/b.ts', ['react']),
        ];

        $kebab = Neighbours::rank('new-thing', $candidates);

        self::assertSame(['web/OldThing.ts', 'web/other-thing.ts', 'web/a.ts'], $kebab);
        self::assertSame($kebab, Neighbours::rank('NewThing', $candidates));
    }

    /** The word compared is the candidate's file stem, not what it declares first. */
    public function testTheCandidatesFileStemIsCompared(): void
    {
        $candidates = [self::file('src/a/A.php'), self::file('src/a/ZetaService.php')];

        self::assertSame(['src/a/ZetaService.php', 'src/a/A.php'], Neighbours::rank('FooService', $candidates));
    }
}
