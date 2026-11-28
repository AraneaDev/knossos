<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * A real scan of a copy of the Python worker, whose own pytest suite is the
 * nearest healthy Python test code at hand: every definite test finding it
 * gives must be true, and none is. Its doubles are `monkeypatch.setattr`
 * calls on what fixtures hand in, and its tests assert on what the worker
 * returns, so the list is empty. The same check over a read-only copy of a
 * private service's suite (src layout, `monkeypatch`) gave no finding
 * either; that copy is not part of this repository.
 */
#[Group('test-fidelity')]
#[Group('python-scanner')]
final class PythonSelfScanTest extends KnossosTestCase
{
    #[Group('slow')]
    public function testTheWorkersOwnSuiteGivesOnlyTrueDefinites(): void
    {
        $root = self::scratchPath('python-self-scan');
        $this->copyTree(self::repositoryRoot() . '/workers/python', $root);
        $pdo = $this->freshTestDatabase();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

        $definite = $pdo->query(
            "SELECT f.category || '/' || f.sub_rule || '@' || fl.relative_path || ':' || f.start_line FROM test_findings f "
            . 'JOIN projects p ON p.id = f.project_id AND f.last_scan_id = p.active_scan_id JOIN files fl ON fl.id = f.file_id '
            . "WHERE f.confidence = 'definite' ORDER BY 1",
        )->fetchAll(PDO::FETCH_COLUMN);
        $count = static fn(string $kind): int => (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE language = 'py' AND kind = '{$kind}'")->fetchColumn();

        assertSame([], $definite);
        // The scan read the suite's test facts, so the empty list is a verdict on them.
        assertGreaterThan(0, $count('test_double'));
        assertGreaterThan(200, $count('test_case'));
    }
}
