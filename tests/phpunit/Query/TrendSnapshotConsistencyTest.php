<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Closure;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PDOStatement;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * A scan that publishes while the trend is being read cannot pair the scan
 * id the trend listed with the figures of the scan that replaced it.
 */
final class TrendSnapshotConsistencyTest extends KnossosTestCase
{
    #[Group('query')]
    public function testAScanPublishedMidReadDoesNotLendItsFiguresToTheListedScan(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-trend-' . bin2hex(random_bytes(6));
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/turn-brief', $root . '/project');
        try {
            $database = $root . '/knossos.sqlite';
            $writer = SqliteConnection::open($database);
            (new MigrationRunner($writer, self::repositoryRoot() . '/migrations'))->migrate();
            $projectId = (new ProjectScanService($writer, self::repositoryRoot(), [$root . '/project']))->scan($root . '/project')->projectId;
            $listed = (string) $writer->query("SELECT active_scan_id FROM projects WHERE id = '{$projectId}'")->fetchColumn();

            $reader = new PublishingPdo($database);
            $before = self::activePoint($reader, $projectId);

            // Between the snapshot listing and the figures: a new scan becomes active and adds a component.
            $reader->publishAfterListing = static function () use ($writer, $projectId): void {
                $writer->beginTransaction();
                $writer->prepare("INSERT INTO scans (id, project_id, mode, status, scanner_set_hash, started_at, finished_at) VALUES ('scan_b', :project, 'full', 'complete', 'h', '2026-01-02T00:00:00Z', '2026-01-02T00:00:00Z')")
                    ->execute(['project' => $projectId]);
                $writer->prepare("INSERT INTO nodes (id, project_id, language, kind, canonical_name, display_name, origin, confidence, owner_key, last_scan_id) VALUES ('late', :project, 'php', 'function', 'late', 'late', 'ast', 'certain', 'late', 'scan_b')")
                    ->execute(['project' => $projectId]);
                $writer->prepare("UPDATE projects SET active_scan_id = 'scan_b' WHERE id = :project")->execute(['project' => $projectId]);
                $writer->commit();
            };
            $during = self::activePoint($reader, $projectId);

            assertSame(true, $reader->published);
            assertSame($listed, $during['scan_id']);
            assertSame($before['counts'], $during['counts']);
            assertSame($before['metrics'], $during['metrics']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** @return array<string, mixed> */
    private static function activePoint(PDO $pdo, string $projectId): array
    {
        $series = ArchitectureQueryService::forDatabase($pdo)->services()->gateQueries->architectureTrends($projectId, 2)->data['series'];
        return array_values(array_filter($series, static fn(array $point): bool => ($point['active'] ?? false) === true))[0];
    }
}

/**
 * A connection that runs `publishAfterListing` once, on the first statement
 * prepared after the trend's snapshot listing: the moment a concurrent scan
 * could commit between the listing and the figures read for it.
 */
final class PublishingPdo extends PDO
{
    public ?Closure $publishAfterListing = null;
    public bool $published = false;
    private bool $listed = false;

    public function __construct(string $database)
    {
        parent::__construct('sqlite:' . $database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->exec('PRAGMA busy_timeout = 5000');
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->publishOnce($query);
        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->publishOnce($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    private function publishOnce(string $query): void
    {
        if ($this->publishAfterListing === null || $this->published) {
            return;
        }
        if ($this->listed) {
            $this->published = true;
            ($this->publishAfterListing)();
            return;
        }
        $this->listed = str_contains($query, 'FROM scans s LEFT JOIN scan_snapshots');
    }
}
