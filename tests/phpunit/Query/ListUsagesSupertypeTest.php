<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * A caller typed on an interface calls the interface's method in the graph,
 * so the implementation listed no production caller at all: a repository
 * method called only through its interface read as used by tests alone.
 * Impact and flow already step through the supertype; usages now do too.
 */
final class ListUsagesSupertypeTest extends KnossosTestCase
{
    #[Group('query')]
    public function testACallThroughTheInterfaceIsListedAsAUsageOfTheImplementation(): void
    {
        [$pdo, $projectId, $root] = $this->scannedFixture();
        try {
            $usages = ArchitectureQueryService::forDatabase($pdo)->listUsages($projectId, 'App\\SqlStore::save')->data['usages'];

            $viaInterface = self::row($usages, 'App\\Writer::run');
            self::assertNotNull($viaInterface, 'The production caller reaches save() through Store.');
            self::assertSame('App\\Store::save', $viaInterface['via_supertype']);
            self::assertSame('probable', $viaInterface['confidence'], 'Which implementation runs is a guess, as in impact analysis.');
            self::assertFalse($viaInterface['test']);
            self::assertNotNull(self::row($usages, 'Tests\\WriterTest::testSaves'), 'The direct call is still listed.');
            self::assertArrayNotHasKey('via_supertype', (array) self::row($usages, 'Tests\\WriterTest::testSaves'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testExcludeTestsLeavesOnlyProductionCallers(): void
    {
        [$pdo, $projectId, $root] = $this->scannedFixture();
        try {
            $result = ArchitectureQueryService::forDatabase($pdo)->listUsages($projectId, 'App\\SqlStore::save', excludeTests: true);

            self::assertSame(['App\\Writer::run'], array_map(static fn(array $usage): string => $usage['source']['canonical_name'], $result->data['usages']));
            self::assertStringStartsWith('Found 1 usage site', $result->summary);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A probable hop cannot pass a certain-only filter. */
    #[Group('query')]
    public function testACertainOnlyListingLeavesTheSupertypeHopOut(): void
    {
        [$pdo, $projectId, $root] = $this->scannedFixture();
        try {
            $usages = ArchitectureQueryService::forDatabase($pdo)->listUsages($projectId, 'App\\SqlStore::save', minConfidence: 'certain')->data['usages'];

            self::assertNull(self::row($usages, 'App\\Writer::run'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * @param list<array<string, mixed>> $usages
     * @return array<string, mixed>|null
     */
    private static function row(array $usages, string $caller): ?array
    {
        foreach ($usages as $usage) {
            if (($usage['source']['canonical_name'] ?? null) === $caller) {
                return $usage;
            }
        }

        return null;
    }

    /** @return array{0: PDO, 1: string, 2: string} */
    private function scannedFixture(): array
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        $files = [
            'composer.json' => '{"autoload": {"psr-4": {"App\\\\": "src/"}}}',
            'src/Store.php' => "<?php\nnamespace App;\ninterface Store\n{\n    public function save(): void;\n}\n",
            'src/SqlStore.php' => "<?php\nnamespace App;\nfinal class SqlStore implements Store\n{\n    public function save(): void {}\n}\n",
            'src/Writer.php' => "<?php\nnamespace App;\nfinal class Writer\n{\n    public function __construct(private Store \$store) {}\n\n    public function run(): void\n    {\n        \$this->store->save();\n    }\n}\n",
            'tests/WriterTest.php' => "<?php\nnamespace Tests;\nuse App\\SqlStore;\nfinal class WriterTest\n{\n    public function testSaves(): void\n    {\n        (new SqlStore())->save();\n    }\n}\n",
        ];
        foreach ($files as $path => $contents) {
            if (!is_dir(dirname($root . '/' . $path))) {
                mkdir(dirname($root . '/' . $path), 0o700, true);
            }
            file_put_contents($root . '/' . $path, $contents);
        }
        $pdo = $this->freshTestDatabase();
        $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

        return [$pdo, $result->projectId, $root];
    }
}
