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
    private static function file(string $path, string $topLevel, array $uses = []): array
    {
        return ['path' => $path, 'top_level' => $topLevel, 'uses' => $uses];
    }

    /** A file whose name ends in the same word comes before one that merely looks like the folder. */
    public function testASharedSuffixBeatsSimilarity(): void
    {
        $candidates = [
            self::file('src/a/Alpha.php', 'Alpha', ['PDO', 'Clock']),
            self::file('src/a/Beta.php', 'Beta', ['PDO', 'Clock']),
            self::file('src/a/ZetaService.php', 'ZetaService', []),
        ];

        self::assertSame(['src/a/ZetaService.php', 'src/a/Alpha.php', 'src/a/Beta.php'], Neighbours::rank('FooService', $candidates));
    }

    /** With no suffix to share, the file closest to the folder's usual dependencies comes first. */
    public function testSimilarityBeatsPath(): void
    {
        $candidates = [
            self::file('src/a/A.php', 'A', ['X']),
            self::file('src/a/B.php', 'B', ['PDO', 'Clock']),
            self::file('src/a/C.php', 'C', ['PDO', 'Clock', 'Other']),
            self::file('src/a/D.php', 'D', ['PDO']),
        ];

        // The folder's usual set is PDO, Clock, Other, X. C holds three of the four (3/4), B two (2/4);
        // A and D hold one each (1/4) and are ordered by path.
        self::assertSame(['src/a/C.php', 'src/a/B.php', 'src/a/A.php'], Neighbours::rank('Foo', $candidates));
    }

    /** Equal files are ordered by path, whatever order they came in, and no more than three are named. */
    public function testTiesBreakByPathAndAtMostThreeAreNamed(): void
    {
        $candidates = [
            self::file('src/a/E.php', 'E'),
            self::file('src/a/B.php', 'B'),
            self::file('src/a/D.php', 'D'),
            self::file('src/a/A.php', 'A'),
            self::file('src/a/C.php', 'C'),
        ];

        self::assertSame(['src/a/A.php', 'src/a/B.php', 'src/a/C.php'], Neighbours::rank('Foo', $candidates));
        self::assertSame(Neighbours::rank('Foo', $candidates), Neighbours::rank('Foo', array_reverse($candidates)));
    }

    /** Nothing to rank gives nothing, and a name with no word shares with no one. */
    public function testNoCandidatesGiveNoNeighbours(): void
    {
        self::assertSame([], Neighbours::rank('Foo', []));
        self::assertSame(['src/a/A.php'], Neighbours::rank('', [self::file('src/a/A.php', '')]));
    }
}
