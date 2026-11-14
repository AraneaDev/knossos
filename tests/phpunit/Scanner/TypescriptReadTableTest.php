<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\ScanLimits;
use PHPUnit\Framework\Attributes\Group;

/**
 * The TypeScript worker states each read once for a core that accepts a read
 * table, and what the core rebuilds from it is the `reads` map the worker
 * sends a host that does not.
 */
#[Group('typescript-scanner')]
final class TypescriptReadTableTest extends KnossosTestCase
{
    private const FILES = ['src/cjs/deck.js', 'src/cjs/ejector.js', 'src/cjs/lib.js', 'src/cjs/rewinder.js', 'src/cjs/winder.js', 'src/devices.js', 'src/flash.js', 'src/handlers.js', 'src/lib/frame.js', 'src/lib/index.js', 'src/shoot.js', 'src/steps.js', 'src/typed.ts'];

    public function testTheCoreRebuildsTheReadsAHostWithoutATableIsSent(): void
    {
        $plain = self::contributionsOf(self::converse([]));
        $client = $this->typescriptWorkerClient();
        try {
            $rebuilt = [];
            foreach ($client->scan(self::request()) as $contribution) {
                $rebuilt[$contribution->ownerKey] = $contribution->reads;
            }
        } finally {
            $client->shutdown();
        }

        assertSame(count(self::FILES), count($plain));
        assertSame(array_map(static fn(array $params): array => $params['reads'], $plain), $rebuilt);
        assertSame(true, array_sum(array_map(count(...), $rebuilt)) > count(self::FILES), 'The fixture reads more than its own files, or this compares nothing.');
    }

    public function testEachPathAndHashTravelsOnceWhenTheCoreAcceptsATable(): void
    {
        $plain = self::contributionsOf(self::converse([]));
        $frames = self::converse(['read_table']);

        $rows = [];
        $expanded = [];
        foreach ($frames as $frame) {
            if (($frame['method'] ?? null) === 'scan/read_table') {
                array_push($rows, ...$frame['params']['rows']);
            }
            if (($frame['method'] ?? null) === 'scan/contribution') {
                assertSame(false, array_key_exists('reads', $frame['params']));
                $reads = [];
                foreach ($frame['params']['reads_ref'] as $row) {
                    $reads[$rows[$row][0]] = $rows[$row][1];
                }
                $expanded[$frame['params']['owner_key']] = $reads;
            }
        }

        assertSame(array_map(static fn(array $params): array => $params['reads'], $plain), $expanded);
        assertSame(count($rows), count(array_unique(array_map(static fn(array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows))), 'No row is sent twice.');
        assertSame(true, count($rows) < array_sum(array_map(count(...), $expanded)), 'Files share reads, so rows are fewer than entries.');
    }

    public function testAHostThatDidNotAskGetsMapsAndNoTable(): void
    {
        $frames = self::converse([]);

        assertSame([], array_values(array_filter($frames, static fn(array $frame): bool => ($frame['method'] ?? null) === 'scan/read_table')));
        foreach (self::contributionsOf($frames) as $params) {
            assertSame(false, array_key_exists('reads_ref', $params));
        }
    }

    /** @return array<string, mixed> */
    private static function request(): array
    {
        return [
            'limits' => ScanLimits::DEFAULT,
            'root' => self::repositoryRoot() . '/tests/Fixtures/ts-cross-file-call-arguments',
            'files' => self::FILES,
            'config_files' => ['tsconfig.json'],
        ];
    }

    /**
     * Every frame the worker writes for one scan, after an `initialize` that
     * names what the host accepts.
     *
     * @param list<string> $accepts
     * @return list<array<string, mixed>>
     */
    private static function converse(array $accepts): array
    {
        $process = proc_open(['node', self::repositoryRoot() . '/workers/typescript/bin/worker.js'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $initialize = ['protocol_version' => '1.0', 'output_schema_version' => '1.0'] + ($accepts === [] ? [] : ['accepts' => $accepts]);
        foreach ([['initialize', $initialize], ['scan', self::request()], ['shutdown', (object) []]] as $index => [$method, $params]) {
            fwrite($pipes[0], json_encode(['jsonrpc' => '2.0', 'id' => $index + 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR) . "\n");
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        assertSame(0, proc_close($process), $stderr);

        return array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", $stdout), static fn(string $line): bool => $line !== '')),
        );
    }

    /**
     * @param list<array<string, mixed>> $frames
     * @return array<string, array<string, mixed>> owner key to the contribution's params
     */
    private static function contributionsOf(array $frames): array
    {
        $contributions = [];
        foreach ($frames as $frame) {
            if (($frame['method'] ?? null) === 'scan/contribution') {
                $contributions[$frame['params']['owner_key']] = $frame['params'];
            }
        }

        return $contributions;
    }
}
