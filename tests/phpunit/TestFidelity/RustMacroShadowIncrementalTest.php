<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\WorkerClients;
use PDO;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * Whether `dbg!();` in a Rust test may declare the name the assertion reads
 * depends on every file of the package: one that starts to define a
 * `macro_rules! dbg` shadows the standard one. While the answer is that it
 * declares nothing, the test file reads them all, every module a `mod`
 * declaration names, and every file a `#[path]` places outside the module
 * tree, so an incremental scan after that edit drops the finding a fresh
 * scan does not give (re-review 3, I4; re-review 5, I6).
 */
#[Group('test-fidelity')]
final class RustMacroShadowIncrementalTest extends KnossosTestCase
{
    use WorkerClients;

    private const MANIFEST = "[package]\nname = \"stale\"\nversion = \"0.1.0\"\nedition = \"2021\"\n";

    private const LIB = "#[macro_use]\nmod macros;\n\npub const LIMIT: u32 = 10;\n\n"
        . "#[cfg(test)]\nmod tests {\n    use super::*;\n    #[test]\n    fn shadowed_dbg() {\n"
        . "        dbg!();\n        assert_eq!(LIMIT, 20);\n    }\n}\n";

    private const NOTHING = "#[allow(dead_code)]\npub fn nothing() {}\n";

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
        $this->root = self::scratchPath('rust-macro-shadow-incremental');
    }

    public function testAMacroAddedInAnotherFileReachesTheCachedTest(): void
    {
        $this->assertTheEditReachesTheCachedTest(
            ['Cargo.toml' => self::MANIFEST, 'src/lib.rs' => self::LIB, 'src/macros.rs' => "pub fn nothing() {}\n"],
            'src/macros.rs',
            self::shadowing('dbg'),
            ['TAUTOLOGY/constant/definite@src/lib.rs:12'],
        );
    }

    /**
     * `mod macros;` is declared but its file is missing at the first scan
     * (re-review 4, deferred note 1): creating it reaches the test too.
     */
    public function testAMacroInAFileCreatedLaterReachesTheCachedTest(): void
    {
        $this->assertTheEditReachesTheCachedTest(
            ['Cargo.toml' => self::MANIFEST, 'src/lib.rs' => self::LIB],
            'src/macros.rs',
            self::shadowing('dbg'),
            ['TAUTOLOGY/constant/definite@src/lib.rs:12'],
        );
    }

    /** rr5/inc/keying2: a `#[path]` file outside `src/` starts to define `todo`. */
    public function testAPathFileOutsideTheModuleTreeReachesTheCachedTest(): void
    {
        $lib = "#[macro_use]\n#[path = \"../macros/todo.rs\"]\nmod outside;\n\npub const LIMIT: u32 = 10;\n\n"
            . "#[cfg(test)]\nmod tests {\n    use super::*;\n\n    #[test]\n    fn placed_outside_src() {\n"
            . "        todo!();\n        assert_eq!(LIMIT, 20);\n    }\n}\n";
        $this->assertTheEditReachesTheCachedTest(
            ['Cargo.toml' => self::MANIFEST, 'src/lib.rs' => $lib, 'macros/todo.rs' => self::NOTHING],
            'macros/todo.rs',
            self::shadowing('todo'),
            ['TAUTOLOGY/constant/definite@src/lib.rs:14'],
        );
    }

    /** rr5/inc/keying: an integration test loads the same file by `#[path]`. */
    public function testAPathFileOutsideTheModuleTreeReachesACachedIntegrationTest(): void
    {
        $it = "#[macro_use]\n#[path = \"../src/shared.rs\"]\nmod shared;\n\n"
            . "#[macro_use]\n#[path = \"../macros/todo.rs\"]\nmod outside;\n\nconst LIMIT: u32 = 10;\n\n"
            . "#[test]\nfn shared_with_the_library() {\n    dbg!();\n    assert_eq!(LIMIT, 20);\n}\n\n"
            . "#[test]\nfn placed_outside_src_and_tests() {\n    todo!();\n    assert_eq!(LIMIT, 20);\n}\n";
        $this->assertTheEditReachesTheCachedTest(
            [
                'Cargo.toml' => self::MANIFEST,
                'src/lib.rs' => "#[macro_use]\n#[allow(unused_macros)]\nmod shared;\n\npub const LIMIT: u32 = 10;\n",
                'src/shared.rs' => "#[allow(unused_macros)]\n" . self::shadowing('dbg'),
                'tests/it.rs' => $it,
                'macros/todo.rs' => self::NOTHING,
            ],
            'macros/todo.rs',
            self::shadowing('todo'),
            ['TAUTOLOGY/constant/definite@tests/it.rs:20'],
        );
    }

    /** rr5/ws m_path_out: a member loads a file no manifest owns, outside its package. */
    public function testAnUnownedPathFileReachesTheCachedTestOfAMember(): void
    {
        $lib = "#[macro_use]\n#[path = \"../../shared/m.rs\"]\nmod outside;\n\npub const LIMIT: u32 = 10;\n\n"
            . "#[cfg(test)]\nmod tests {\n    use super::*;\n\n    #[test]\n    fn path_outside_package() {\n"
            . "        dbg!();\n        assert_eq!(LIMIT, 20);\n    }\n}\n";
        $this->assertTheEditReachesTheCachedTest(
            [
                'Cargo.toml' => "[workspace]\nresolver = \"2\"\nmembers = [\"m_path_out\"]\n",
                'm_path_out/Cargo.toml' => "[package]\nname = \"m_path_out\"\nversion = \"0.1.0\"\nedition = \"2021\"\n",
                'm_path_out/src/lib.rs' => $lib,
                'shared/m.rs' => self::NOTHING,
            ],
            'shared/m.rs',
            self::shadowing('dbg'),
            ['TAUTOLOGY/constant/definite@m_path_out/src/lib.rs:14'],
        );
    }

    /** A `macro_rules! NAME` that declares the `LIMIT` the test reads. */
    private static function shadowing(string $name): string
    {
        return "macro_rules! {$name} {\n    () => {\n        const LIMIT: u32 = 20;\n    };\n}\n";
    }

    /**
     * Write `files`, scan, write `after` to `edited`, and compare an
     * incremental scan with a fresh one: both give no finding.
     *
     * @param array<string, string> $files
     * @param list<string> $before The findings of the first scan.
     */
    private function assertTheEditReachesTheCachedTest(array $files, string $edited, string $after, array $before): void
    {
        foreach ($files as $path => $content) {
            $this->write($path, $content);
        }
        $pdo = $this->freshTestDatabase();
        $service = new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]);
        $service->scan($this->root);
        // The standard macro declares nothing: `LIMIT` is 10, never 20.
        assertSame($before, $this->findings($pdo));

        $this->write($edited, $after);
        $service->scan($this->root);
        $incremental = $this->findings($pdo);

        $fresh = $this->freshTestDatabase();
        (new ProjectScanService($fresh, self::repositoryRoot(), [$this->root]))->scan($this->root);
        assertSame([], $this->findings($fresh));
        assertSame([], $incremental);
    }

    /** Write one file under the root, creating its directory. */
    private function write(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $content);
    }

    /** @return list<string> The active scan's findings, as `category/sub_rule/confidence@path:line`. */
    private function findings(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT f.category, f.sub_rule, f.confidence, fl.relative_path, f.start_line FROM test_findings f '
            . 'JOIN projects p ON p.id = f.project_id AND f.last_scan_id = p.active_scan_id JOIN files fl ON fl.id = f.file_id',
        )->fetchAll(PDO::FETCH_NUM);
        $findings = array_map(static fn(array $row): string => vsprintf('%s/%s/%s@%s:%d', $row), $rows);
        sort($findings);

        return $findings;
    }
}
