<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * A project file no worker scans, such as a shell script, is a `file`
 * component once something names it: a process the code launches, or a
 * note. It can then be found and annotated, and a launch reaches it.
 */
final class NamedFileComponentsTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-named-files-' . bin2hex(random_bytes(6));
        $files = [
            'composer.json' => "{\"name\": \"demo/named\", \"autoload\": {\"psr-4\": {\"App\\\\\": \"src/\"}}}\n",
            'src/Deploy.php' => "<?php\n\nnamespace App;\n\nfinal class Deploy\n{\n    /** @return list<string> */\n"
                . "    public static function command(string \$root): array\n    {\n        return ['bash', \$root . '/scripts/deploy.sh'];\n    }\n\n"
                . "    /** @return list<string> */\n    public static function missing(string \$root): array\n    {\n        return ['bash', \$root . '/scripts/gone.sh'];\n    }\n\n"
                . "    /** @return list<string> */\n    public static function built(string \$root): array\n    {\n        return [\$root . '/artifacts/tool'];\n    }\n}\n",
            '.gitignore' => "/artifacts/\n",
            'artifacts/tool' => "binary\n",
            'scripts/deploy.sh' => "#!/bin/sh\necho deploy\n",
            'tools/check' => "#!/bin/sh\necho check\n",
            'vendor/lib/tool.sh' => "#!/bin/sh\n",
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

    /** @return list<array{0: string, 1: ?string}> each file component's name and path attribute */
    private static function fileComponents(PDO $pdo): array
    {
        return $pdo->query("SELECT canonical_name, json_extract(attributes_json, '$.path') FROM nodes WHERE kind = 'file' ORDER BY canonical_name")->fetchAll(PDO::FETCH_NUM);
    }

    #[Group('scan')]
    public function testALaunchedScriptIsAFileComponentTheLaunchReaches(): void
    {
        $pdo = $this->freshTestDatabase();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);

        assertSame([['scripts/deploy.sh', 'scripts/deploy.sh']], self::fileComponents($pdo));
        $launches = $pdo->query("SELECT s.canonical_name, t.canonical_name FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id WHERE e.kind = 'launches'")->fetchAll(PDO::FETCH_NUM);
        assertSame([['App\\Deploy::command', 'scripts/deploy.sh']], $launches);
        $projectId = (string) $pdo->query('SELECT id FROM projects')->fetchColumn();
        $result = ArchitectureQueryService::forDatabase($pdo)->findComponent($projectId, 'scripts/deploy.sh');
        assertSame('file', $result->data['components'][0]['kind'] ?? null);
        assertSame('scripts/deploy.sh', $result->evidence[0]['path'] ?? null);
    }

    #[Group('scan')]
    public function testANoteOnAProjectFileMakesItAComponentUntilTheNoteGoes(): void
    {
        $pdo = $this->freshTestDatabase();
        $scanner = new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]);
        $scanner->scan($this->root);
        $projectId = (string) $pdo->query('SELECT id FROM projects')->fetchColumn();
        $queries = ArchitectureQueryService::forDatabase($pdo);
        $queries->services()->annotationQueries->upsertAnnotation($projectId, 'tools/check', 'note', 'Run by CI before the gate.', true);
        // Excluded or missing paths name nothing.
        $queries->services()->annotationQueries->upsertAnnotation($projectId, 'vendor/lib/tool.sh', 'note', 'vendored', true);
        $queries->services()->annotationQueries->upsertAnnotation($projectId, 'tools/absent', 'note', 'not on disk', true);

        // Nothing on disk changed, and the scan still takes the new note into account.
        $scanner->scan($this->root);
        assertSame([['scripts/deploy.sh', 'scripts/deploy.sh'], ['tools/check', 'tools/check']], self::fileComponents($pdo));

        $queries->services()->annotationQueries->removeAnnotation($projectId, 'tools/check', 'note', execute: true);
        $scanner->scan($this->root);
        assertSame([['scripts/deploy.sh', 'scripts/deploy.sh']], self::fileComponents($pdo));
    }

    /**
     * A note naming a path that is not project-relative names nothing, even
     * when a file of that literal name exists. A backslash and a drive letter
     * once passed the check here and then failed evidence validation, which
     * aborted the scan.
     */
    #[Group('scan')]
    public function testANoteOnANonRelativePathNamesNothing(): void
    {
        mkdir($this->root . '/C:', 0o700, true);
        file_put_contents($this->root . '/C:/run', "#!/bin/sh\n");
        file_put_contents($this->root . '/tools\\run', "#!/bin/sh\n");
        $pdo = $this->freshTestDatabase();
        $scanner = new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]);
        $scanner->scan($this->root);
        $projectId = (string) $pdo->query('SELECT id FROM projects')->fetchColumn();
        $annotations = ArchitectureQueryService::forDatabase($pdo)->services()->annotationQueries;
        $annotations->upsertAnnotation($projectId, 'C:/run', 'note', 'drive letter', true);
        $annotations->upsertAnnotation($projectId, 'tools\\run', 'note', 'backslash', true);

        $scanner->scan($this->root);
        assertSame([['scripts/deploy.sh', 'scripts/deploy.sh']], self::fileComponents($pdo));
    }

    #[Group('scan')]
    public function testANoteOnAFileComponentGoesStaleWhenTheFileChanges(): void
    {
        $pdo = $this->freshTestDatabase();
        $scanner = new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]);
        $scanner->scan($this->root);
        $projectId = (string) $pdo->query('SELECT id FROM projects')->fetchColumn();
        $queries = ArchitectureQueryService::forDatabase($pdo);
        $queries->services()->annotationQueries->upsertAnnotation($projectId, 'tools/check', 'note', 'Run by CI before the gate.', true);
        $scanner->scan($this->root);
        $note = static fn(): array => $queries->findComponent($projectId, 'tools/check')->data['notes'][0] ?? [];
        assertSame(false, $note()['stale'] ?? null);

        file_put_contents($this->root . '/tools/check', "#!/bin/sh
echo changed\n");
        assertSame(true, $note()['stale'] ?? null);
    }
}
