<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\PaneProject;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The project a pane answer is for: the scanned project containing the
 * path, or the `unscanned` envelope, with the leading fields in one order
 * whatever the status.
 */
final class PaneProjectTest extends KnossosTestCase
{
    /** No project contains the path: `unscanned`, the path in front of the envelope, the answer never asked. */
    #[Group('query')]
    public function testAPathNoProjectContainsIsUnscanned(): void
    {
        [$pdo, $root] = $this->database();
        try {
            $answered = false;
            $result = PaneProject::answer($pdo, $root . '/elsewhere', ['project_id' => null, 'snapshot_id' => null, 'items' => []], static function () use (&$answered): array {
                $answered = true;

                return [];
            });
            assertSame(['status' => 'unscanned', 'path' => $root . '/elsewhere', 'project_id' => null, 'snapshot_id' => null, 'items' => []], $result);
            assertFalse($answered);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A project row with no active scan has no graph to answer from. */
    #[Group('query')]
    public function testAProjectWithoutAnActiveScanIsUnscanned(): void
    {
        [$pdo, $root] = $this->database();
        try {
            $this->project($pdo, (string) realpath($root), '');
            $result = PaneProject::answer($pdo, $root, ['project_id' => null, 'snapshot_id' => null], static fn(): array => ['status' => 'ok']);
            assertSame(['status' => 'unscanned', 'path' => realpath($root), 'project_id' => null, 'snapshot_id' => null], $result);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A scanned project: the answer gets the project and the envelope with its ids filled where they stand. */
    #[Group('query')]
    public function testAScannedProjectFillsTheIdsInPlace(): void
    {
        [$pdo, $root] = $this->database();
        try {
            $real = (string) realpath($root);
            $this->project($pdo, $real, 'scan_1');
            mkdir($root . '/src');
            $result = PaneProject::answer(
                $pdo,
                $root . '/src/../src',
                ['name' => 'x', 'project_id' => null, 'snapshot_id' => null, 'items' => []],
                static fn(array $project, array $envelope): array => ['status' => 'ok', 'project' => $project] + $envelope,
            );
            assertSame([
                'status' => 'ok',
                'project' => ['id' => 'p', 'root' => $real, 'snapshot_id' => 'scan_1'],
                'path' => $real . '/src',
                'name' => 'x',
                'project_id' => 'p',
                'snapshot_id' => 'scan_1',
                'items' => [],
            ], $result);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A migrated database in a fresh directory, and that directory.
     *
     * @return array{0: PDO, 1: string}
     */
    private function database(): array
    {
        $root = sys_get_temp_dir() . '/knossos-stale-pane-project-' . bin2hex(random_bytes(6));
        mkdir($root);
        $pdo = SqliteConnection::open($root . '/knossos.sqlite');
        (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
        $pdo->exec('PRAGMA foreign_keys = OFF');

        return [$pdo, $root];
    }

    /** Records project `p` at `$root` with `$scan` as its active scan, none when empty. */
    private function project(PDO $pdo, string $root, string $scan): void
    {
        $pdo->prepare("INSERT INTO projects(id, name, root_realpath, config_json, active_scan_id, created_at, updated_at) VALUES ('p', 'p', ?, '{}', ?, 'now', 'now')")
            ->execute([$root, $scan === '' ? null : $scan]);
    }
}
