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
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertSame;

/**
 * A call through an interface reaches every implementation: `Uses::run`
 * calls `Reads::read`, which `Fancy::read` fulfils, and `Fancy::read` is the
 * only user of `Helper`.
 */
final class DispatchReachTest extends KnossosTestCase
{
    /** @return array{0: PDO, 1: string, 2: string} */
    private function dispatching(): array
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        $php = static fn(string $body): string => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\n{$body}\n";
        file_put_contents($root . '/src/Core/Reads.php', $php("interface Reads\n{\n    public function read(): string;\n}"));
        file_put_contents($root . '/src/Core/Plain.php', $php("final class Plain implements Reads\n{\n    public function read(): string\n    {\n        return 'plain';\n    }\n}"));
        file_put_contents($root . '/src/Core/Fancy.php', $php("final class Fancy implements Reads\n{\n    public function read(): string\n    {\n        return Helper::shine('fancy');\n    }\n}"));
        file_put_contents($root . '/src/Core/Helper.php', $php("final class Helper\n{\n    public static function shine(string \$text): string\n    {\n        return '*' . \$text . '*';\n    }\n}"));
        file_put_contents($root . '/src/Core/Uses.php', $php("final class Uses\n{\n    public function run(Reads \$reads): string\n    {\n        return \$reads->read();\n    }\n}"));
        file_put_contents($root . '/tests/UsesTest.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests;\n\nuse App\\Plain;\nuse App\\Uses;\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class UsesTest extends TestCase\n{\n    public function testRuns(): void\n    {\n        self::assertSame('plain', (new Uses())->run(new Plain()));\n    }\n}\n");
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

        return [$pdo, $projectId, $root];
    }

    /**
     * A scanned fixture with `$files` (path => PHP body under `namespace App;`, or a whole file for `tests/`).
     *
     * @param array<string, string> $files
     * @return array{0: PDO, 1: string, 2: string}
     */
    private function scanned(array $files): array
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        foreach ($files as $path => $body) {
            file_put_contents($root . '/' . $path, str_starts_with($path, 'tests/') ? $body : "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\n{$body}\n");
        }
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

