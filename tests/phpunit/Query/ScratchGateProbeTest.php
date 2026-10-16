<?php
declare(strict_types=1);
namespace Knossos\Tests\Phpunit\Query;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\SyntheticGraph;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
final class ScratchGateProbeTest extends KnossosTestCase
{
    #[RunInSeparateProcess]
    public function testProbe(): void
    {
        ini_set('memory_limit', '2G');
        [$pdo, $repository, $ids] = $this->storeFixture();
        $pdo->beginTransaction();
        SyntheticGraph::seed($pdo, $ids, 199_998, 2);
        $pdo->commit();
        $repository->completeScan($ids['project'], $ids['scan']);
        $t = hrtime(true);
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $archive = (hrtime(true) - $t) / 1e9;
        $row = $pdo->query('SELECT complete, fact_count, byte_size, length(payload_json) AS stored FROM scan_snapshots')->fetch();
        $before = memory_get_peak_usage(true);
        $t = hrtime(true);
        $gate = ArchitectureQueryService::forDatabase($pdo)->qualityGate($ids['project'], $ids['scan'], ['new_cycles' => 0]);
        file_put_contents('/tmp/claude-1002/-home-tim-Knossos-MCP/2db7470b-2ba5-4a22-9626-9fc9527eff56/scratchpad/gateprobe.txt', sprintf("archive %.1fs complete=%s facts=%s bytes=%dMB stored=%dMB; gate %.1fs peak %dMB (before %dMB) status=%s\n", $archive, $row['complete'], $row['fact_count'], $row['byte_size'] >> 20, $row['stored'] >> 20, (hrtime(true) - $t) / 1e9, memory_get_peak_usage(true) >> 20, $before >> 20, json_encode($gate->data['passed'] ?? $gate->data)));
        self::assertTrue(true);
    }
}
