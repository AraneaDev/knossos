<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanOptions;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Three ways a TypeScript method is called with no edge to it: through an
 * interface the class implements, through an interface the class meets by
 * shape alone, and through a cast to an object type naming the method. None
 * of them is probable dead code. A method none of them covers still is.
 */
#[Group('query')]
#[Group('typescript-scanner')]
final class TypescriptContractCandidatesTest extends KnossosTestCase
{
    /** @var array<string, string> canonical name => the reason the last fixture read gave */
    private array $reasons = [];

    public function testAnInterfaceMemberAClassImplementsIsNotProbablyDead(): void
    {
        $confidence = $this->candidates('ts-dead-implemented-interface');

        self::assertNotSame('probable', $confidence['src/types.ts#QueryGuard::validate'] ?? null);
        self::assertNotSame('probable', $confidence['src/types.ts#QueryGuard::complexity'] ?? null);
        self::assertSame('probable', $confidence['src/guard.ts#Guard::unused'] ?? null);
    }

    public function testAMethodMeetingAnInterfaceByShapeIsNotProbablyDead(): void
    {
        $confidence = $this->candidates('ts-dead-structural-interface');

        self::assertNotSame('probable', $confidence['src/store.ts#Store::checkedAt'] ?? null);
        self::assertNotSame('probable', $confidence['src/store.ts#Store::record'] ?? null);
        self::assertSame('probable', $confidence['src/store.ts#Store::unused'] ?? null);
        self::assertStringContainsString('every member of the interface Checks', $this->reasons['src/store.ts#Store::record'] ?? '');
    }

    public function testAMethodACastNamesIsNotProbablyDead(): void
    {
        $confidence = $this->candidates('ts-dead-cast-member');

        self::assertNotSame('probable', $confidence['src/adapters.ts#PostgresAdapter::destroyPool'] ?? null);
        self::assertNotSame('probable', $confidence['src/adapters.ts#MysqlAdapter::destroyPool'] ?? null);
        self::assertSame('probable', $confidence['src/adapters.ts#PostgresAdapter::unused'] ?? null);
        self::assertStringContainsString('as a cast to one writes', $this->reasons['src/adapters.ts#MysqlAdapter::destroyPool'] ?? '');
    }

    /**
     * The fixture's dead-code candidates, canonical name => confidence; their reasons go to {@see self::$reasons}.
     *
     * @return array<string, string>
     */
    private function candidates(string $fixture): array
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/' . $fixture;
        $pdo = $this->freshTestDatabase();
        $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, new ScanOptions(mode: 'full'))->projectId;
        $data = ArchitectureQueryService::forDatabase($pdo)->services()->healthQueries->architectureHealth($projectId, limit: 100)->data;

        $confidence = [];
        foreach ($data['dead_code_candidates'] as $candidate) {
            $confidence[(string) $candidate['component']['canonical_name']] = (string) $candidate['confidence'];
            $this->reasons[(string) $candidate['component']['canonical_name']] = (string) $candidate['reason'];
        }

        return $confidence;
    }
}
