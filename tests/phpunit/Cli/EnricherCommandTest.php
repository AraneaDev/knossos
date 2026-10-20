<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use Knossos\Application;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\EnricherCommand;
use Knossos\Discovery\EnricherDefinition;
use Knossos\Discovery\EnrichersFile;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertEquals;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('cli')]
final class EnricherCommandTest extends KnossosTestCase
{
    private string $tempDir;
    private string|false $savedRootsFile;
    private string|false $savedDataDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/knossos-enricher-cli-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o755, true);
        $this->savedRootsFile = getenv('KNOSSOS_ROOTS_FILE');
        $this->savedDataDir = getenv('KNOSSOS_DATA_DIR');
        putenv('KNOSSOS_ROOTS_FILE');
    }

    protected function tearDown(): void
    {
        putenv($this->savedRootsFile === false ? 'KNOSSOS_ROOTS_FILE' : 'KNOSSOS_ROOTS_FILE=' . $this->savedRootsFile);
        putenv($this->savedDataDir === false ? 'KNOSSOS_DATA_DIR' : 'KNOSSOS_DATA_DIR=' . $this->savedDataDir);
        exec('rm -rf ' . escapeshellarg($this->tempDir));
        parent::tearDown();
    }

    private function context(?string $databasePath = null): CliCommandContext
    {
        return new CliCommandContext(
            new CliOptionParser(),
            new CliInputLoader(),
            new RuntimeFactory(self::repositoryRoot()),
            $databasePath ?? $this->tempDir . '/knossos.sqlite',
        );
    }

    private function enrichersFile(): string
    {
        return $this->tempDir . '/enrichers.json';
    }

    /**
     * Run the command from argv the way the router would, returning its exit code and output.
     *
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function invoke(array $arguments, ?CliCommandContext $context = null): array
    {
        [$positionals, $options] = (new CliOptionParser())->parse($arguments);
        ob_start();
        try {
            $exit = (new EnricherCommand())->run('enricher', $positionals, $options, $context ?? $this->context());
        } finally {
            $output = (string) ob_get_clean();
        }

        return [$exit, $output];
    }

    /** @param list<array<string, mixed>> $entries */
    private function writeEntries(array $entries): void
    {
        file_put_contents($this->enrichersFile(), json_encode(['enrichers' => $entries], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, EnricherDefinition> */
    private function definitions(): array
    {
        [$definitions, $problems] = (new EnrichersFile($this->enrichersFile()))->load();
        assertSame([], $problems);

        return $definitions;
    }

    public function testCommandIsRouted(): void
    {
        $command = new EnricherCommand();

        assertSame(true, $command->supports('enricher'));
        assertSame(false, $command->supports('enrichers'));
        assertSame(['db', 'json', 'timeout', 'env', 'kinds', 'replace'], $command->allowedOptions('enricher'));
    }

    // ----- list -----

    public function testListWithNoFileSaysSoAndSucceeds(): void
    {
        [$exit, $output] = $this->invoke(['list']);

        assertSame(CliCommand::EXIT_OK, $exit);
        assertContains('No enrichers are defined in ' . $this->enrichersFile(), $output);
    }

    public function testListPrintsEachDefinitionWithEnvironmentNamesButNeverValues(): void
    {
        $this->writeEntries([
            ['name' => 'endpoints', 'command' => ['python3', '/opt/my enrichers/e.py'], 'timeout_seconds' => 45, 'env' => ['API_TOKEN' => 's3cret-value'], 'input_node_kinds' => ['function', 'method']],
            ['name' => 'plain', 'command' => ['x']],
        ]);

        [$exit, $output] = $this->invoke(['list']);

        assertSame(CliCommand::EXIT_OK, $exit);
        assertSame(
            "Enrichers in {$this->enrichersFile()}:\n"
            . "endpoints\n"
            . "  command:     python3 '/opt/my enrichers/e.py'\n"
            . "  timeout:     45s\n"
            . "  input kinds: function, method\n"
            . "  env:         API_TOKEN\n"
            . "plain\n"
            . "  command:     x\n"
            . "  timeout:     30s\n"
            . "  input kinds: (none)\n"
            . "  env:         (none)\n",
            $output,
        );
        assertSame(false, str_contains($output, 's3cret-value'));
    }

    public function testListAsJsonHidesEnvironmentValuesToo(): void
    {
        $this->writeEntries([
            ['name' => 'endpoints', 'command' => ['python3', 'e.py'], 'env' => ['API_TOKEN' => 's3cret-value'], 'input_node_kinds' => ['method']],
        ]);

        [$exit, $output] = $this->invoke(['list', '--json']);

        assertSame(CliCommand::EXIT_OK, $exit);
        assertSame(false, str_contains($output, 's3cret-value'));
        assertSame(
            [
                'enrichers' => [[
                    'name' => 'endpoints',
                    'command' => ['python3', 'e.py'],
                    'timeout_seconds' => 30,
                    'input_node_kinds' => ['method'],
                    'env_keys' => ['API_TOKEN'],
                ]],
                'problems' => [],
            ],
            json_decode($output, true, 16, JSON_THROW_ON_ERROR),
        );
    }

    public function testListReportsProblemsAfterTheDefinitionsAndFails(): void
    {
        $this->writeEntries([
            ['name' => 'good', 'command' => ['x']],
            ['name' => 'Bad Name', 'command' => ['x']],
        ]);

        [$exit, $output] = $this->invoke(['list']);

        assertSame(CliCommand::EXIT_GATE_FAILED, $exit);
        $good = strpos($output, "good\n");
        $problems = strpos($output, 'Problems:');
        assertSame(true, $good !== false && $problems !== false && $good < $problems);
        assertContains('enrichers.json entry 1 skipped: name must match', $output);

        [$exit, $json] = $this->invoke(['list', '--json']);
        $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        assertSame(CliCommand::EXIT_GATE_FAILED, $exit);
        assertSame(['good'], array_column($decoded['enrichers'], 'name'));
        assertSame(1, count($decoded['problems']));
    }

    public function testListTakesNoArgumentsOrAddOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->invoke(['list', '--timeout=5']);
    }

    // ----- add -----

    public function testAddWritesADefinitionThatLoadsBack(): void
    {
        [$exit, $output] = $this->invoke([
            'add', 'endpoints', '--timeout=45', '--env=MODE=strict', '--env=URL=http://x/?a=b', '--kinds=function,method',
            '--', 'python3', '/opt/e.py', '--verbose',
        ]);

        assertSame(CliCommand::EXIT_OK, $exit);
        assertContains('Added enricher endpoints to ' . $this->enrichersFile(), $output);
        assertEquals(
            ['endpoints' => new EnricherDefinition(
                'endpoints',
                ['python3', '/opt/e.py', '--verbose'],
                45,
                ['MODE' => 'strict', 'URL' => 'http://x/?a=b'],
                ['function', 'method'],
            )],
            $this->definitions(),
        );
    }

    public function testAddKeepsTheExistingEntriesAndAppends(): void
    {
        $this->writeEntries([['name' => 'first', 'command' => ['a']]]);

        $this->invoke(['add', 'second', '--', 'b']);

        assertSame(['first', 'second'], array_keys($this->definitions()));
    }

    public function testAddFindsTheFileThroughTheDataDirectory(): void
    {
        $dataDir = $this->tempDir . '/data';
        putenv('KNOSSOS_DATA_DIR=' . $dataDir);
        $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), null);

        $this->invoke(['add', 'endpoints', '--', 'x'], $context);

        assertSame(['endpoints'], array_keys((new EnrichersFile($dataDir . '/enrichers.json'))->read()));
    }

    public function testAFileLocatedFromTheWorkingDirectorySaysSo(): void
    {
        // With nothing naming the location, the file is wherever the shell
        // is, which is rarely the one the server's scans read.
        $previousCwd = (string) getcwd();
        $previousHome = getenv('HOME');
        putenv('KNOSSOS_DATA_DIR');
        putenv('HOME=' . $this->tempDir);
        chdir($this->tempDir);
        try {
            $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), null);
            [, $added] = $this->invoke(['add', 'endpoints', '--', 'x'], $context);
            [, $removed] = $this->invoke(['remove', 'endpoints', '--json'], $context);
        } finally {
            chdir($previousCwd);
            putenv($previousHome === false ? 'HOME' : 'HOME=' . $previousHome);
        }

        assertContains($this->tempDir . '/.knossos/enrichers.json', $added);
        assertContains('came from the working directory', $added);
        assertContains('server_info', $added);
        assertSame('working-directory', json_decode($removed, true, 16, JSON_THROW_ON_ERROR)['enrichers_file_source']);
    }

    public function testANamedLocationNeedsNoWarning(): void
    {
        [, $output] = $this->invoke(['add', 'endpoints', '--', 'x']);

        assertSame(false, str_contains($output, 'working directory'));
    }

    public function testADuplicateExitsTwoThroughTheCli(): void
    {
        $this->writeEntries([['name' => 'endpoints', 'command' => ['old']]]);
        $errors = fopen('php://memory', 'w+');
        assert(is_resource($errors));

        $exit = (new Application($errors))->run(['enricher', 'add', 'endpoints', '--db=' . $this->tempDir . '/knossos.sqlite', '--', 'new']);

        rewind($errors);
        assertSame(CliCommand::EXIT_ERROR, $exit);
        assertContains('already defined', (string) stream_get_contents($errors));
        assertSame(['old'], $this->definitions()['endpoints']->command);
    }

    public function testAddRefusesADuplicateName(): void
    {
        $this->writeEntries([['name' => 'endpoints', 'command' => ['old']]]);

        try {
            $this->invoke(['add', 'endpoints', '--', 'new']);
            self::fail('A duplicate name was accepted.');
        } catch (InvalidArgumentException $error) {
            assertContains('already defined', $error->getMessage());
            assertContains('--replace', $error->getMessage());
        }
        assertSame(['old'], $this->definitions()['endpoints']->command);
    }

    public function testReplaceSwapsTheEntryInPlace(): void
    {
        $this->writeEntries([['name' => 'first', 'command' => ['a']], ['name' => 'endpoints', 'command' => ['old']], ['name' => 'last', 'command' => ['z']]]);

        [$exit, $output] = $this->invoke(['add', 'endpoints', '--replace', '--', 'new']);

        assertSame(CliCommand::EXIT_OK, $exit);
        assertContains('Replaced enricher endpoints', $output);
        assertSame(['first', 'endpoints', 'last'], array_keys($this->definitions()));
        assertSame(['new'], $this->definitions()['endpoints']->command);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function invalidAdditions(): iterable
    {
        yield 'a bad name' => [['add', 'Bad Name', '--', 'x'], 'name must match'];
        yield 'no command' => [['add', 'endpoints'], 'command of endpoints must be a non-empty list'];
        yield 'an empty command word' => [['add', 'endpoints', '--', ''], 'command of endpoints must be a non-empty list'];
        yield 'timeout 0' => [['add', 'endpoints', '--timeout=0', '--', 'x'], 'timeout_seconds of endpoints must be an integer from 1 to 120'];
        yield 'timeout 121' => [['add', 'endpoints', '--timeout=121', '--', 'x'], 'timeout_seconds of endpoints must be an integer from 1 to 120'];
        yield 'a timeout that is not a number' => [['add', 'endpoints', '--timeout=soon', '--', 'x'], 'timeout_seconds of endpoints must be an integer'];
        yield 'an env without =' => [['add', 'endpoints', '--env=MODE', '--', 'x'], '--env takes NAME=VALUE'];
        yield 'an empty env name' => [['add', 'endpoints', '--env==v', '--', 'x'], 'env of endpoints must be an object'];
        yield 'an empty input kind' => [['add', 'endpoints', '--kinds=method,', '--', 'x'], 'input_node_kinds of endpoints must be a list'];
        yield 'no name at all' => [['add'], 'Usage: knossos enricher add'];
    }

    /** @param list<string> $arguments */
    #[DataProvider('invalidAdditions')]
    public function testAddRefusesWhatTheFileWouldSkip(array $arguments, string $message): void
    {
        try {
            $this->invoke($arguments);
            self::fail('An invalid enricher was accepted.');
        } catch (InvalidArgumentException $error) {
            assertContains($message, $error->getMessage());
        }
        assertSame(false, is_file($this->enrichersFile()));
    }

    public function testAddRefusesToRewriteAFileWithProblems(): void
    {
        // Rewriting would keep only the entries that load, so the broken one
        // would vanish without anyone having asked for that.
        $this->writeEntries([['name' => 'good', 'command' => ['x']], ['name' => 'broken', 'command' => []]]);
        $before = (string) file_get_contents($this->enrichersFile());

        try {
            $this->invoke(['add', 'endpoints', '--', 'x']);
            self::fail('A broken file was rewritten.');
        } catch (InvalidArgumentException $error) {
            assertContains('has problems', $error->getMessage());
            assertContains('entry 1 skipped', $error->getMessage());
        }
        assertSame($before, file_get_contents($this->enrichersFile()));
    }

    public function testAddRefusesAFileThatIsNotJson(): void
    {
        file_put_contents($this->enrichersFile(), '{');

        $this->expectException(InvalidArgumentException::class);
        $this->invoke(['add', 'endpoints', '--', 'x']);
    }

    // ----- remove -----

    public function testRemoveDropsTheEntry(): void
    {
        $this->writeEntries([['name' => 'first', 'command' => ['a']], ['name' => 'endpoints', 'command' => ['b']]]);

        [$exit, $output] = $this->invoke(['remove', 'endpoints']);

        assertSame(CliCommand::EXIT_OK, $exit);
        assertContains('Removed enricher endpoints from ' . $this->enrichersFile(), $output);
        assertSame(['first'], array_keys($this->definitions()));
    }

    public function testRemovingAnAbsentEnricherFails(): void
    {
        $this->writeEntries([['name' => 'first', 'command' => ['a']]]);
        $before = (string) file_get_contents($this->enrichersFile());

        [$exit, $output] = $this->invoke(['remove', 'endpoints', '--json']);

        assertSame(CliCommand::EXIT_GATE_FAILED, $exit);
        assertSame(['name' => 'endpoints', 'enrichers_file' => $this->enrichersFile(), 'enrichers_file_source' => 'named', 'removed' => false], json_decode($output, true, 16, JSON_THROW_ON_ERROR));
        assertSame($before, file_get_contents($this->enrichersFile()));
    }

    public function testRemoveRefusesToRewriteAFileWithProblems(): void
    {
        $this->writeEntries([['name' => 'endpoints', 'command' => ['x']], ['name' => 'broken', 'command' => []]]);

        $this->expectException(InvalidArgumentException::class);
        $this->invoke(['remove', 'endpoints']);
    }

    public function testAnUnknownActionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Usage: knossos enricher list|add|remove');
        $this->invoke(['edit']);
    }
}
