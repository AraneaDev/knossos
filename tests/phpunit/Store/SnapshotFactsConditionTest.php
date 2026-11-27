<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\EnrichedFacts;
use Knossos\Store\SnapshotFacts;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

/**
 * The condition that leaves enriched and derived rows out names the alias the
 * table is read under, for the diagnostics table as for the graph's.
 */
final class SnapshotFactsConditionTest extends KnossosTestCase
{
    public function testADiagnosticsConditionNamesTheAliasItIsReadUnder(): void
    {
        assertSame(" AND d.owner_key NOT LIKE 'enricher:%'", SnapshotFacts::condition('diagnostics', 'd'));
        assertSame(" AND diagnostics.owner_key NOT LIKE 'enricher:%'", SnapshotFacts::condition('diagnostics'));
        assertSame(" AND d.owner_key NOT LIKE 'enricher:%'", EnrichedFacts::nativeCondition('diagnostics', 'd'));
    }

    public function testTheAliasedConditionRunsAndLeavesTheEnricherOwnedRowOut(): void
    {
        $pdo = $this->freshTestDatabase();
        $pdo->exec("INSERT INTO projects(id, name, root_realpath, config_json, created_at, updated_at) VALUES ('p', 'P', '/nowhere', '{}', 'now', 'now')");
        $pdo->exec("INSERT INTO scans(id, project_id, mode, status, scanner_set_hash, started_at) VALUES ('s', 'p', 'full', 'complete', 'h', 'now')");
        foreach (['native' => 'scanner:php', 'enriched' => 'enricher:fixture'] as $id => $owner) {
            $pdo->exec("INSERT INTO diagnostics(id, project_id, severity, code, message, owner_key, scan_id) VALUES ('{$id}', 'p', 'warning', 'C', 'm', '{$owner}', 's')");
        }

        $ids = $pdo->query('SELECT d.id FROM diagnostics d WHERE d.project_id = \'p\'' . SnapshotFacts::condition('diagnostics', 'd'))->fetchAll(\PDO::FETCH_COLUMN);

        assertSame(['native'], $ids);
    }
}
