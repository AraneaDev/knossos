<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Closure;
use Knossos\Query\ConstructedClasses;
use Knossos\Query\Direction;
use Knossos\Query\EdgeWalk;
use Knossos\Store\EnrichedFacts;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The bounded walk over native edges and its closure, and the walks built on
 * it that once read an enricher's edges as their own.
 */
final class EdgeWalkTest extends KnossosTestCase
{
    /** Level by level to the bound, each node read once around a cycle, an enricher's edge and other kinds never followed. */
    #[Group('query')]
    public function testTheWalkReadsNativeEdgesOfItsKindsToTheBound(): void
    {
        [$pdo, $project, $node, $edge] = $this->graph();
        [$a, $b, $c, $d, $guess] = array_map(static fn(string $name): string => $node('class', $name), ['A', 'B', 'C', 'D', 'Guess']);
        $edge('extends', $a, $b);
        $edge('extends', $b, $c);
        $edge('extends', $c, $a);
        $edge('extends', $c, $d);
        $edge('implements', $a, $d);
        $edge('extends', $b, $guess, true);

        assertSame(self::sorted([$a => [$b], $b => [$c], $c => [$a, $d]]), self::sorted(EdgeWalk::reach($pdo, $project, ['extends'], Direction::Outgoing, [$a], 5)));
        assertSame([$a => [$b], $b => [$c]], EdgeWalk::reach($pdo, $project, ['extends'], Direction::Outgoing, [$a], 2));
        assertSame(self::sorted([$d => [$a, $c]]), self::sorted(EdgeWalk::reach($pdo, $project, ['extends', 'implements'], Direction::Incoming, [$d], 1)));
        assertSame([$c => [$d]], EdgeWalk::reach($pdo, $project, ['extends'], Direction::Outgoing, [$c], 1, " AND edges.target_id <> '{$a}'"));
        assertSame([], EdgeWalk::reach($pdo, $project, ['extends'], Direction::Outgoing, [], 5));
    }

    /** The closure leaves the start out, meets each node once, and never enters a stop. */
    #[Group('query')]
    public function testTheClosureStopsOnCyclesAndStops(): void
    {
        $links = ['a' => ['b'], 'b' => ['c', 'a'], 'c' => ['d']];

        assertSame(['b', 'a', 'c', 'd'], EdgeWalk::closure('x', ['x' => ['b']] + $links));
        assertSame(['b', 'c', 'd'], EdgeWalk::closure('a', $links));
        assertSame(['b'], EdgeWalk::closure('a', $links, ['c']));
        assertSame([], EdgeWalk::closure('d', $links));
    }

    /** An enricher's claim that a class extends another does not make constructing it run the other's constructor. */
    #[Group('query')]
    public function testAnEnrichersExtendsEdgeRunsNoConstructor(): void
    {
        [$pdo, $project, $node, $edge] = $this->graph();
        $base = $node('class', 'Base');
        $constructor = $node('method', 'Base::__construct', '__construct');
        $edge('contains', $base, $constructor);
        $child = $node('class', 'Child');
        $edge('extends', $child, $base);
        $guess = $node('class', 'Guess');
        $edge('extends', $guess, $base, true);

        assertSame([$constructor => [$base, $child]], ConstructedClasses::of($pdo, $project, [$constructor]));
    }

    /**
     * A store with one scan, a node maker (kind, name, display name) and an
     * edge maker that can mark the edge an enricher's.
     *
     * @return array{0: PDO, 1: string, 2: Closure(string, string, ?string=): string, 3: Closure(string, string, string, bool=): void}
     */
    private function graph(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $owner = 'php:file:src/Checkout.php';
        $node = static function (string $kind, string $name, ?string $display = null) use ($repository, $ids, $owner): string {
            $id = StableId::symbol($ids['project'], 'php', $kind, 'App\\' . $name);
            $repository->saveNode($id, $ids['project'], 'php', $kind, 'App\\' . $name, $display ?? $name, null, $ids['file'], 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);

            return $id;
        };
        $edge = static function (string $kind, string $from, string $to, bool $enriched = false) use ($pdo, $repository, $ids, $owner): void {
            $id = StableId::edge($ids['project'], $kind, $from, $to, 'x');
            $repository->saveEdge($id, $ids['project'], $kind, $from, $to, $ids['file'], 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
            if ($enriched) {
                $pdo->prepare('UPDATE edges SET origin = ? WHERE id = ?')->execute([EnrichedFacts::ORIGIN, $id]);
            }
        };

        return [$pdo, (string) $ids['project'], $node, $edge];
    }

    /**
     * `$links` with each node's neighbours sorted, the rows' own order being the database's.
     *
     * @param array<string, list<string>> $links
     * @return array<string, list<string>>
     */
    private static function sorted(array $links): array
    {
        foreach ($links as &$neighbours) {
            sort($neighbours);
        }

        return $links;
    }
}