        return [$pdo, $projectId, $root];
    }

    /**
     * `Fancy::read` overrides `Base::read`; `Base::read` is a dependant of `Helper::shine` in
     * its own right, through `Other::x`. Passing it through for `Fancy::read` must not hide it.
     *
     * @return array{0: PDO, 1: string, 2: string}
     */
    private function overriding(): array
    {
        $test = static fn(string $class, string $extends, string $call, string $abstract = ''): string => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests;\n\nuse App\\Helper;\nuse App\\Other;\n\n{$abstract}class {$class} extends {$extends}\n{\n    public function testRun(): void\n    {\n        self::assertNotSame('', {$call});\n    }\n}\n";

        return $this->scanned([
            'src/Core/Helper.php' => "final class Helper\n{\n    public static function shine(string \$text): string\n    {\n        return '*' . \$text . '*';\n    }\n}",
            'src/Core/Other.php' => "final class Other\n{\n    public static function x(): string\n    {\n        return Helper::shine('x');\n    }\n}",
            'src/Core/Base.php' => "class Base\n{\n    public function read(): string\n    {\n        return Other::x();\n    }\n}",
            'src/Core/Fancy.php' => "final class Fancy extends Base\n{\n    public function read(): string\n    {\n        return Helper::shine('fancy');\n    }\n}",
            'tests/BaseCase.php' => $test('BaseCase', '\\PHPUnit\\Framework\\TestCase', 'Other::x()', 'abstract '),
            'tests/ChildTest.php' => $test('ChildTest', 'BaseCase', "Helper::shine('c')"),
        ]);
    }

    #[Group('query')]
    public function testAParentMethodPassedThroughIsStillFoundWhenItDependsInItsOwnRight(): void
    {
        [$pdo, $projectId, $root] = $this->overriding();
        try {
            $impact = ArchitectureQueryService::forDatabase($pdo)->impactAnalysis($projectId, 'App\\Helper::shine')->data['dependants'];
            $distances = array_column(array_map(static fn(array $d): array => ['name' => $d['node']['canonical_name'], 'distance' => $d['distance']], $impact), 'distance', 'name');
            assertSame(2, $distances['App\\Base::read'] ?? null);
            $listed = [];
            foreach ((new BlastRadiusService($pdo))->rings($root, 'App\\Helper::shine')['rings'] as $ring) {
                array_push($listed, ...array_column($ring['items'], 'canonical_name'));
            }
            assertContains('App\\Base::read', $listed);
            $tests = ArchitectureQueryService::forDatabase($pdo)->testImpact($projectId, files: ['src/Core/Helper.php'])->data['test_files'];
            assertContains('tests/BaseCase.php', array_column($tests, 'path'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * `Reads::read` and `Fancy::read` both return a `Dto`: the interface's method is a
     * dependant in its own right, at the same hop. Its callers still count for `Fancy::read`.
     */
    #[Group('query')]
    public function testAnImplementationIsTestedThroughASupertypeMethodThatIsADependantToo(): void
    {
        [$pdo, , $root] = $this->scanned([
            'src/Core/Dto.php' => "final class Dto\n{\n}",
            'src/Core/Reads.php' => "interface Reads\n{\n    public function read(): Dto;\n}",
            'src/Core/Fancy.php' => "final class Fancy implements Reads\n{\n    public function read(): Dto\n    {\n        return new Dto();\n    }\n}",
            'src/Core/Uses.php' => "final class Uses\n{\n    public function run(Reads \$reads): Dto\n    {\n        return \$reads->read();\n    }\n}",
            'tests/UsesTest.php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests;\n\nuse App\\Fancy;\nuse App\\Uses;\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class UsesTest extends TestCase\n{\n    public function testRuns(): void\n    {\n        self::assertNotNull((new Uses())->run(new Fancy()));\n    }\n}\n",
        ]);
        try {
            $tested = [];
            foreach ((new BlastRadiusService($pdo))->rings($root, 'App\\Dto')['rings'] as $ring) {
                foreach ($ring['items'] as $item) {
                    $tested[$item['canonical_name']] = $item['tested'];
                }
            }
            assertSame(true, $tested['App\\Fancy::read'] ?? null);
        } finally {
            $this->removeTempTree($root);
        }
    }

    private static function id(PDO $pdo, string $projectId, string $name): string
    {
        $statement = $pdo->prepare('SELECT id FROM nodes WHERE project_id = ? AND canonical_name = ?');
        $statement->execute([$projectId, $name]);
        return (string) $statement->fetchColumn();
    }

    #[Group('query')]
    public function testATestCallingThroughTheInterfaceReachesWhatAnImplementationUses(): void
    {
        [$pdo, $projectId, $root] = $this->dispatching();
        try {
            $found = ArchitectureQueryService::forDatabase($pdo)->testImpact($projectId, files: ['src/Core/Helper.php']);
            assertContains('tests/UsesTest.php', array_column($found->data['test_files'], 'path'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testTheBlastRadiusReachesTheCallerThroughTheInterfaceAndCountsItTested(): void
    {
        [$pdo, , $root] = $this->dispatching();
        try {
            [$one, $two, $far] = (new BlastRadiusService($pdo))->rings($root, 'App\\Helper::shine')['rings'];
            assertSame([['App\\Fancy::read'], 1], [array_column($one['items'], 'canonical_name'), $one['tested']]);
            assertSame([['App\\Uses::run'], 1], [array_column($two['items'], 'canonical_name'), $two['tested']]);
            // The interface's method is passed through, never listed.
            $listed = [...array_column($one['items'], 'canonical_name'), ...array_column($two['items'], 'canonical_name'), ...array_column($far['items'], 'canonical_name')];
            assertNotContains('App\\Reads::read', $listed);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testImpactAnalysisReachesTheCallerThroughTheInterface(): void
    {
        [$pdo, $projectId, $root] = $this->dispatching();
        try {
            $queries = ArchitectureQueryService::forDatabase($pdo);
            $fromRead = $queries->impactAnalysis($projectId, 'App\\Fancy::read')->data['dependants'];
            $byName = array_column(array_map(static fn(array $d): array => ['name' => $d['node']['canonical_name'], 'd' => $d], $fromRead), 'd', 'name');
            assertSame(1, $byName['App\\Uses::run']['distance'] ?? null);
            // The hop names the edge it went by: the call into the interface's method.
            assertSame(self::id($pdo, $projectId, 'App\\Reads::read'), $byName['App\\Uses::run']['via']['target_id']);
            // Which implementation runs is a guess: the hop is probable at best, whatever the call's own confidence.
            assertSame('probable', $byName['App\\Uses::run']['path_confidence']);
            assertNotContains('App\\Reads::read', array_keys($byName));
            $fromHelper = $queries->impactAnalysis($projectId, 'App\\Helper::shine')->data['dependants'];
            $distances = array_column(array_map(static fn(array $d): array => ['name' => $d['node']['canonical_name'], 'distance' => $d['distance']], $fromHelper), 'distance', 'name');
            assertSame(2, $distances['App\\Uses::run'] ?? null);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Two implementations of one interface method, met at different hops:
     * `Fancy::read` uses `Helper` at once, `Plain::read` through `Shade`.
     * Each still reaches the caller behind the interface.
     */
    #[Group('query')]
    public function testEveryImplementationReachesTheCallerWhateverHopItIsMetAt(): void
    {
        [$pdo, $projectId, $root] = $this->dispatching();
        try {
            $php = static fn(string $body): string => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\n{$body}\n";
            file_put_contents($root . '/src/Core/Shade.php', $php("final class Shade\n{\n    public static function cast(): string\n    {\n        return Helper::shine('shade');\n    }\n}"));
            file_put_contents($root . '/src/Core/Plain.php', $php("final class Plain implements Reads\n{\n    public function read(): string\n    {\n        return Shade::cast();\n    }\n}"));
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $rings = (new BlastRadiusService($pdo))->rings($root, 'App\\Helper::shine')['rings'];
            $tested = [];
            foreach ($rings as $ring) {
                foreach ($ring['items'] as $item) {
                    $tested[$item['canonical_name']] = $item['tested'];
                }
            }
            assertSame(true, $tested['App\\Fancy::read'] ?? null);
            assertSame(true, $tested['App\\Plain::read'] ?? null);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testTheSupertypeReadsCountAgainstTheWalksEdgeBound(): void
    {
        [$pdo, $projectId, $root] = $this->dispatching();
        try {
            $search = (new TestReachSearch($pdo, null, 10_000, 1))->search($projectId, [self::id($pdo, $projectId, 'App\\Fancy::read')], 4, AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, 1, PHP_INT_MAX);
            assertSame(['edge_limit'], $search['truncation_reasons']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('flow')]
    public function testAFlowStepsFromTheInterfaceMethodIntoAnImplementation(): void
    {
        [$pdo, $projectId, $root] = $this->dispatching();
        try {
            $flow = ArchitectureQueryService::forDatabase($pdo)->explainFlow($projectId, 'App\\Uses::run', 'App\\Helper::shine');
            $paths = $flow->data['paths'];
            assertSame(1, count($paths));
            assertSame(['App\\Uses::run', 'App\\Reads::read', 'App\\Fancy::read', 'App\\Helper::shine'], array_column($paths[0]['nodes'], 'canonical_name'));
            $step = $paths[0]['hops'][1];
            assertSame('implemented_by', $step['kind']);
            assertSame('derived', $step['origin']);
            // Which implementation runs is a guess, so the step is probable at best.
            assertSame('probable', $step['confidence']);
            assertSame(null, $step['evidence']);
            // Plain::read implements it too but leads nowhere near the target.
            assertNotContains('App\\Plain::read', array_column($paths[0]['nodes'], 'canonical_name'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('flow')]
    public function testAFlowAskedForCertainHopsTakesNoImplementationStep(): void
    {
        [$pdo, $projectId, $root] = $this->dispatching();
        try {
            $flow = ArchitectureQueryService::forDatabase($pdo)->explainFlow($projectId, 'App\\Uses::run', 'App\\Helper::shine', minConfidence: 'certain');
            assertSame([], $flow->data['paths']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('flow')]
    public function testAFlowStepsFromAParentMethodIntoTheOverride(): void
    {
        [$pdo, $projectId, $root] = $this->scanned([
            'src/Core/Helper.php' => "final class Helper\n{\n    public static function shine(string \$text): string\n    {\n        return '*' . \$text . '*';\n    }\n}",
            'src/Core/Base.php' => "class Base\n{\n    public function read(): string\n    {\n        return 'base';\n    }\n}",
            'src/Core/Fancy.php' => "final class Fancy extends Base\n{\n    public function read(): string\n    {\n        return Helper::shine('fancy');\n    }\n}",
            'src/Core/Uses.php' => "final class Uses\n{\n    public function run(Base \$base): string\n    {\n        return \$base->read();\n    }\n}",
        ]);
        try {
            $flow = ArchitectureQueryService::forDatabase($pdo)->explainFlow($projectId, 'App\\Uses::run', 'App\\Helper::shine');
            assertSame(['calls', 'implemented_by', 'calls'], array_column($flow->data['paths'][0]['hops'] ?? [], 'kind'));
        } finally {
            $this->removeTempTree($root);
        }
    }
}
