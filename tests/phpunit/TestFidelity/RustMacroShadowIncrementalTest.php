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
 * depends on every file of the crate: one that starts to define a
 * `macro_rules! dbg` shadows the standard one. The test file reads them all,
 * so an incremental scan after that edit drops the finding a fresh scan does
 * not give (re-review 3, I4).
 */
#[Group('test-fidelity')]
final class RustMacroShadowIncrementalTest extends KnossosTestCase
{
    use WorkerClients;

    private const LIB = "#[macro_use]\nmod macros;\n\npub const LIMIT: u32 = 10;\n\n"
        . "#[cfg(test)]\nmod tests {\n    use super::*;\n    #[test]\n    fn shadowed_dbg() {\n"
        . "        dbg!();\n        assert_eq!(LIMIT, 20);\n    }\n}\n";

    private const SHADOWING = "macro_rules! dbg {\n    () => {\n        const LIMIT: u32 = 20;\n    };\n}\n";

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
        $this->root = self::scratchPath('rust-macro-shadow-incremental');
        mkdir($this->root . '/src', 0o777, true);
        file_put_contents($this->root . '/Cargo.toml', "[package]\nname = \"stale\"\nversion = \"0.1.0\"\nedition = \"2021\"\n");
        file_put_contents($this->root . '/src/lib.rs', self::LIB);
    }

    public function testAMacroAddedInAnotherFileReachesTheCachedTest(): void
    {
        file_put_contents($this->root . '/src/macros.rs', "pub fn nothing() {}\n");
        $pdo = $this->freshTestDatabase();
        $service = new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]);
        $service->scan($this->root);
        // The standard `dbg!` declares nothing: `LIMIT` is 10, never 20.
        assertSame(['TAUTOLOGY/constant/definite@src/lib.rs:12'], $this->findings($pdo));

        file_put_contents($this->root . '/src/macros.rs', self::SHADOWING);
        $service->scan($this->root);
        $incremental = $this->findings($pdo);

        $fresh = $this->freshTestDatabase();
        (new ProjectScanService($fresh, self::repositoryRoot(), [$this->root]))->scan($this->root);
        assertSame([], $this->findings($fresh));
        assertSame([], $incremental);
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
