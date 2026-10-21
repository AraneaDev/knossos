<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Mcp\ToolCatalog;
use Knossos\Query\ConfidenceRank;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/** One confidence vocabulary: the names, their ranks in PHP and in SQL, and the schema's list. */
final class ConfidenceRankTest extends KnossosTestCase
{
    #[Group('query')]
    public function testEachNameRanksAndAnUnknownOneIsRefused(): void
    {
        assertSame([1, 2, 3], array_map(ConfidenceRank::rank(...), ['possible', 'probable', 'certain']));
        assertSame('min_confidence must be possible, probable, or certain.', self::captureThrown(static fn() => ConfidenceRank::rank('sure'), InvalidArgumentException::class)->getMessage());
        assertSame(['certain', 'probable', 'possible'], ConfidenceRank::enum());
    }

    /** The SQL ranks each name as NAMES does, and a name it does not know as the lowest. */
    #[Group('query')]
    public function testTheSqlRankAgreesWithTheNames(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $rank = static fn(string $name): int => (int) $pdo->query('SELECT ' . ConfidenceRank::of($pdo->quote($name)))->fetchColumn();

        foreach (ConfidenceRank::NAMES as $name => $expected) {
            assertSame($expected, $rank($name));
        }
        assertSame(min(ConfidenceRank::NAMES), $rank('unknown'));
    }

    /** Every schema property over confidence names lists the vocabulary. */
    #[Group('query')]
    public function testEveryToolSchemaListsTheVocabulary(): void
    {
        $lists = [];
        foreach (ToolCatalog::definitions(false) as $tool) {
            foreach ($tool['inputSchema']['properties'] ?? [] as $name => $property) {
                if ($name === 'min_confidence') {
                    $lists[] = $property['enum'];
                }
            }
        }

        assertSame(array_fill(0, count($lists), ConfidenceRank::enum()), $lists);
        assertSame(true, count($lists) > 0);
    }
}
