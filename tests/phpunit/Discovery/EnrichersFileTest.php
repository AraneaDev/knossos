<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\EnricherDefinition;
use Knossos\Discovery\EnrichersFile;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('enrichers-file')]
final class EnrichersFileTest extends TestCase
{
    private string $directory;
    private string|false $savedRootsFile;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/knossos-enrichers-' . uniqid('', true);
        mkdir($this->directory, 0o777, true);
        $this->savedRootsFile = getenv('KNOSSOS_ROOTS_FILE');
    }

    protected function tearDown(): void
    {
        if ($this->savedRootsFile === false) {
            putenv('KNOSSOS_ROOTS_FILE');
        } else {
            putenv('KNOSSOS_ROOTS_FILE=' . $this->savedRootsFile);
        }
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    private function fileWith(string $contents): EnrichersFile
    {
        $path = $this->directory . '/enrichers.json';
        file_put_contents($path, $contents);

        return new EnrichersFile($path);
    }

    /** @param array<string, mixed> ...$entries */
    private function fileWithEntries(array ...$entries): EnrichersFile
    {
        return $this->fileWith(json_encode(['enrichers' => $entries], JSON_THROW_ON_ERROR));
    }

    public function testReadsValidEntries(): void
    {
        $file = $this->fileWith('{"enrichers":[{"name":"acme-rest","command":["python3","/opt/m.py"],"timeout_seconds":60,"env":{"A":"1"},"input_node_kinds":["method","route"]}]}');

        $definitions = $file->read();

        assertSame(['acme-rest'], array_keys($definitions));
        $definition = $definitions['acme-rest'];
        assertSame('acme-rest', $definition->name);
        assertSame(['python3', '/opt/m.py'], $definition->command);
        assertSame(60, $definition->timeoutSeconds);
        assertSame(['A' => '1'], $definition->environment);
        assertSame(['method', 'route'], $definition->inputNodeKinds);
        assertSame([], $file->problems());
    }

    public function testOptionalFieldsTakeTheirDefaults(): void
    {
        $definition = $this->fileWithEntries(['name' => 'plain', 'command' => ['x']])->read()['plain'];

        assertSame(EnricherDefinition::DEFAULT_TIMEOUT_SECONDS, $definition->timeoutSeconds);
        assertSame([], $definition->environment);
        assertSame([], $definition->inputNodeKinds);
    }

    public function testMissingFileReadsEmpty(): void
    {
        $file = new EnrichersFile($this->directory . '/absent.json');

        assertSame([], $file->read());
        assertSame([], $file->problems());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidEntries(): array
    {
        return [
            'an empty command' => [['name' => 'bad', 'command' => []]],
            'a command given as a string' => [['name' => 'bad', 'command' => 'python3 m.py']],
            'a bad name' => [['name' => 'Bad Name', 'command' => ['x']]],
            'timeout 0' => [['name' => 'bad', 'command' => ['x'], 'timeout_seconds' => 0]],
            'an env name with =' => [['name' => 'bad', 'command' => ['x'], 'env' => ['A=B' => '1']]],
            'an empty env name' => [['name' => 'bad', 'command' => ['x'], 'env' => ['' => '1']]],
            'an env name with NUL' => [['name' => 'bad', 'command' => ['x'], 'env' => ["A\0B" => '1']]],
            'timeout 121' => [['name' => 'bad', 'command' => ['x'], 'timeout_seconds' => 121]],
        ];
    }

    /** @param array<string, mixed> $invalid */
    #[DataProvider('invalidEntries')]
    public function testInvalidEntriesAreSkippedWithAProblem(array $invalid): void
    {
        $file = $this->fileWithEntries(['name' => 'good-one', 'command' => ['x']], $invalid);

        assertSame(['good-one'], array_keys($file->read()));
        $problems = $file->problems();
        assertCount(1, $problems);
        assertStringContainsString('entry 1', $problems[0]);
    }

    public function testDuplicateNameIsSkippedWithAProblem(): void
    {
        $file = $this->fileWithEntries(
            ['name' => 'twin', 'command' => ['first']],
            ['name' => 'twin', 'command' => ['second']],
        );

        assertSame(['first'], $file->read()['twin']->command);
        $problems = $file->problems();
        assertCount(1, $problems);
        assertStringContainsString('entry 1', $problems[0]);
    }

    public function testABareTopLevelListIsNotAValidShape(): void
    {
        $file = $this->fileWith('[{"name":"plain","command":["x"]}]');

        assertSame([], $file->read());
        assertCount(1, $file->problems());
    }

    public function testLoadReturnsDefinitionsAndProblemsTogether(): void
    {
        $file = $this->fileWithEntries(['name' => 'good', 'command' => ['x']], ['name' => 'Bad', 'command' => ['x']]);

        [$definitions, $problems] = $file->load();

        assertSame(['good'], array_keys($definitions));
        assertCount(1, $problems);
    }

    public function testUnreadableJsonIsAProblemNotAnException(): void
    {
        $file = $this->fileWith('{');

        assertSame([], $file->read());
        assertCount(1, $file->problems());
    }

    public function testForDatabaseFollowsRootsFileOverride(): void
    {
        putenv('KNOSSOS_ROOTS_FILE=/x/y/roots.json');

        assertSame('/x/y/enrichers.json', EnrichersFile::forDatabase('/d/knossos.sqlite')->path);
    }

    public function testForDatabaseSitsBesideTheDatabaseByDefault(): void
    {
        putenv('KNOSSOS_ROOTS_FILE');

        assertSame('/d/enrichers.json', EnrichersFile::forDatabase('/d/knossos.sqlite')->path);
    }

    public function testWrittenDefinitionsReadBackUnchanged(): void
    {
        $file = new EnrichersFile($this->directory . '/enrichers.json');
        $written = [
            new EnricherDefinition('acme-rest', ['python3', '/opt/m.py'], 60, ['A' => '1'], ['method', 'route']),
            new EnricherDefinition('plain', ['x']),
        ];

        $file->write($written);

        [$definitions, $problems] = $file->load();
        assertSame([], $problems);
        assertEquals(['acme-rest' => $written[0], 'plain' => $written[1]], $definitions);
        $decoded = json_decode((string) file_get_contents($file->path), true, 16, JSON_THROW_ON_ERROR);
        assertSame(['name', 'command', 'timeout_seconds', 'env', 'input_node_kinds'], array_keys($decoded['enrichers'][0]));
        assertSame('{}', json_encode(json_decode((string) file_get_contents($file->path))->enrichers[1]->env));
    }

    public function testTheWriteIsAtomicAndOwnerOnly(): void
    {
        // The env values may hold credentials, so the file is never readable
        // by anyone else, and a write never leaves its temporary file behind.
        $file = $this->fileWith('{"enrichers":[]}');
        chmod($file->path, 0o644);

        $file->write([new EnricherDefinition('plain', ['x'])]);

        assertSame(0o600, fileperms($file->path) & 0o777);
        assertSame(['enrichers.json'], array_values(array_diff(scandir($this->directory) ?: [], ['.', '..'])));
    }

    public function testWritingCreatesTheDirectory(): void
    {
        $file = new EnrichersFile($this->directory . '/enrichers.json');
        rmdir($this->directory);

        $file->write([new EnricherDefinition('plain', ['x'])]);

        assertSame(['plain'], array_keys($file->read()));
        assertSame(0o700, fileperms($this->directory) & 0o777);
    }
}
