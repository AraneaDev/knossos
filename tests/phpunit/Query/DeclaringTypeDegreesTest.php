<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PDOStatement;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * Classification reads the degrees of the types declaring a chunk's methods in
 * one batch, not one type at a time.
 *
 * A constructor of a referenced class is left out because its type is used,
 * which asks that type's in-degree; the type is referenced, so it is no
 * candidate and its degree was never read with the chunk. Each such type was
 * then read alone, two statements apiece: 268 of them on a scan of this
 * repository, against 21 chunks.
 */
final class DeclaringTypeDegreesTest extends KnossosTestCase
{
    #[Group('query')]
    public function testTheDegreeReadsDoNotGrowWithTheReferencedTypesAChunkAsksAbout(): void
    {
        [$few, $excludedFew] = $this->inDegreeReads(2);
        [$many, $excludedMany] = $this->inDegreeReads(6);

        // The constructors are left out the same way either way...
        assertSame([2, 6], [$excludedFew, $excludedMany]);
        // ...and the reads that told them so do not multiply with the types.
        assertSame($few, $many);
    }

    /**
     * A node a role marks as reached by convention, test code above all, is
     * counted and dropped before classification: its degrees and boundaries
     * are never used, so they are never read. On a scan of this repository
     * that was 9,194 of the chunks' 10,500 nodes, read and thrown away.
     */
    #[Group('query')]
    public function testNothingIsReadForANodeTheConventionCheckTurnsAway(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $tests = [];
        for ($index = 0; $index < 3; ++$index) {
            $name = sprintf('App\\Tests\\helper%d', $index);
            $id = StableId::symbol($project, 'php', 'function', $name);
            $repository->saveNode($id, $project, 'php', 'function', $name, sprintf('helper%d', $index), null, $ids['file'], 1, 9, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
            $repository->saveClassification(StableId::classification($project, $id, 'quality.test_module', 'test.roles'), $project, $id, 'quality.test_module', 'derived', 'probable', 'test.roles', $ids['file'], 1, 9, [], $ids['scan']);
            $tests[] = $id;
        }
        $repository->completeScan($project, $ids['scan']);

        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class]);
        CountingStatement::drain();
        try {
            $bounds = ArchitectureQueryService::forDatabase($pdo)->services()->healthQueries->architectureHealth($project, limit: 10)->data['bounds'];
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
        }
        $readFor = [];
        foreach (CountingStatement::drain() as [$sql, $params]) {
            $read = match (true) {
                str_contains($sql, 'COUNT(*) AS edge_count FROM edges') => 'degrees',
                str_contains($sql, 'FROM boundary_memberships') => 'boundaries',
                default => null,
            };
            foreach ($read === null ? [] : array_intersect($tests, $params ?? []) as $id) {
                $readFor[] = $read;
            }
        }

        assertSame(3, (int) $bounds['excluded_convention_discovered']);
        assertSame([], array_values(array_unique($readFor)));
    }

    /**
     * How many in-degree reads classification makes, and how many constructors
     * it leaves out, for `$types` referenced classes each declaring an
     * unreferenced constructor.
     *
     * @return array{0: int, 1: int}
     */
    private function inDegreeReads(int $types): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        for ($index = 0; $index < $types; ++$index) {
            $class = sprintf('App\\Service%d', $index);
            $classId = StableId::symbol($project, 'php', 'class', $class);
            $constructorId = StableId::symbol($project, 'php', 'method', $class . '::__construct');
            $repository->saveNode($classId, $project, 'php', 'class', $class, sprintf('Service%d', $index), null, $ids['file'], 1, 9, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
            $repository->saveNode($constructorId, $project, 'php', 'method', $class . '::__construct', '__construct', null, $ids['file'], 2, 8, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
            $repository->saveEdge(StableId::edge($project, 'contains', $classId, $constructorId, (string) $index), $project, 'contains', $classId, $constructorId, $ids['file'], 2, 2, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
            $repository->saveEdge(StableId::edge($project, 'references', $ids['checkout'], $classId, (string) $index), $project, 'references', $ids['checkout'], $classId, $ids['file'], 3, 3, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        }
        $repository->completeScan($project, $ids['scan']);

        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class]);
        CountingStatement::drain();
        try {
            $bounds = ArchitectureQueryService::forDatabase($pdo)->services()->healthQueries->architectureHealth($project, limit: 10)->data['bounds'];
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
        }
        $reads = count(array_filter(CountingStatement::drain(), static fn(array $run): bool => str_starts_with($run[0], 'SELECT target_id, kind, COUNT(*) AS edge_count FROM edges')));

        return [$reads, (int) $bounds['excluded_constructors']];
    }
}

/** Records the SQL of each statement as it runs. */
final class CountingStatement extends PDOStatement
{
    /** @var list<array{0: string, 1: array<mixed>|null}> */
    private static array $executed = [];

    protected function __construct() {}

    /**
     * What ran since the last call, which this forgets.
     *
     * @return list<array{0: string, 1: array<mixed>|null}>
     */
    public static function drain(): array
    {
        $executed = self::$executed;
        self::$executed = [];
        return $executed;
    }

    public function execute(?array $params = null): bool
    {
        self::$executed[] = [$this->queryString, $params];
        return parent::execute($params);
    }
}
