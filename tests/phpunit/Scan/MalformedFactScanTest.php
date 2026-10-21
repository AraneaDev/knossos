<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanOptions;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\FakeWorker;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * A whole scan whose PHP worker reports one malformed fact for one file.
 * The core used to refuse the reply and drop every file of the language; it
 * now keeps that file without facts, with the reason, and the cache replays
 * exactly that on the next scan. The fake worker stands in for the PHP worker
 * and says each file read only itself, so an added file leaves the other two
 * to the cache.
 */
#[Group('scan')]
final class MalformedFactScanTest extends KnossosTestCase
{
    private string $root = '';
    private string $installation = '';
    private string $record = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-malformed-fact-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o777, true);
        file_put_contents($this->root . '/src/Good.php', "<?php\nfinal class Good {}\n");
        file_put_contents($this->root . '/src/Bad.php', "<?php\nfinal class Bad {}\n");
        $this->record = (string) tempnam(sys_get_temp_dir(), 'knossos-malformed-record-');
        $this->installation = sys_get_temp_dir() . '/knossos-stale-malformed-install-' . bin2hex(random_bytes(6));
        mkdir($this->installation . '/workers/php/bin', 0o777, true);
        file_put_contents($this->installation . '/workers/php/bin/worker', sprintf(
            "<?php\n\$argv = [__FILE__, 'per_file_malformed_bad', %s, '0', %s];\nrequire %s;\n",
            var_export($this->record, true),
            var_export(FakeWorker::manifestForPackaged('php'), true),
            var_export(FakeWorker::path(), true),
        ));
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        $this->removeTempTree($this->installation);
        @unlink($this->record);
        parent::tearDown();
    }

    public function testOneMalformedFactCostsOnlyItsFileAndTheCacheReplaysIt(): void
    {
        $pdo = $this->freshTestDatabase();
        $service = new ProjectScanService($pdo, $this->installation, [$this->root]);

        $first = $service->scan($this->root);

        assertSame([], $first->data['degraded_languages']);
        assertSame(['src/Good.php'], $pdo->query("SELECT canonical_name FROM nodes WHERE kind = 'class'")->fetchAll(PDO::FETCH_COLUMN));
        $diagnostics = $pdo->query(
            "SELECT f.relative_path, d.severity FROM diagnostics d JOIN files f ON f.id = d.file_id WHERE d.code = 'WORKER_CONTRIBUTION_INVALID'",
        )->fetchAll(PDO::FETCH_NUM);
        assertSame([['src/Bad.php', 'error']], $diagnostics);
        $before = $this->graph($pdo);

        // A new file makes the next scan incremental: Good and Bad come back from the cache.
        file_put_contents($this->root . '/src/Other.php', "<?php\nfinal class Other {}\n");
        $service->scan($this->root);

        assertSame(['2', '1'], array_values(array_filter(explode("\n", (string) file_get_contents($this->record)))), 'Only the new file was sent to the worker.');
        $replayed = $this->graph($pdo);
        foreach (['nodes', 'edges', 'diagnostics'] as $table) {
            $unchanged = array_values(array_filter($replayed[$table], static fn(string $row): bool => !str_contains($row, 'Other')));
            assertSame($before[$table], $unchanged, $table);
        }

        // And a full scan from nothing agrees with the incremental one.
        $fresh = $this->freshTestDatabase();
        (new ProjectScanService($fresh, $this->installation, [$this->root]))->scan($this->root, new ScanOptions(mode: 'full'));
        assertSame($replayed, $this->graph($fresh));
    }

    /** @return array{nodes: list<string>, edges: list<string>, diagnostics: list<string>} the graph, without scan ids, sorted */
    private function graph(PDO $pdo): array
    {
        $rows = static function (string $sql) use ($pdo): array {
            $lines = array_map(static fn(array $row): string => implode(' | ', $row), $pdo->query($sql)->fetchAll(PDO::FETCH_NUM));
            sort($lines);

            return $lines;
        };

        return [
            'nodes' => $rows('SELECT id, kind, canonical_name, display_name FROM nodes'),
            'edges' => $rows('SELECT id, kind, source_id, target_id FROM edges'),
            'diagnostics' => $rows(
                'SELECT d.severity, d.code, d.message, COALESCE(f.relative_path, \'\'), d.owner_key FROM diagnostics d LEFT JOIN files f ON f.id = d.file_id',
            ),
        ];
    }
}
