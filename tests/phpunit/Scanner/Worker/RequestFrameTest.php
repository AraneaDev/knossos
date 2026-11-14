<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Worker;

use JsonException;
use Knossos\Scanner\Protocol\Protocol;
use Knossos\Scanner\Worker\RequestFrame;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The line a worker reads, and that every bundled worker answers a `shutdown`
 * written that way instead of refusing its parameters.
 */
#[Group('worker-protocol')]
final class RequestFrameTest extends KnossosTestCase
{
    public function testARequestWithoutParametersCarriesAnEmptyObject(): void
    {
        assertSame(
            '{"jsonrpc":"2.0","id":3,"method":"shutdown","params":{}}' . "\n",
            RequestFrame::encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => Protocol::METHOD_SHUTDOWN, 'params' => []]),
        );
    }

    public function testFilledParametersAndPathsTravelAsWritten(): void
    {
        assertSame(
            '{"jsonrpc":"2.0","method":"cancel","params":{"request_id":2}}' . "\n",
            RequestFrame::encode(['jsonrpc' => '2.0', 'method' => Protocol::METHOD_CANCEL, 'params' => ['request_id' => 2]]),
        );
        assertSame(
            '{"id":1,"params":{"root":"/srv/app","files":[]}}' . "\n",
            RequestFrame::encode(['id' => 1, 'params' => ['root' => '/srv/app', 'files' => []]]),
        );
        assertSame('{"id":1}' . "\n", RequestFrame::encode(['id' => 1]));
    }

    public function testAMessageThatCannotBeEncodedThrows(): void
    {
        $this->expectException(JsonException::class);

        RequestFrame::encode(['params' => ['name' => "\xff"]]);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function bundledWorkers(): iterable
    {
        $root = self::repositoryRoot();

        yield 'php' => [[PHP_BINARY, $root . '/workers/php/bin/worker']];
        yield 'typescript' => [['node', $root . '/workers/typescript/bin/worker.js']];
        yield 'python' => [['python3', '-I', '-B', $root . '/workers/python/bin/worker.py']];
    }

    /**
     * @param list<string> $command
     */
    #[DataProvider('bundledWorkers')]
    public function testABundledWorkerSaysByeToTheShutdownTheCoreWrites(array $command): void
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], RequestFrame::encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => Protocol::METHOD_SHUTDOWN, 'params' => []]));
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        $exit = proc_close($process);

        assertSame(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['status' => 'bye']], json_decode(trim($stdout), true), $stdout . $stderr);
        assertSame(0, $exit, $stderr);
    }
}
