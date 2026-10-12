<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\BlastRadiusService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertSame;

/**
 * `new Collector()` runs `Collector::__construct`, but the graph records it as
 * a `constructs` edge into the class, never into the constructor. A walk over
 * dependants that met the constructor stopped there: what only a constructor
 * uses read as reached by nothing, and no test reached it however much its
 * class is tested.
 *
 * Here `Collector::__construct` is the only user of `Helper`; `Runner::run`
 * constructs a `Collector`, and `Other::go` a `Child` that inherits the
 * constructor. `Typed::take` names `Collector` as a type and constructs
 * nothing, so it never runs the constructor.
 */
final class ConstructorReachTest extends KnossosTestCase
{
    /** @return array{0: PDO, 1: string, 2: string} */
    private function constructing(): array
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        $php = static fn(string $body): string => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\n{$body}\n";
        $test = static fn(string $class, string $call): string => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests;\n\nuse App\\Other;\nuse App\\Runner;\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class {$class} extends TestCase\n{\n    public function testRuns(): void\n    {\n        self::assertNotSame('', {$call});\n    }\n}\n";
        $files = [
            'src/Core/Helper.php' => $php("final class Helper\n{\n    public static function shine(string \$text): string\n    {\n        return '*' . \$text . '*';\n    }\n}"),
            'src/Core/Collector.php' => $php("class Collector\n{\n    public string \$text;\n\n    public function __construct()\n    {\n        \$this->text = Helper::shine('collected');\n    }\n}"),
            'src/Core/Child.php' => $php("final class Child extends Collector\n{\n}"),
            'src/Core/Runner.php' => $php("final class Runner\n{\n    public function run(): string\n    {\n        return (new Collector())->text;\n    }\n}"),
            'src/Core/Other.php' => $php("final class Other\n{\n    public static function go(): string\n    {\n        return (new Child())->text;\n    }\n}"),
            'src/Core/Typed.php' => $php("final class Typed\n{\n    public static function take(Collector \$collector): string\n    {\n        return \$collector->text;\n    }\n}"),
            'tests/RunnerTest.php' => $test('RunnerTest', '(new Runner())->run()'),
            'tests/OtherTest.php' => $test('OtherTest', 'Other::go()'),
        ];
        foreach ($files as $path => $source) {
            file_put_contents($root . '/' . $path, $source);
        }
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

        return [$pdo, $projectId, $root];
    }

    #[Group('query')]
    public function testATestThatConstructsAClassReachesWhatItsConstructorUses(): void
    {
        [$pdo, $projectId, $root] = $this->constructing();
        try {
            $found = ArchitectureQueryService::forDatabase($pdo)->testImpact($projectId, files: ['src/Core/Helper.php']);
            $paths = array_column($found->data['test_files'], 'path');
            assertContains('tests/RunnerTest.php', $paths);
            // A subclass without a constructor of its own runs its parent's.
            assertContains('tests/OtherTest.php', $paths);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testImpactAnalysisReachesWhatConstructsTheClassAndNothingThatOnlyNamesIt(): void
    {
        [$pdo, $projectId, $root] = $this->constructing();
        try {
            $dependants = ArchitectureQueryService::forDatabase($pdo)->impactAnalysis($projectId, 'App\\Helper::shine')->data['dependants'];
            $distances = array_column(array_map(static fn(array $d): array => ['name' => $d['node']['canonical_name'], 'distance' => $d['distance']], $dependants), 'distance', 'name');
            assertSame(1, $distances['App\\Collector::__construct'] ?? null);
            assertSame(2, $distances['App\\Runner::run'] ?? null);
            assertSame(2, $distances['App\\Other::go'] ?? null);
            // A type in a signature constructs nothing, and the class is passed through, never listed for it.
            assertNotContains('App\\Typed::take', array_keys($distances));
            assertNotContains('App\\Collector', array_keys($distances));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testTheBlastRadiusReachesWhatConstructsTheClassAndCountsItTested(): void
    {
        [$pdo, , $root] = $this->constructing();
        try {
            $tested = [];
            foreach ((new BlastRadiusService($pdo))->rings($root, 'App\\Helper::shine')['rings'] as $ring) {
                foreach ($ring['items'] as $item) {
                    $tested[$item['canonical_name']] = $item['tested'];
                }
            }
            assertSame(true, $tested['App\\Runner::run'] ?? null);
            assertSame(true, $tested['App\\Other::go'] ?? null);
            assertSame(true, $tested['App\\Collector::__construct'] ?? null);
            assertNotContains('App\\Typed::take', array_keys($tested));
        } finally {
            $this->removeTempTree($root);
        }
    }
}
