<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A property read or written through a typed receiver is a usage of that
 * property, so the methods touching it show up as its readers.
 */
#[Group('query')]
final class PropertyUsagesTest extends KnossosTestCase
{
    public function testListUsagesFindsAPropertysReaders(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-property-usages-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        copy(self::repositoryRoot() . '/tests/Fixtures/php-scanner/src/PropertyUse.php', $root . '/src/PropertyUse.php');

        try {
            $pdo = $this->freshTestDatabase();
            $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
            $queries = ArchitectureQueryService::forDatabase($pdo);
            $result = $queries->listUsages($projectId, 'Fixture\\Box::$items');
            $counter = $queries->listUsages($projectId, 'Fixture\\Counter::$n');
        } finally {
            $this->removeTempTree($root);
        }

        self::assertSame('Fixture\\Box::$items', $result->data['target']['canonical_name'] ?? null);
        $usages = $result->data['usages'];
        self::assertCount(2, $usages);
        self::assertSame(['Fixture\\User::f', 'Fixture\\User::f'], array_column(array_column($usages, 'source'), 'canonical_name'));
        self::assertSame([18, 19], array_column($usages, 'start_line'));
        // A read and a write are separate usages, each saying which it is.
        self::assertSame(['read', 'write'], array_column(array_column($usages, 'attributes'), 'access'));
        // A read and a write on one line are both kept; the later write collapses into the first.
        self::assertSame(
            [[36, 'read'], [36, 'write'], [37, 'read_write']],
            self::sites($counter->data['usages']),
        );
    }

    /**
     * Each usage's line and access, in line then access order.
     *
     * @param list<array<string, mixed>> $usages
     * @return list<array{0: mixed, 1: mixed}>
     */
    private static function sites(array $usages): array
    {
        $sites = array_map(static fn(array $usage): array => [$usage['start_line'], $usage['attributes']['access'] ?? null], $usages);
        sort($sites);

        return $sites;
    }
}
