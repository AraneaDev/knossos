<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\FileContextService;
use Knossos\Query\FileTestReach;
use Knossos\Query\QueryServices;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

use PHPUnit\Framework\Attributes\Group;

/**
 * A composition root constructs every service and does little else, so every
 * test that builds the application reaches every service through it. Such
 * tests are reported apart from those that reach the change by a call.
 *
 * `Wiring::wire` constructs six services, `Target` among them. `TargetTest`
 * calls `Target::run`; `AppTest` only builds the application.
 */
final class WiringReachTest extends KnossosTestCase
{
    /**
     * @param array<string, string> $extra more files, path => PHP body under `namespace App;`
     * @return array{0: PDO, 1: string, 2: string}
     */
    private function wired(array $extra = []): array
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        $php = static fn(string $body): string => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\n{$body}\n";
        $services = ['Target', 'Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'];
        foreach ($services as $service) {
            file_put_contents($root . "/src/Core/{$service}.php", $php("final class {$service}\n{\n    public function run(): string\n    {\n        return '{$service}';\n    }\n}"));
        }
        $built = implode('', array_map(static fn(string $service): string => "            new {$service}(),\n", $services));
        file_put_contents($root . '/src/Core/Wiring.php', $php("final class Wiring\n{\n    /** @return list<object> */\n    public static function wire(): array\n    {\n        return [\n{$built}        ];\n    }\n}"));
        foreach ($extra as $path => $body) {
            file_put_contents($root . '/' . $path, $php($body));
        }
        $test = static fn(string $class, string $use, string $call): string => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests;\n\nuse App\\{$use};\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class {$class} extends TestCase\n{\n    public function testIt(): void\n    {\n        self::assertNotEmpty({$call});\n    }\n}\n";
        file_put_contents($root . '/tests/TargetTest.php', $test('TargetTest', 'Target', '(new Target())->run()'));
        file_put_contents($root . '/tests/AppTest.php', $test('AppTest', 'Wiring', 'Wiring::wire()'));
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

        return [$pdo, $projectId, $root];
    }

    #[Group('query')]
    public function testATestReachingTheChangeOnlyThroughACompositionRootIsReportedApart(): void
    {
        [$pdo, $projectId, $root] = $this->wired();
        try {
            $found = ArchitectureQueryService::forDatabase($pdo)->testImpact($projectId, files: ['src/Core/Target.php']);
            assertSame(['tests/TargetTest.php'], array_column($found->data['test_files'], 'path'));
            assertSame(['tests/AppTest.php'], array_column($found->data['test_files_via_wiring'], 'path'));
            assertSame(1, $found->data['bounds']['test_files_via_wiring_found']);
            assertStringContainsString('1 more reach it only through a composition root', $found->summary);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testATestThatAlsoCallsTheChangeIsADirectOne(): void
    {
        [$pdo, $projectId, $root] = $this->wired([
            'src/Core/App.php' => "final class App\n{\n    public static function boot(): string\n    {\n        Wiring::wire();\n\n        return (new Target())->run();\n    }\n}",
        ]);
        try {
            file_put_contents($root . '/tests/BootTest.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests;\n\nuse App\\App;\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class BootTest extends TestCase\n{\n    public function testIt(): void\n    {\n        self::assertNotEmpty(App::boot());\n    }\n}\n");
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $found = ArchitectureQueryService::forDatabase($pdo)->testImpact($projectId, files: ['src/Core/Target.php']);
            assertSame(['tests/TargetTest.php', 'tests/BootTest.php'], array_column($found->data['test_files'], 'path'));
            assertSame(['tests/AppTest.php'], array_column($found->data['test_files_via_wiring'], 'path'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAChangeToTheCompositionRootItselfReachesItsTestsDirectly(): void
    {
        [$pdo, $projectId, $root] = $this->wired();
        try {
            $found = ArchitectureQueryService::forDatabase($pdo)->testImpact($projectId, files: ['src/Core/Wiring.php']);
            assertSame(['tests/AppTest.php'], array_column($found->data['test_files'], 'path'));
            assertSame([], $found->data['test_files_via_wiring']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testOneLimitCoversBothListsTheDirectFilesFirst(): void
    {
        [$pdo, $projectId, $root] = $this->wired();
        try {
            $found = ArchitectureQueryService::forDatabase($pdo)->testImpact($projectId, files: ['src/Core/Target.php'], limit: 1);
            assertSame(['tests/TargetTest.php'], array_column($found->data['test_files'], 'path'));
            assertSame([], $found->data['test_files_via_wiring']);
            assertSame(['result_limit'], $found->data['bounds']['truncation_reasons']);
            assertStringContainsString('The lists were truncated to the first 1 of 2 test files.', $found->summary);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testTheBriefsListTheDirectTestsFirstAndCountBoth(): void
    {
        [$pdo, $projectId, $root] = $this->wired();
        try {
            $listed = FileTestReach::testsOf(QueryServices::wire($pdo)->changeQueries, $projectId, ['src/Core/Target.php'], 5);
            assertSame(['tests/TargetTest.php', 'tests/AppTest.php'], array_column($listed['tests'], 'path'));
            assertSame(['src/Core/Target.php' => 2], (new FileTestReach($pdo))->reach($projectId, ['src/Core/Target.php']));
            $context = (new FileContextService($pdo))->context($root . '/src/Core/Target.php');
            assertSame(['tests/TargetTest.php', 'tests/AppTest.php'], array_column($context['file']['tests']['items'] ?? [], 'path'));
        } finally {
            $this->removeTempTree($root);
        }
    }
}
