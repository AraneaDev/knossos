<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

use Knossos\Discovery\EnrichersFile;
use Knossos\Result\ResultEnvelope;
use Knossos\Scan\ProjectScanService;
use PDO;

/**
 * Scans of a temporary copy of the enricher-flow fixture, with the fixture
 * enricher installed and, when asked, requested by the project. The using
 * class extends KnossosTestCase, which supplies the tree and database helpers.
 */
trait EnricherFlowScans
{
    /** @var list<string> temporary trees to remove after the test */
    private array $enricherFlowTrees = [];

    /**
     * Copy the enricher-flow fixture, install the fixture enricher in its own
     * data directory and request it (or not) from the project config.
     *
     * @return array{root: string, installation: string}
     */
    protected function enricherFlowProject(bool $requested = true, string $mode = 'ok'): array
    {
        $suffix = bin2hex(random_bytes(6));
        $root = sys_get_temp_dir() . '/knossos-stale-enricher-flow-' . $suffix;
        $installation = sys_get_temp_dir() . '/knossos-stale-enricher-flow-install-' . $suffix;
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/enricher-flow', $root);
        mkdir($installation, 0o777, true);
        $this->enricherFlowTrees[] = $root;
        $this->enricherFlowTrees[] = $installation;
        file_put_contents($root . '/knossos.json', json_encode(['version' => 1, 'enrichers' => $requested ? ['fixture'] : []]));
        file_put_contents($installation . '/enrichers.json', json_encode(['enrichers' => [[
            'name' => 'fixture',
            'command' => [PHP_BINARY, self::repositoryRoot() . '/tests/Fixtures/enrichers/fixture-enricher.php', '@' . $installation . '/mode'],
            'timeout_seconds' => 10,
            'input_node_kinds' => ['module', 'function', 'class'],
        ]]], JSON_UNESCAPED_SLASHES));
        $this->setEnricherFlowMode($installation, $mode);

        return ['root' => $root, 'installation' => $installation];
    }

    /** Switch what the fixture enricher emits on its next run. */
    protected function setEnricherFlowMode(string $installation, string $mode): void
    {
        file_put_contents($installation . '/mode', $mode);
    }

    /**
     * An explicit scan of the copy, which runs the requested enrichers.
     *
     * @param array{root: string, installation: string} $project
     */
    protected function scanEnricherFlow(PDO $pdo, array $project): ResultEnvelope
    {
        $service = new ProjectScanService($pdo, self::repositoryRoot(), [$project['root']], enrichers: new EnrichersFile($project['installation'] . '/enrichers.json'));

        return $service->scan($project['root'], runEnrichers: true);
    }

    /** Remove the copies this test made. */
    protected function removeEnricherFlowTrees(): void
    {
        foreach ($this->enricherFlowTrees as $tree) {
            $this->removeTempTree($tree);
        }
        $this->enricherFlowTrees = [];
    }
}
