<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\AbstractArchitectureQueryService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\BlastRadiusService;
use Knossos\Query\TestReachSearch;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * A property read or write on a receiver the scan can type is an edge to the
 * property. An untyped receiver, a chained fetch and the other languages
 * record no edge: the class's methods may read the property, and a walk over
 * dependants that met the property stopped there, so a property typed with a
 * much-used class read as untested however much its class is tested.
 *
 * Here `Holder::$helper` holds a `Helper`; `HolderTest` constructs a
 * `Holder` and runs it.
 */
final class PropertyReachTest extends KnossosTestCase
{
    /** @return array{0: PDO, 1: string, 2: string} */
    private function holding(): array
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        $php = static fn(string $body): string => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\n{$body}\n";
        file_put_contents($root . '/src/Core/Helper.php', $php("final class Helper\n{\n    public function shine(string \$text): string\n    {\n        return '*' . \$text . '*';\n    }\n}"));
        file_put_contents($root . '/src/Core/Holder.php', $php("final class Holder\n{\n    private Helper \$helper;\n\n    public function run(\$holder): string\n    {\n        return \$holder->helper->shine('held');\n    }\n\n    public function typed(): string\n    {\n        return \$this->helper->shine('typed');\n    }\n}"));
        file_put_contents($root . '/tests/HolderTest.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests;\n\nuse App\\Holder;\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class HolderTest extends TestCase\n{\n    public function testRuns(): void\n    {\n        \$holder = new Holder();\n        self::assertSame('*held*', \$holder->run(\$holder));\n    }\n}\n");
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

        return [$pdo, $projectId, $root];
    }

    private static function id(PDO $pdo, string $projectId, string $name): string
    {
        $statement = $pdo->prepare('SELECT id FROM nodes WHERE project_id = ? AND canonical_name = ?');
        $statement->execute([$projectId, $name]);
        return (string) $statement->fetchColumn();
    }

    #[Group('query')]
    public function testATestOfTheClassReachesItsProperty(): void
    {
        [$pdo, $projectId, $root] = $this->holding();
        try {
            $property = self::id($pdo, $projectId, 'App\\Holder::$helper');
            assertNotSame('', $property);
            $found = (new TestReachSearch($pdo))->search($projectId, [$property], 4, AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, 1, hrtime(true) + 5_000_000_000);
            assertSame(1, count($found['tests']));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testImpactAnalysisOfAPropertyReachesWhatUsesItsClassAsProbable(): void
    {
        [$pdo, $projectId, $root] = $this->holding();
        try {
            $dependants = ArchitectureQueryService::forDatabase($pdo)->impactAnalysis($projectId, 'App\\Holder::$helper')->data['dependants'];
            $byName = array_column(array_map(static fn(array $d): array => ['name' => $d['node']['canonical_name'], 'd' => $d], $dependants), 'd', 'name');
            assertSame('probable', $byName['App\\Tests\\HolderTest::testRuns']['path_confidence'] ?? null);
            // The class is passed through, never listed for its own property.
            assertNotContains('App\\Holder', array_keys($byName));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testATypedReadOfAPropertyIsADirectDependantAndAChainedOneIsNot(): void
    {
        [$pdo, $projectId, $root] = $this->holding();
        try {
            $property = self::id($pdo, $projectId, 'App\\Holder::$helper');
            $dependants = ArchitectureQueryService::forDatabase($pdo)->impactAnalysis($projectId, 'App\\Holder::$helper')->data['dependants'];
            $byName = [];
            foreach ($dependants as $d) {
                $byName[$d['node']['canonical_name']] = $d;
            }
            // The typed read is its own references edge to the property, not a stand-in hop through the class.
            assertSame('references', $byName['App\\Holder::typed']['via']['kind'] ?? null);
            assertSame($property, $byName['App\\Holder::typed']['via']['target_id'] ?? null);
            assertSame('read', $byName['App\\Holder::typed']['via']['attributes']['access'] ?? null);
            // The chained read records no edge, so the owner fallback is the only route to the class's users.
            assertNotContains('App\\Holder::run', array_keys($byName));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testTheBlastRadiusCountsAPropertyOfATestedClassTested(): void
    {
        [$pdo, , $root] = $this->holding();
        try {
            $tested = [];
            foreach ((new BlastRadiusService($pdo))->rings($root, 'App\\Helper')['rings'] as $ring) {
                foreach ($ring['items'] as $item) {
                    $tested[$item['canonical_name']] = $item['tested'];
                }
            }
            assertContains('App\\Holder::$helper', array_keys($tested));
            assertSame(true, $tested['App\\Holder::$helper']);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
