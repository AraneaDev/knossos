<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\TestFidelity;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\TestFidelity\OnRequestFindings;
use Knossos\Scan\ProjectScanService;
use Knossos\TestFidelity\OnRequest\{OnRequestInput, OverMocking};
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * What the on-request rules read, loaded from a graph a real worker scanned,
 * and `OVER_MOCKING` over it against Momus's goldens (`test/golden/audit.test.ts`):
 * MOCK-001 at line 1 of `test_patch_imports.py` and `test_patch_missing.py`,
 * and of Rust's `tests/mock_echo.rs` (preflight F1 puts it in place of
 * `gateway_mock.rs`, which holds no double Knossos can see).
 */
#[Group('test-fidelity')]
final class OnRequestFindingsTest extends KnossosTestCase
{
    #[Group('python-scanner')]
    public function testPythonStringPatchesAndAFunctionLocalImport(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('test-fidelity/python/momus-drift');
        try {
            $input = (new OnRequestFindings($pdo))->load($projectId);
            // The worker emits the function-local `from datetime import timedelta` as the
            // module's import; it is local, so it is no dependency (Momus reads module level only).
            self::assertContains(['module' => 'external:datetime', 'name' => 'datetime', 'local' => true], $input->imports['test_patch_imports.py']);
            self::assertContains(['module' => 'external:unittest.mock', 'name' => 'unittest.mock', 'local' => false], $input->imports['test_patch_imports.py']);
            // `patch("prod_imports.idna")` doubles the module prod_imports, which the file never imports.
            self::assertSame(['module' => 'prod_imports.py', 'name' => 'prod_imports'], $input->doubles['test_patch_imports.py'][0]);
            self::assertSame('python', $input->testFiles['test_patch_imports.py']);
            self::assertFalse($input->partial);

            $items = ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries->auditTests($projectId, tier: 'on_request')->data['items'];
            $found = array_map(static fn(array $item): array => [$item['file'], $item['line'], $item['message']], $items);
            self::assertContains(['test_patch_imports.py', 1, '1/1 dependencies mocked, 0 production assertions: over-mocked'], $found);
            self::assertContains(['test_patch_missing.py', 1, '1/1 dependencies mocked, 0 production assertions: over-mocked'], $found);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testRustMockEchoIsOverMocked(): void
    {
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
        [$pdo, $projectId, $root] = $this->scanTempFixture('test-fidelity/rust/tautology');
        try {
            $input = (new OnRequestFindings($pdo))->load($projectId);
            // `use tautology::clock::{elapsed, Clock, MockClock}`: three items of one module, one import.
            self::assertSame([['module' => 'src/clock.rs', 'name' => 'crate::clock', 'local' => false]], $input->imports['tests/mock_echo.rs']);
            self::assertSame(['module' => 'src/clock.rs', 'name' => 'Clock'], $input->doubles['tests/mock_echo.rs'][0]);

            $items = ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries->auditTests($projectId, 'tests/mock_echo.rs', tier: 'on_request')->data['items'];
            self::assertSame([['tests/mock_echo.rs', 1, '1/1 dependencies mocked, 1 production assertions: over-mocked']], array_map(static fn(array $item): array => [$item['file'], $item['line'], $item['message']], $items));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The local-import rule reads Python only: a Rust `use` inside a
     * `#[test] fn` is the module's dependency, as the Rust worker reports it.
     */
    public function testARustUseInsideATestFunctionIsADependency(): void
    {
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
        $root = self::scratchPath('knossos-stale-rust-local');
        $this->writeTree($root, [
            'Cargo.toml' => "[package]\nname = \"shape\"\nversion = \"0.1.0\"\nedition = \"2021\"\n",
            'src/lib.rs' => "pub mod clock;\n",
            'src/clock.rs' => "pub struct Wall;\nimpl Wall {\n    pub fn tick(&self) -> u64 {\n        1\n    }\n}\n",
            'tests/inner_test.rs' => "#[test]\nfn ticks() {\n    use shape::clock::Wall;\n    assert_eq!(Wall.tick(), 1);\n}\n",
        ]);
        $pdo = $this->freshTestDatabase();
        $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;

        $input = (new OnRequestFindings($pdo))->load($projectId);
        self::assertSame([['module' => 'src/clock.rs', 'name' => 'crate::clock', 'local' => false]], $input->imports['tests/inner_test.rs']);
        // So with one other module doubled, half the file's dependencies are mocked: not over-mocked.
        $file = 'tests/inner_test.rs';
        $doubled = new OnRequestInput($input->testFiles, $input->imports, [$file => [['module' => 'src/other.rs', 'name' => 'Other']]], []);
        self::assertSame([], OverMocking::findings($doubled));
        // Read as local, the `use` would leave the double the file's only dependency.
        $local = new OnRequestInput($input->testFiles, [$file => [['local' => true] + $input->imports[$file][0]]], $doubled->doubles, []);
        self::assertCount(1, OverMocking::findings($local));
    }

    /**
     * A double's target lives in its own file, its children's, and, for a
     * member the double stubs, the file of a type it uses or extends: a PHP
     * trait's method is the trait's child, not the class's.
     */
    public function testATargetLivesWhereItsMembersAre(): void
    {
        $root = self::scratchPath('knossos-stale-php-trait');
        $this->writeTree($root, [
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}},"autoload-dev":{"psr-4":{"Tests\\\\":"tests/"}}}',
            'src/Greets.php' => "<?php\nnamespace App;\ntrait Greets { public function greet(): string { return 'hi'; } public function wave(): void {} }\n",
            'src/Base.php' => "<?php\nnamespace App;\nclass Base { public function id(): int { return 1; } }\n",
            'src/Greeter.php' => "<?php\nnamespace App;\nclass Greeter extends Base { use Greets; public function name(): string { return 'x'; } }\n",
            'tests/GreeterTest.php' => "<?php\nnamespace Tests;\nuse App\\Greeter;\nuse PHPUnit\\Framework\\TestCase;\nfinal class GreeterTest extends TestCase {\n    public function testGreets(): void {\n        \$g = \$this->createMock(Greeter::class);\n        \$g->method('greet')->willReturn('yo');\n        \$g->method('id')->willReturn(2);\n        self::assertSame('yo', \$g->greet());\n    }\n}\n",
        ]);
        $pdo = $this->freshTestDatabase();
        $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;

        $input = (new OnRequestFindings($pdo))->load($projectId, changed: ['src/Greets.php']);
        self::assertSame(['src/Greets.php'], $input->changed);
        self::assertSame([[
            'double' => 'createMock App\\Greeter',
            'line' => 7,
            'target' => 'Greeter',
            'canonical' => 'App\\Greeter',
            'files' => ['src/Greeter.php'],
            'inherited' => ['id' => ['src/Base.php'], 'greet' => ['src/Greets.php'], 'wave' => ['src/Greets.php']],
            'members' => ['greet', 'id'],
        ]], $input->targets['tests/GreeterTest.php']);
    }

    public function testAProjectWithNoTestsLoadsNothing(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('nest');
        try {
            $input = (new OnRequestFindings($pdo))->load($projectId);

            self::assertSame([[], [], [], [], false], [$input->testFiles, $input->imports, $input->doubles, $input->assertions, $input->partial]);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Write each file under `$root`, making its folders.
     *
     * @param array<string, string> $files relative path => content
     */
    private function writeTree(string $root, array $files): void
    {
        foreach ($files as $path => $content) {
            if (!is_dir(dirname($root . '/' . $path))) {
                mkdir(dirname($root . '/' . $path), 0o777, true);
            }
            file_put_contents($root . '/' . $path, $content);
        }
    }
}
