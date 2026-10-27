<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PDOStatement;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * file_context reads the edges of the file it was asked about, seeking each
 * of its components, never every edge of the project.
 *
 * The dependent-file count, the dependents' boundaries and the walk to the
 * tests that reach the file each read the project's edges through the kind
 * index and only then kept the file's: on a scan of this repository (100,000
 * edges) file_context took about 0.5 s a file, against about 0.05 s once each
 * read starts from the file. The file's components were found the same way,
 * from every node of the project. Counting every boundary's members by
 * grouping a join sorted all 52,000 memberships; counted per boundary it
 * reads the index.
 */
final class FileContextPlanTest extends KnossosTestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-file-context-plan-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o777, true);
        mkdir($this->root . '/tests', 0o777, true);
        file_put_contents($this->root . '/src/Ledger.php', "<?php\nnamespace App;\nfinal class Ledger { public function total(): int { return 1; } }\n");
        file_put_contents($this->root . '/src/Checkout.php', "<?php\nnamespace App;\nfinal class Checkout { public function run(Ledger \$ledger): int { return \$ledger->total(); } }\n");
        file_put_contents($this->root . '/src/tally.php', "<?php\nnamespace App;\nfunction tally(): int { return 2; }\n");
        file_put_contents($this->root . '/src/Report.php', "<?php\nnamespace App;\nfinal class Report { public function run(): int { return tally(); } }\n");
        file_put_contents($this->root . '/tests/LedgerTest.php', "<?php\nnamespace App\\Tests;\nuse App\\Ledger;\nuse PHPUnit\\Framework\\TestCase;\nfinal class LedgerTest extends TestCase { public function testTotal(): void { (new Ledger())->total(); \\App\\tally(); } }\n");
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    #[Group('query')]
    public function testFileContextSeeksTheFilesEdgesRatherThanScanningTheProjects(): void
    {
        $pdo = $this->freshTestDatabase();
        $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root)->projectId;

        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RecordingStatement::class]);
        RecordingStatement::drain();
        try {
            // A file of one component as well: one target is where the walk
            // to the tests scanned the source index to spare itself a sort.
            $statuses = array_map(
                static fn(string $path): string => ArchitectureQueryService::forDatabase($pdo)->fileContext($projectId, $path)->data['status'],
                ['src/Ledger.php', 'src/tally.php'],
            );
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
        }
        assertSame(['ok', 'ok'], $statuses);

        $edgeReads = 0;
        $scans = [];
        foreach (RecordingStatement::drain() as [$sql, $params]) {
            foreach (RecordingStatement::plan($pdo, $sql, $params) as $detail) {
                $detail = (string) $detail;
                if (preg_match('/^SEARCH \w+ USING (COVERING )?INDEX edges_/', $detail) === 1) {
                    ++$edgeReads;
                    // An endpoint sought: the file's own edges. Anything else reads the project's.
                    if (preg_match('/(source|target)_id=\?/', $detail) !== 1) {
                        $scans[] = $detail . '  <=  ' . substr((string) preg_replace('/\s+/', ' ', $sql), 0, 120);
                    }
                }
                // Nor every node of the project, to keep the file's.
                if (preg_match('/^SEARCH \w+ USING (COVERING )?INDEX nodes_project_\w+ \(project_id=\?\)$/', $detail) === 1) {
                    $scans[] = $detail . '  <=  ' . substr((string) preg_replace('/\s+/', ' ', $sql), 0, 120);
                }
                if (str_contains($sql, 'AS members') && str_contains($detail, 'TEMP B-TREE FOR GROUP BY')) {
                    $scans[] = $detail . '  <=  ' . substr((string) preg_replace('/\s+/', ' ', $sql), 0, 120);
                }
            }
        }

        assertNotSame(0, $edgeReads, 'file_context read no edges, so this test checked nothing.');
        assertSame([], $scans);
    }
}
