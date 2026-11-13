<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliCommandRouter;
use Knossos\Cli\CliHelpRenderer;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\QueryCommand;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** `knossos creation-brief`: the brief of a file about to be created, as text or as the envelope. */
#[Group('cli')]
final class CreationBriefCommandTest extends KnossosTestCase
{
    private string|false $allowedRoots = false;

    private string|false $dataDir = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowedRoots = getenv('KNOSSOS_ALLOWED_ROOTS');
        $this->dataDir = getenv('KNOSSOS_DATA_DIR');
        putenv('KNOSSOS_ALLOWED_ROOTS=' . sys_get_temp_dir());
        putenv('KNOSSOS_DATA_DIR');
    }

    protected function tearDown(): void
    {
        putenv(is_string($this->allowedRoots) ? 'KNOSSOS_ALLOWED_ROOTS=' . $this->allowedRoots : 'KNOSSOS_ALLOWED_ROOTS');
        putenv(is_string($this->dataDir) ? 'KNOSSOS_DATA_DIR=' . $this->dataDir : 'KNOSSOS_DATA_DIR');
        parent::tearDown();
    }

    /** A scanned copy of a small fixture that declares a boundary, a rule and a gate, with its own database. */
    private function scannedRoot(): string
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/turn-brief', $root);
        file_put_contents($root . '/knossos.json', json_encode([
            'version' => 1,
            'boundaries' => [['name' => 'Core', 'path_prefix' => 'src/Core/'], ['name' => 'Edge', 'path_prefix' => 'src/Edge/']],
            'policies' => [['id' => 'core-inward', 'from_boundary' => 'Core', 'deny_targets' => ['Edge']]],
            'gates' => [['name' => 'cs', 'command' => 'make cs']],
        ], JSON_THROW_ON_ERROR));
        mkdir($root . '/.knossos', 0o777, true);
        file_put_contents($root . '/.knossos/roots.json', json_encode(['roots' => [(string) realpath($root)]], JSON_THROW_ON_ERROR));
        $pdo = SqliteConnection::open($root . '/.knossos/knossos.sqlite');
        (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
        $this->backdateDirectories($root, 10);

        return $root;
    }

    /** @param array<string, list<string>> $options @return array{0: int, 1: string} */
    private function routeBrief(array $positionals, array $options = []): array
    {
        $router = new CliCommandRouter(self::repositoryRoot(), new CliOptionParser(), new CliHelpRenderer(), 'test');
        ob_start();
        try {
            $status = $router->route('creation-brief', $positionals, $options);

            return [$status, (string) ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    }

    public function testItPrintsTheRenderedLinesAndExitsZero(): void
    {
        $root = $this->scannedRoot();
        try {
            [$status, $out] = $this->routeBrief([$root, 'src/Core/NewThing.php']);

            self::assertSame(0, $status);
            self::assertSame("knossos · new file in Core\nmay not use: Edge\ngates: cs. Run: make cs\n", $out);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testJsonPrintsTheEnvelope(): void
    {
        $root = $this->scannedRoot();
        try {
            [$status, $out] = $this->routeBrief([$root, 'src/Core/NewThing.php'], ['json' => ['true']]);
            $envelope = json_decode($out, true, flags: JSON_THROW_ON_ERROR);

            self::assertSame(0, $status);
            self::assertSame('src/Core/NewThing.php', $envelope['data']['path']);
            self::assertSame(['boundary' => 'Core', 'may_not_use' => ['Edge']], $envelope['data']['placement']);
            self::assertSame('New file in Core: 0 conventions, 1 gates.', $envelope['summary']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A directory nothing scanned fails the way every query command does: the same message, and no database is made. */
    public function testAnUnscannedPathFailsLikeTheOtherQueryCommands(): void
    {
        $directory = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);
        try {
            $messages = [];
            foreach (['creation-brief' => [$directory, 'a.php'], 'file-metrics' => [$directory]] as $command => $positionals) {
                try {
                    (new CliCommandRouter(self::repositoryRoot(), new CliOptionParser(), new CliHelpRenderer(), 'test'))->route($command, $positionals, []);
                    self::fail($command . ' answered for a directory nothing scanned.');
                } catch (InvalidArgumentException $error) {
                    $messages[$command] = $error->getMessage();
                }
            }

            self::assertStringStartsWith('Project not found: ' . $directory, $messages['creation-brief']);
            self::assertSame($messages['file-metrics'], $messages['creation-brief']);
        } finally {
            $this->removeTempTree($directory);
        }
    }

    public function testAMissingFileIsRefusedWithTheUsage(): void
    {
        $root = $this->scannedRoot();
        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Usage: knossos creation-brief <path|project-id> <file> [--json]');
            $this->routeBrief([$root]);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A partial brief says why on stderr in text mode; the JSON envelope carries it already, and a clean brief writes nothing. */
    public function testTextModeReportsTheEnvelopesWarningsOnStderr(): void
    {
        $root = $this->scannedRoot();
        try {
            $run = function (array $options) use ($root): array {
                $errors = fopen('php://memory', 'w+');
                self::assertNotFalse($errors);
                $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), null);
                ob_start();
                try {
                    (new QueryCommand($errors))->run('creation-brief', [$root, 'src/Core/NewThing.php'], $options, $context);
                } finally {
                    ob_end_clean();
                }
                rewind($errors);

                return [(string) stream_get_contents($errors)];
            };

            self::assertSame([''], $run([]));
            file_put_contents($root . '/knossos.json', '{ not json');
            [$stderr] = $run([]);
            self::assertStringStartsWith("warning: The project's knossos.json could not be read", $stderr);
            self::assertStringEndsWith(PHP_EOL, $stderr);
            self::assertSame([''], $run(['json' => ['true']]));
        } finally {
            $this->removeTempTree($root);
        }
    }
}
