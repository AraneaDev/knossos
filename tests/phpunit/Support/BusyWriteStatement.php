<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

use PDOException;
use PDOStatement;
use ReflectionProperty;

/**
 * A PDO statement that fails as SQLite does when another process holds the
 * write lock, for the statements whose SQL starts with {@see self::$failOn}:
 * a test can meet a scan's commit without racing one. Installed through
 * PDO::ATTR_STATEMENT_CLASS.
 */
final class BusyWriteStatement extends PDOStatement
{
    /** The SQL prefix that fails; null lets everything through. */
    public static ?string $failOn = null;

    /** PDO constructs these itself; the constructor exists only to satisfy the attribute. */
    protected function __construct() {}

    /** Fail as a locked database, or run the statement. */
    public function execute(?array $params = null): bool
    {
        if (self::$failOn !== null && str_starts_with(ltrim($this->queryString), self::$failOn)) {
            $busy = new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
            (new ReflectionProperty(PDOException::class, 'code'))->setValue($busy, 'HY000');

            throw $busy;
        }

        return parent::execute($params);
    }
}
