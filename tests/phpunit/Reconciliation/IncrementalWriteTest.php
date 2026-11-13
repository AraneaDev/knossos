<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanOptions;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * An incremental scan re-parses only the files that changed, but it used to
 * persist its result by deleting the project's whole graph and writing every row
 * back. The cost of a rescan was therefore the size of the project rather than
 * the size of the change: on this repository, one edited file rewrote 28,000
 * edges to alter a handful of them.
 */
final class IncrementalWriteTest extends KnossosTestCase
{
    #[Group('reconciliation')]
    public function testEditingOneFileWritesRowsInProportionToTheChangeNotTheProject(): void
    {
        $root = sys_get_temp_dir() . '/knossos-incremental-' . bin2hex(random_bytes(6));
        if (!mkdir($root . '/src', 0o755, true)) {
            throw new \RuntimeException('Unable to create fixture tree.');
        }
        try {
            file_put_contents($root . '/composer.json', json_encode(['name' => 'fixture/incremental'], JSON_THROW_ON_ERROR));
            // Enough declarations that a whole-graph rewrite is unmistakably larger
            // than a one-file change.
            for ($file = 0; $file < 12; $file++) {
                $classes = '';
                for ($class = 0; $class < 12; $class++) {
                    $classes .= sprintf("final class C%d_%d { public function run(): void {} }\n", $file, $class);
                }
                file_put_contents($root . sprintf('/src/F%d.php', $file), "<?php\n\nnamespace Fixture;\n\n" . $classes);
            }
            $pdo = SqliteConnection::open($root . '/graph.sqlite');
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
            $service->scan($root, new ScanOptions(mode: 'full'));
            $rows = (int) $pdo->query('SELECT (SELECT COUNT(*) FROM nodes) + (SELECT COUNT(*) FROM edges) + (SELECT COUNT(*) FROM files)')->fetchColumn();

            // Count only the rows physically inserted or deleted. Re-stamping a
            // surviving row with the current scan id is an update of one unindexed
            // column; deleting and re-inserting it rewrites every index the row
            // appears in, which is the cost this is about.
            $pdo->exec('CREATE TABLE churn (op TEXT)');
            foreach (['nodes', 'edges'] as $table) {
                foreach (['INSERT', 'DELETE'] as $op) {
                    $pdo->exec(sprintf(
                        'CREATE TRIGGER churn_%1$s_%2$s AFTER %2$s ON %1$s BEGIN INSERT INTO churn(op) VALUES (\'%2$s\'); END',
                        $table,
                        $op,
                    ));
                }
            }

            file_put_contents($root . '/src/F0.php', "<?php\n\nnamespace Fixture;\n\nfinal class C0_0 { public function run(): void {} }\n");
            $result = $service->scan($root);
            $churn = (int) $pdo->query('SELECT COUNT(*) FROM churn')->fetchColumn();

            assertSame('incremental', $result->data['mode']);
            assertSame(1, $result->data['parsed_files']);
            // One file of twelve changed, so the rows it owns are a twelfth of the
            // graph. A whole-graph rewrite churns every row twice over.
            $this->assertLessThan((int) ($rows / 4), $churn, sprintf('Rescan inserted or deleted %d rows for a graph of %d.', $churn, $rows));
        } finally {
            unset($pdo);
            $this->removeFixtureTree($root);
        }
    }

    #[Group('reconciliation')]
    public function testFilesPointAtTheConfirmingScanAndEveryOtherRowAtAScanThatStillExists(): void
    {
        // Files and boundaries are attributed to the scan that confirmed
        // them, which is what "the files of the active scan" is asked by. The
        // large tables stay on the scan that wrote them, and scan cleanup has
        // to keep every scan one of their rows still names.
        $root = sys_get_temp_dir() . '/knossos-incremental-' . bin2hex(random_bytes(6));
        if (!mkdir($root . '/src', 0o755, true)) {
            throw new \RuntimeException('Unable to create fixture tree.');
        }
        try {
            file_put_contents($root . '/composer.json', json_encode(['name' => 'fixture/scan-id'], JSON_THROW_ON_ERROR));
            file_put_contents($root . '/src/A.php', "<?php\n\nnamespace Fixture;\n\nfinal class A { public function run(): void {} }\n");
            file_put_contents($root . '/src/B.php', "<?php\n\nnamespace Fixture;\n\nfinal class B {}\n");
            $pdo = SqliteConnection::open($root . '/graph.sqlite');
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
            $service->scan($root, new ScanOptions(mode: 'full'));

            file_put_contents($root . '/src/B.php', "<?php\n\nnamespace Fixture;\n\nfinal class B { public function added(): void {} }\n");
            $result = $service->scan($root);

            $active = (string) $pdo->query('SELECT active_scan_id FROM projects')->fetchColumn();
            assertSame($result->snapshotId, $active);
            foreach (['files', 'boundaries'] as $table) {
                $statement = $pdo->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE last_scan_id <> :scan', $table));
                $statement->execute(['scan' => $active]);
                assertSame(0, (int) $statement->fetchColumn(), $table . ' has rows attributed to an older scan');
            }
            // A.php was not touched by the second scan, so its rows are still the first scan's.
            $older = $pdo->prepare('SELECT COUNT(*) FROM nodes WHERE last_scan_id <> :scan');
            $older->execute(['scan' => $active]);
            self::assertGreaterThan(0, (int) $older->fetchColumn(), 'An untouched node must not be rewritten just to move its scan.');
            foreach (['nodes', 'edges', 'classifications', 'boundary_memberships'] as $table) {
                assertSame(0, (int) $pdo->query(sprintf('SELECT COUNT(*) FROM %s t WHERE NOT EXISTS (SELECT 1 FROM scans s WHERE s.id = t.last_scan_id)', $table))->fetchColumn(), $table . ' names a scan that was deleted');
            }
            assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());

            // With no snapshot kept, a scan is removed as soon as nothing names it
            // and kept for as long as one row does.
            $service->scan($root, new ScanOptions(mode: 'full', snapshotRetention: 0));
            file_put_contents($root . '/src/B.php', "<?php\n\nnamespace Fixture;\n\nfinal class B { public function again(): void {} }\n");
            $service->scan($root, new ScanOptions(snapshotRetention: 0));
            $named = (int) $pdo->query('SELECT COUNT(DISTINCT last_scan_id) FROM nodes')->fetchColumn();
            self::assertGreaterThan(1, $named);
            assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
            assertSame(0, (int) $pdo->query(
                'SELECT COUNT(*) FROM scans s WHERE s.id <> (SELECT active_scan_id FROM projects) '
                . 'AND NOT EXISTS (SELECT 1 FROM scan_snapshots ss WHERE ss.scan_id = s.id) '
                . 'AND NOT EXISTS (SELECT 1 FROM nodes n WHERE n.last_scan_id = s.id) AND NOT EXISTS (SELECT 1 FROM edges e WHERE e.last_scan_id = s.id) '
                . 'AND NOT EXISTS (SELECT 1 FROM classifications c WHERE c.last_scan_id = s.id) AND NOT EXISTS (SELECT 1 FROM boundary_memberships m WHERE m.last_scan_id = s.id)',
            )->fetchColumn(), 'A scan nothing names and no snapshot holds must be removed.');
        } finally {
            unset($pdo);
            $this->removeFixtureTree($root);
        }
    }
}
