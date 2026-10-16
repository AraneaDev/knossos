<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use InvalidArgumentException;
use Knossos\Discovery\DiscoveryException;
use Knossos\Mcp\ToolErrorMapper;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class ToolErrorMapperTest extends KnossosTestCase
{
    /** A message written for the caller is kept; anything else is logged and replaced. */
    #[Group('mcp')]
    public function testOnlyCallerFacingMessagesSurvive(): void
    {
        assertSame('KNOSSOS_UNSAFE_PATH', ToolErrorMapper::code(new DiscoveryException('outside the roots')));
        assertSame('outside the roots', ToolErrorMapper::publicMessage(new DiscoveryException('outside the roots')));
        assertSame('KNOSSOS_INVALID_ARGUMENT', ToolErrorMapper::code(new InvalidArgumentException('bad limit')));

        $message = '';
        $logged = $this->errorLogOf(function () use (&$message): void {
            $message = ToolErrorMapper::publicMessage(new RuntimeException('SQLSTATE[HY000]: /srv/internal/db is locked'));
        });

        assertSame(ToolErrorMapper::GENERIC_MESSAGE, $message);
        assertSame('KNOSSOS_TOOL_ERROR', ToolErrorMapper::code(new RuntimeException('x')));
        assertSame(true, str_contains($logged, 'SQLSTATE[HY000]'));
    }

    /**
     * A write that lands while a scan holds the graph's write lock is worth
     * retrying, and the caller should be told so, not that something
     * unexpected happened. The driver's own text still stays out of it.
     */
    #[Group('mcp')]
    public function testABusyDatabaseSaysToRetry(): void
    {
        $busy = new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
        (new \ReflectionProperty(\PDOException::class, 'code'))->setValue($busy, 'HY000');

        assertSame('KNOSSOS_DATABASE_BUSY', ToolErrorMapper::code($busy));
        $message = ToolErrorMapper::publicMessage($busy);
        assertSame(true, str_contains($message, 'retry'));
        assertSame(false, str_contains($message, 'SQLSTATE'));
    }
}
