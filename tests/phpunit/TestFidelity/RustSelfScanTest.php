<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\WorkerClients;
use PDO;

use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * A real scan of a copy of the Rust worker, whose own cargo suite is the
 * nearest healthy Rust test code at hand: every definite test finding it
 * gives must be true, and none is. Its tests assert on what the worker
 * answers, and it holds no test double (its mockall and fake sources are
 * string fixtures), so the list is a verdict on its test cases and their
 * assertions. The copy leaves out `target/`, `bin/` and `mutants.out/`,
 * which are build output, not source.
 *
 * The same check over read-only copies of two private Cargo workspaces
 * (228 and 75 test cases, two hand-written fakes) gave no finding either;
 * those copies are not part of this repository. That scan found every
 * `mod name;` loading `name/mod.rs` taken for an orphan, its tests unread,
 * which `a_module_in_its_own_mod_rs_is_no_orphan` now covers.
 */
#[Group('test-fidelity')]
#[Group('rust-scanner')]
final class RustSelfScanTest extends KnossosTestCase
{
    use WorkerClients;

    private const BUILD_OUTPUT = ['target', 'bin', 'mutants.out'];

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
    }

    public function testTheWorkersOwnSuiteGivesOnlyTrueDefinites(): void
    {
        $root = self::scratchPath('rust-self-scan');
        $this->copySource(self::repositoryRoot() . '/workers/rust', $root);
        $pdo = $this->freshTestDatabase();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

        $definite = $pdo->query(
            "SELECT f.category || '/' || f.sub_rule || '@' || fl.relative_path || ':' || f.start_line FROM test_findings f "
            . 'JOIN projects p ON p.id = f.project_id AND f.last_scan_id = p.active_scan_id JOIN files fl ON fl.id = f.file_id '
            . "WHERE f.confidence = 'definite' ORDER BY 1",
        )->fetchAll(PDO::FETCH_COLUMN);
        $assertions = 0;
        foreach ($pdo->query("SELECT attributes_json FROM nodes WHERE language = 'rust' AND kind = 'test_case'")->fetchAll(PDO::FETCH_COLUMN) as $attributes) {
            $assertions += count(json_decode((string) $attributes, true, 512, JSON_THROW_ON_ERROR)['assertions'] ?? []);
        }

        assertSame([], $definite);
        // The scan read the suite's test facts, so the empty list is a verdict on them.
        assertGreaterThan(350, (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE language = 'rust' AND kind = 'test_case'")->fetchColumn());
        assertGreaterThan(900, $assertions);
    }

    /** Copy the worker's sources, without the build output beside them. */
    private function copySource(string $from, string $to): void
    {
        $filter = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $item, string $path, \RecursiveDirectoryIterator $iterator): bool => $iterator->getSubPath() !== ''
                || !in_array($item->getFilename(), self::BUILD_OUTPUT, true),
        );
        foreach (new \RecursiveIteratorIterator($filter) as $item) {
            $destination = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            @mkdir(dirname($destination), 0o777, true);
            copy($item->getPathname(), $destination);
        }
    }
}
