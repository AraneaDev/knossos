<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Framework;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertSame;

/**
 * A process started from a literal argv runs a file of the project: the code
 * that builds the argv launches that file's module, so flow and impact cross
 * into a worker written in another language.
 */
final class LaunchedProcessTest extends KnossosTestCase
{
    /** @return array{0: PDO, 1: string, 2: string} */
    private function scanned(): array
    {
        $root = sys_get_temp_dir() . '/knossos-stale-launches-' . bin2hex(random_bytes(6));
        $files = [
            'composer.json' => "{\"name\": \"demo/launcher\", \"autoload\": {\"psr-4\": {\"App\\\\\": \"src/\"}}}\n",
            'src/Workers.php' => "<?php\n\nnamespace App;\n\nfinal class Workers\n{\n"
                . "    /** @return list<list<string>> */\n    public static function commands(string \$root): array\n    {\n"
                . "        return [\n"
                . "            [PHP_BINARY, '-d', 'memory_limit=512M', \$root . '/bin/worker'],\n"
                . "            ['node', '--expose-gc', \$root . '/js/worker.js'],\n"
                . "            ['/usr/bin/env', 'tool', '/opt/elsewhere/run.sh'],\n"
                . "        ];\n    }\n}\n",
            'bin/worker' => "#!/usr/bin/env php\n<?php\n\nfwrite(STDOUT, 'ready');\n",
            'js/package.json' => "{\"name\": \"js\", \"private\": true}\n",
            'js/worker.js' => "export function main() { return 1; }\nmain();\n",
        ];
        foreach ($files as $path => $contents) {
            if (!is_dir(dirname($root . '/' . $path))) {
                mkdir(dirname($root . '/' . $path), 0o700, true);
            }
            file_put_contents($root . '/' . $path, $contents);
        }
        $pdo = $this->freshTestDatabase();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

        return [$pdo, (string) $pdo->query('SELECT id FROM projects')->fetchColumn(), $root];
    }

    #[Group('scan')]
    public function testTheCodeBuildingAnArgvLaunchesTheProjectFileItRuns(): void
    {
        [$pdo, $projectId, $root] = $this->scanned();
        try {
            $launched = $pdo->query("SELECT s.canonical_name, t.canonical_name, e.confidence FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id WHERE e.kind = 'launches' ORDER BY t.canonical_name")->fetchAll(PDO::FETCH_NUM);
            assertSame([
                ['App\\Workers::commands', 'bin/worker', 'probable'],
                ['App\\Workers::commands', 'js/worker.js', 'probable'],
            ], $launched);
            // A path outside the project names nothing, and leaves nothing behind.
            assertSame('0', (string) $pdo->query("SELECT COUNT(*) FROM nodes WHERE canonical_name LIKE '%elsewhere%'")->fetchColumn());
            $dependants = ArchitectureQueryService::forDatabase($pdo)->impactAnalysis($projectId, 'js/worker.js')->data['dependants'];
            assertContains('App\\Workers::commands', array_map(static fn(array $d): string => $d['node']['canonical_name'], $dependants));
        } finally {
            unset($pdo);
            $this->removeTempTree($root);
        }
    }

    /**
     * Starting a worker in another process is how code is meant to reach across an
     * out-of-process boundary, so a policy does not count a launch unless it names it.
     */
    #[Group('scan')]
    public function testAPolicyCountsALaunchOnlyWhenItNamesTheKind(): void
    {
        [$pdo, $projectId, $root] = $this->scanned();
        try {
            $queries = ArchitectureQueryService::forDatabase($pdo);
            $policy = ['id' => 'app-stays-out-of-js', 'from_boundary' => 'namespace:App', 'deny_targets' => ['module:js']];
            assertSame(0, count($queries->checkArchitecture($projectId, [$policy])->data['violations']));
            $named = $queries->checkArchitecture($projectId, [$policy + ['edge_kinds' => ['launches']]])->data['violations'];
            assertSame(1, count($named));
        } finally {
            unset($pdo);
            $this->removeTempTree($root);
        }
    }

    #[Group('scan')]
    public function testPythonTypescriptAndRustCodeLaunchTheFilesTheyRun(): void
    {
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
        $root = sys_get_temp_dir() . '/knossos-stale-launches-polyglot-' . bin2hex(random_bytes(6));
        $files = [
            'runner.py' => "import subprocess\ndef start():\n    subprocess.run(['node', 'js/worker.js'])\n",
            'js/package.json' => "{\"name\": \"js\", \"private\": true}\n",
            'js/worker.js' => "export function main() { return 1; }\n",
            'js/runner.js' => "import { execFile } from 'node:child_process';\nexport function start() { execFile('python3', ['tools/job.py']); }\n",
            'tools/job.py' => "def main():\n    return 1\n",
            'Cargo.toml' => "[package]\nname = \"launcher\"\nversion = \"0.1.0\"\n",
            'src/lib.rs' => "use std::process::Command;\n\npub fn start() {\n    Command::new(\"python3\").arg(\"tools/job.py\").status().ok();\n}\n",
        ];
        foreach ($files as $path => $contents) {
            if (!is_dir(dirname($root . '/' . $path))) {
                mkdir(dirname($root . '/' . $path), 0o700, true);
            }
            file_put_contents($root . '/' . $path, $contents);
        }
        $pdo = $this->freshTestDatabase();
        try {
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $launched = $pdo->query("SELECT s.canonical_name, t.canonical_name FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id WHERE e.kind = 'launches' ORDER BY s.canonical_name")->fetchAll(PDO::FETCH_NUM);
            assertSame([
                ['crate::start', 'tools.job'],
                ['js/runner.js#start', 'tools.job'],
                ['runner.start', 'js/worker.js'],
            ], $launched);
        } finally {
            unset($pdo);
            $this->removeTempTree($root);
        }
    }
}
