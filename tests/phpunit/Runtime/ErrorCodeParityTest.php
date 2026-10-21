<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Runtime;

use InvalidArgumentException;
use Knossos\Cancellation\ScanCancelledException;
use Knossos\Cli\CliErrorRenderer;
use Knossos\Discovery\DiscoveryException;
use Knossos\Mcp\ToolErrorMapper;
use Knossos\Runtime\ErrorCode;
use Knossos\Scan\ScanSnapshotChangedException;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Store\ScanBusyException;
use Knossos\Store\SqliteBusy;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDOException;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Throwable;

/**
 * The MCP server and the CLI give every mapped failure the same code, and,
 * where the failure's own text is not withheld, the same message: the table
 * lives in {@see ErrorCode} and the adapters only render it.
 */
#[Group('runtime-factory')]
final class ErrorCodeParityTest extends KnossosTestCase
{
    /** A PDOException as the SQLite driver raises it for a contended write lock. */
    private static function busy(): PDOException
    {
        $busy = new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
        (new \ReflectionProperty(PDOException::class, 'code'))->setValue($busy, 'HY000');

        return $busy;
    }

    /** @return array<string, array{Throwable, string}> */
    public static function mapped(): array
    {
        return [
            'cancelled' => [new ScanCancelledException('Scan cancelled.'), 'KNOSSOS_SCAN_CANCELLED'],
            'scan busy, wrapping the busy driver error' => [new ScanBusyException('held by pid 1', 0, self::busy()), 'KNOSSOS_SCAN_BUSY'],
            'database busy' => [self::busy(), 'KNOSSOS_DATABASE_BUSY'],
            'database busy, wrapped' => [new RuntimeException('could not write the note', 0, self::busy()), 'KNOSSOS_DATABASE_BUSY'],
            'snapshot changed' => [ScanSnapshotChangedException::contentChanged('src/a.php'), 'KNOSSOS_SCAN_SNAPSHOT_CHANGED'],
            'worker' => [new WorkerException('WORKER_TIMEOUT', 'timed out'), 'WORKER_TIMEOUT'],
            'discovery' => [new DiscoveryException('outside the roots'), 'KNOSSOS_UNSAFE_PATH'],
            'storage' => [new PDOException('disk I/O error: /srv/x.sqlite'), 'KNOSSOS_STORAGE_ERROR'],
            'invalid argument' => [new InvalidArgumentException('bad limit'), 'KNOSSOS_INVALID_ARGUMENT'],
        ];
    }

    #[DataProvider('mapped')]
    public function testBothAdaptersGiveTheSharedCode(Throwable $error, string $code): void
    {
        assertSame($code, ErrorCode::for($error));
        assertSame($code, ToolErrorMapper::code($error));
        assertSame($code, CliErrorRenderer::diagnosticCode($error));
    }

    #[DataProvider('mapped')]
    public function testBothAdaptersSayTheSameWhereTheDetailIsNotWithheld(Throwable $error, string $code): void
    {
        assertSame($code === 'KNOSSOS_STORAGE_ERROR', ErrorCode::withholdsDetail($error));
        if (ErrorCode::withholdsDetail($error)) {
            $this->errorLogOf(static function () use ($error): void {
                assertSame(ErrorCode::publicMessage($error), ToolErrorMapper::publicMessage($error));
            });
            assertSame($error->getMessage(), CliErrorRenderer::message($error));

            return;
        }
        assertSame(ErrorCode::publicMessage($error), ToolErrorMapper::publicMessage($error));
        assertSame(ErrorCode::publicMessage($error), CliErrorRenderer::message($error));
    }

    public function testABusyDatabaseIsRedactedOnBoth(): void
    {
        assertSame(SqliteBusy::MESSAGE, ToolErrorMapper::publicMessage(self::busy()));
        assertSame(SqliteBusy::MESSAGE, CliErrorRenderer::message(self::busy()));
    }

    public function testAnUnmappedFailureKeepsEachAdaptersFallbackCode(): void
    {
        $error = new \LogicException('internal /srv/path');

        assertSame(ErrorCode::UNEXPECTED, ErrorCode::for($error));
        assertSame(ToolErrorMapper::GENERIC_CODE, ToolErrorMapper::code($error));
        assertSame('KNOSSOS_RUNTIME_ERROR', CliErrorRenderer::diagnosticCode($error));
        $this->errorLogOf(static function () use ($error): void {
            assertSame(ErrorCode::GENERIC_MESSAGE, ToolErrorMapper::publicMessage($error));
        });
        assertSame('internal /srv/path', CliErrorRenderer::message($error));
    }
}
