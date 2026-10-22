<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * A shell script is a `file` component whether or not anything launches it,
 * and the programs it runs by path are its `launches` edges, so a change to
 * one of them reaches the script that runs it.
 */
#[Group('scan')]
final class ShellScriptNodesTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-shell-nodes-' . bin2hex(random_bytes(6));
        $files = [
            'scripts/run.sh' => "#!/bin/sh\nset -e\nphp bin/tool \"\$@\"\n",
            'bin/tool' => "#!/usr/bin/env php\n<?php\n\nfwrite(STDOUT, \"tool\\n\");\n",
            'bin/other' => "#!/usr/bin/env php\n<?php\n\nfwrite(STDOUT, \"other\\n\");\n",
        ];
        foreach ($files as $path => $contents) {
            if (!is_dir(dirname($this->root . '/' . $path))) {
                mkdir(dirname($this->root . '/' . $path), 0o700, true);
            }
            file_put_contents($this->root . '/' . $path, $contents);
        }
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    /** @return list<array{0: string, 1: string, 2: int}> each launch's source, target and evidence line */
    private static function launches(PDO $pdo): array
    {
        return $pdo->query("SELECT s.canonical_name, t.canonical_name, e.start_line FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id WHERE e.kind = 'launches' ORDER BY 1, 2")->fetchAll(PDO::FETCH_NUM);
    }

    public function testAShellScriptIsAFileComponentThatLaunchesWhatItNames(): void
    {
        $pdo = $this->freshTestDatabase();
        $scanner = new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]);
        $projectId = $scanner->scan($this->root)->projectId;

        $found = ArchitectureQueryService::forDatabase($pdo)->findComponent($projectId, 'scripts/run.sh');
        self::assertSame('file', $found->data['components'][0]['kind'] ?? null);
        self::assertSame('scripts/run.sh', $found->evidence[0]['path'] ?? null);
        self::assertSame([['scripts/run.sh', 'bin/tool', 3]], self::launches($pdo));

        // The launch is a usage of the launched file, with the script as its evidence.
        $usages = ArchitectureQueryService::forDatabase($pdo)->listUsages($projectId, 'bin/tool')->data['usages'] ?? [];
        self::assertSame([['launches', 'scripts/run.sh', 'scripts/run.sh', 3]], array_map(
            static fn(array $usage): array => [$usage['kind'], $usage['source']['canonical_name'], $usage['path'], $usage['start_line']],
            $usages,
        ));

        // The edge follows the script when it changes.
        file_put_contents($this->root . '/scripts/run.sh', "#!/bin/sh\nexec ./bin/other\n");
        $scanner->scan($this->root);
        self::assertSame([['scripts/run.sh', 'bin/other', 2]], self::launches($pdo));
    }
}
