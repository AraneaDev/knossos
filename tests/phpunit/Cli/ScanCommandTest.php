<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use Knossos\Cli\{CliCommand, CliCommandContext, CliInputLoader, CliOptionParser};
use Knossos\Cli\Command\ScanCommand;
use Knossos\Discovery\DiscoveryException;
use Knossos\Roots\RootNotFoundException;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** `knossos scan <path>`: the options it forwards, the output it prints and what it leaves in the graph. */
#[Group('cli')]
final class ScanCommandTest extends KnossosTestCase
{
    private string $database;
    private string $root;
    /** What the last run printed, kept when it threw. */
    private string $lastOutput = '';

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = bin2hex(random_bytes(4));
        $this->database = sys_get_temp_dir() . '/knossos-stale-scan-' . $suffix . '.sqlite';
        $this->root = sys_get_temp_dir() . '/knossos-stale-scan-root-' . $suffix;
        mkdir($this->root . '/src', 0o777, true);
        file_put_contents($this->root . '/src/Checkout.php', "<?php\n\nnamespace App;\n\nfinal class Checkout\n{\n    public function total(): int\n    {\n        return 1;\n    }\n}\n");
        $this->root = (string) realpath($this->root);
    }

    protected function tearDown(): void
    {
        foreach ([$this->database, $this->database . '-wal', $this->database . '-shm'] as $file) {
            @unlink($file);
        }
        if (is_dir($this->root)) {
            $this->removeTempTree($this->root);
        }
        parent::tearDown();
    }

    public function testATextScanPrintsTheProjectAndSnapshotItStored(): void
    {
        [$exit, $text] = $this->runScan([$this->root], []);

        self::assertSame(CliCommand::EXIT_OK, $exit);
        self::assertSame(1, preg_match('/^Project: (\S+)$/m', $text, $project));
        self::assertSame(1, preg_match('/^Snapshot: (\S+)$/m', $text, $snapshot));
        $pdo = $this->pdo();
        self::assertSame($this->root, $this->column($pdo, 'SELECT root_realpath FROM projects WHERE id = ?', [$project[1]]));
        // The snapshot a scan reports is its scan: complete, and the project's active one.
        self::assertSame($project[1], $this->column($pdo, "SELECT project_id FROM scans WHERE id = ? AND status = 'complete'", [$snapshot[1]]));
        self::assertSame($snapshot[1], $this->column($pdo, 'SELECT active_scan_id FROM projects WHERE id = ?', [$project[1]]));
        self::assertStringEndsWith('Snapshot: ' . $snapshot[1] . PHP_EOL, $text);
    }

    public function testJsonOutputCarriesTheSameIdsTheTextWouldReport(): void
    {
        [$exit, $output] = $this->runScan([$this->root], ['json' => ['1']]);

        self::assertSame(CliCommand::EXIT_OK, $exit);
        self::assertStringEndsWith(PHP_EOL, $output);
        $json = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $pdo = $this->pdo();
        self::assertSame($json['project_id'], $this->column($pdo, 'SELECT id FROM projects WHERE root_realpath = ?', [$this->root]));
        self::assertSame($json['snapshot_id'], $this->column($pdo, 'SELECT active_scan_id FROM projects WHERE id = ?', [$json['project_id']]));
        self::assertIsString($json['summary']);
        self::assertStringNotContainsString("\nProject: ", $output);

        // A re-scan in text mode names the same project the JSON did.
        [, $text] = $this->runScan([$this->root], []);
        self::assertStringContainsString('Project: ' . $json['project_id'] . "\n", $text);
    }

    public function testNameOptionNamesTheStoredProject(): void
    {
        [, $output] = $this->runScan([$this->root], ['name' => ['Custom'], 'json' => ['']]);

        $json = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Custom', $this->column($this->pdo(), 'SELECT name FROM projects WHERE id = ?', [$json['project_id']]));
    }

    public function testNoEnrichersKeepsTheProjectsEnrichersFromStarting(): void
    {
        // The installation's enrichers.json sits beside the roots file, so both move to a directory of this test's own.
        $installation = sys_get_temp_dir() . '/knossos-stale-scan-enrichers-' . bin2hex(random_bytes(4));
        mkdir($installation);
        $marker = $installation . '/started';
        file_put_contents($installation . '/enrichers.json', json_encode(['enrichers' => [[
            'name' => 'fixture',
            'command' => [PHP_BINARY, self::repositoryRoot() . '/tests/Fixtures/enrichers/fixture-enricher.php', 'ok', $marker],
        ]]], JSON_UNESCAPED_SLASHES));
        file_put_contents($this->root . '/knossos.json', '{"version": 1, "enrichers": ["fixture"]}');
        $previous = getenv('KNOSSOS_ROOTS_FILE');
        putenv('KNOSSOS_ROOTS_FILE=' . $installation . '/roots.json');
        try {
            [$exit] = $this->runScan([$this->root], ['no-enrichers' => ['']]);
            self::assertSame(CliCommand::EXIT_OK, $exit);
            self::assertFileDoesNotExist($marker, '--no-enrichers scans as a background scan does.');

            $this->runScan([$this->root], []);
            self::assertFileExists($marker, 'A plain scan is explicit and starts the enricher.');
        } finally {
            putenv(is_string($previous) ? 'KNOSSOS_ROOTS_FILE=' . $previous : 'KNOSSOS_ROOTS_FILE');
            $this->removeTempTree($installation);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function offValues(): iterable
    {
        yield 'false' => ['false'];
        yield 'zero' => ['0'];
        yield 'no' => ['no'];
        yield 'off' => ['OFF'];
    }

    #[DataProvider('offValues')]
    public function testAJsonFlagSwitchedOffPrintsText(string $value): void
    {
        [$exit, $text] = $this->runScan([$this->root], ['json' => [$value]]);

        self::assertSame(CliCommand::EXIT_OK, $exit);
        self::assertMatchesRegularExpression('/\nProject: \S+\nSnapshot: \S+\n$/', $text);
        self::assertNull(json_decode($text, true));
    }

    public function testAMissingPathThrowsTheUsageLineAndWritesNothing(): void
    {
        try {
            $this->runScan([], ['name' => ['Custom']]);
            self::fail('A scan without a path ran.');
        } catch (InvalidArgumentException $error) {
            self::assertStringStartsWith('Usage: knossos scan <path>', $error->getMessage());
        }
        self::assertSame('', $this->lastOutput);
        // The usage check runs before the database is opened, so none is created.
        self::assertFileDoesNotExist($this->database);
    }

    /** @return iterable<string, array{array<string, list<string>>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'max-files zero' => [['max-files' => ['0']], '--max-files must be between 1 and 100000.'];
        yield 'max-files past the cap' => [['max-files' => ['100001']], '--max-files must be between 1 and 100000.'];
        yield 'max-files not a number' => [['max-files' => ['abc']], '--max-files must be between 1 and 100000.'];
        yield 'max-file-bytes zero' => [['max-file-bytes' => ['0']], '--max-file-bytes must be between 1 and 100000000.'];
        yield 'max-file-bytes past the cap' => [['max-file-bytes' => ['100000001']], '--max-file-bytes must be between 1 and 100000000.'];
        yield 'snapshot-retention past 20' => [['snapshot-retention' => ['21']], '--snapshot-retention must be between 0 and 20.'];
        yield 'snapshot-retention negative' => [['snapshot-retention' => ['-1']], '--snapshot-retention must be between 0 and 20.'];
        yield 'worker-timeout-ms below 1000' => [['worker-timeout-ms' => ['999']], '--worker-timeout-ms must be between 1000 and 120000.'];
        yield 'worker-timeout-ms above 120000' => [['worker-timeout-ms' => ['120001']], '--worker-timeout-ms must be between 1000 and 120000.'];
        yield 'worker-memory-mb below 64' => [['worker-memory-mb' => ['63']], '--worker-memory-mb must be between 64 and 65536.'];
        yield 'worker-memory-mb above 65536' => [['worker-memory-mb' => ['65537']], '--worker-memory-mb must be between 64 and 65536.'];
        yield 'boundary without colons' => [['boundary' => ['bad']], '--boundary uses NAME:path:PREFIX or NAME:namespace:PREFIX.'];
        yield 'boundary of an unknown kind' => [['boundary' => ['X:other:Y']], '--boundary uses NAME:path:PREFIX or NAME:namespace:PREFIX.'];
        yield 'one bad boundary among good ones' => [['boundary' => ['Domain:path:src', 'X::Y']], '--boundary uses NAME:path:PREFIX or NAME:namespace:PREFIX.'];
        yield 'name twice' => [['name' => ['a', 'b']], '--name must have one non-empty value.'];
        yield 'mode twice' => [['mode' => ['full', 'full']], '--mode must have one non-empty value.'];
        yield 'max-files twice' => [['max-files' => ['5', '6']], '--max-files must have one non-empty value.'];
        yield 'bare boundary' => [['boundary' => ['']], '--boundary takes a value; write --boundary=VALUE.'];
    }

    /** @param array<string, list<string>> $options */
    #[DataProvider('invalidOptions')]
    public function testAnInvalidOptionIsRefusedBeforeAnythingIsScanned(array $options, string $message): void
    {
        try {
            $this->runScan([$this->root], $options);
            self::fail('An invalid option was accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertSame($message, $error->getMessage());
        }
        self::assertSame('', $this->lastOutput);
        self::assertSame(0, $this->rows('projects'));
    }

    /** @return iterable<string, array{array<string, list<string>>}> */
    public static function boundaryValues(): iterable
    {
        yield 'snapshot-retention zero' => [['snapshot-retention' => ['0']]];
        yield 'snapshot-retention twenty' => [['snapshot-retention' => ['20']]];
        yield 'worker-timeout-ms bounds' => [['worker-timeout-ms' => ['1000']]];
        yield 'worker-memory-mb lowest' => [['worker-memory-mb' => ['64']]];
        yield 'max-file-bytes and max-files at their caps' => [['max-files' => ['100000'], 'max-file-bytes' => ['100000000']]];
    }

    /** @param array<string, list<string>> $options */
    #[DataProvider('boundaryValues')]
    public function testValuesOnTheBoundsAreAccepted(array $options): void
    {
        [$exit, $text] = $this->runScan([$this->root], $options);

        self::assertSame(CliCommand::EXIT_OK, $exit);
        self::assertStringContainsString("\nProject: ", $text);
        self::assertSame(1, $this->rows('projects'));
    }

    public function testAnExplicitBoundaryIsStoredForTheProject(): void
    {
        [, $output] = $this->runScan([$this->root], ['boundary' => ['Domain:path:src/Domain', 'Core:namespace:App\\Core'], 'json' => ['1']]);

        $projectId = json_decode($output, true, 512, JSON_THROW_ON_ERROR)['project_id'];
        $statement = $this->pdo()->prepare("SELECT name, matcher_json FROM boundaries WHERE project_id = ? AND source = 'explicit' ORDER BY name");
        $statement->execute([$projectId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['Core', 'Domain'], array_column($rows, 'name'));
        self::assertStringContainsString('src/Domain', (string) $rows[1]['matcher_json']);
        self::assertStringContainsString('Core', (string) $rows[0]['matcher_json']);
    }

    /** The cap reaches discovery: one file over it stops the scan, and a cap the tree fits lets it run. */
    public function testMaxFilesIsHonouredRatherThanIgnored(): void
    {
        file_put_contents($this->root . '/src/Cart.php', "<?php\n\nnamespace App;\n\nfinal class Cart {}\n");

        try {
            $this->runScan([$this->root], ['max-files' => ['1']]);
            self::fail('A scan over --max-files ran.');
        } catch (DiscoveryException $error) {
            self::assertSame('Discovery file limit exceeded (1).', $error->getMessage());
        }
        self::assertSame(0, $this->rows('projects'));

        [$exit, $output] = $this->runScan([$this->root], ['max-files' => ['2'], 'json' => ['1']]);
        self::assertSame(CliCommand::EXIT_OK, $exit);
        self::assertSame(2, json_decode($output, true, 512, JSON_THROW_ON_ERROR)['data']['metrics']['discovered_files']);
    }

    public function testARescanKeepsTheProjectTakesANewSnapshotAndIsLedgered(): void
    {
        [, $first] = $this->runScan([$this->root], ['json' => ['1']]);
        $first = json_decode($first, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $this->rows('scan_ledger'));
        file_put_contents($this->root . '/src/Cart.php', "<?php\n\nnamespace App;\n\nfinal class Cart {}\n");

        [$exit, $second] = $this->runScan([$this->root], ['json' => ['1']]);
        $second = json_decode($second, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(CliCommand::EXIT_OK, $exit);
        self::assertSame($first['project_id'], $second['project_id']);
        self::assertNotSame($first['snapshot_id'], $second['snapshot_id']);
        $statement = $this->pdo()->prepare('SELECT from_snapshot, to_snapshot, changes_json FROM scan_ledger WHERE project_id = ?');
        $statement->execute([$first['project_id']]);
        $entries = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $entries);
        self::assertSame($first['snapshot_id'], $entries[0]['from_snapshot']);
        self::assertSame($second['snapshot_id'], $entries[0]['to_snapshot']);
        self::assertStringContainsString('src/Cart.php', (string) $entries[0]['changes_json']);
    }

    public function testANonexistentPathFailsAndStoresNoProject(): void
    {
        $missing = $this->root . '/does-not-exist';
        try {
            $this->runScan([$missing], []);
            self::fail('A scan of a missing path succeeded.');
        } catch (RootNotFoundException $error) {
            self::assertStringContainsString($missing, $error->getMessage());
        }
        self::assertSame('', $this->lastOutput);
        self::assertSame(0, $this->rows('projects'));
    }

    public function testItAnswersOnlyScanAndAllowsTheDocumentedOptions(): void
    {
        $command = new ScanCommand();
        self::assertTrue($command->supports('scan'));
        self::assertFalse($command->supports('scans'));
        self::assertFalse($command->supports('Scan'));
        $allowed = $command->allowedOptions('scan');
        sort($allowed);
        self::assertSame(['boundary', 'db', 'json', 'max-file-bytes', 'max-files', 'mode', 'name', 'no-enrichers', 'snapshot-retention', 'worker-memory-mb', 'worker-timeout-ms'], $allowed);
    }

    /**
     * @param list<string> $positionals
     * @param array<string, list<string>> $options
     * @return array{int, string}
     */
    private function runScan(array $positionals, array $options): array
    {
        $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), $this->database);
        $this->lastOutput = '';
        ob_start();
        try {
            $exit = (new ScanCommand())->run('scan', $positionals, $options, $context);
        } finally {
            $this->lastOutput = (string) ob_get_clean();
        }

        return [$exit, $this->lastOutput];
    }

    private function pdo(): PDO
    {
        return (new RuntimeFactory(self::repositoryRoot()))->database($this->database);
    }

    /** @param list<string> $parameters */
    private function column(PDO $pdo, string $sql, array $parameters): mixed
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn();
    }

    private function rows(string $table): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
