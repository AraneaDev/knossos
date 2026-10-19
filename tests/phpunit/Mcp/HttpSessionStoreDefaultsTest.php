<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Mcp\HttpSessionStore;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * The limits a store built with no arguments enforces, which is how the HTTP
 * router builds it: sessions last thirty minutes and at most a thousand are
 * held at once.
 */
#[Group('http')]
final class HttpSessionStoreDefaultsTest extends KnossosTestCase
{
    public function testADefaultSessionExpiresThirtyMinutesAfterItWasCreated(): void
    {
        $pdo = self::database();
        (new HttpSessionStore($pdo))->create();

        $row = $pdo->query('SELECT created_at, expires_at FROM http_sessions')->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame(1800, (int) $row['expires_at'] - (int) $row['created_at']);
    }

    public function testADefaultStoreHoldsAThousandSessionsAndRefusesTheNext(): void
    {
        $store = new HttpSessionStore(self::database());
        for ($i = 0; $i < 1000; $i++) {
            $store->create();
        }

        try {
            $store->create();
            self::fail('The thousand-and-first session was created.');
        } catch (RuntimeException $full) {
            self::assertSame(HttpSessionStore::CAPACITY_ERROR, $full->getCode());
        }
    }

    private static function database(): PDO
    {
        $pdo = SqliteConnection::open(':memory:');
        (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();

        return $pdo;
    }
}
