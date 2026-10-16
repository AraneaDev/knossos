<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

use PDOStatement;

/**
 * A PDO statement that counts how often each query runs, installed through
 * PDO::ATTR_STATEMENT_CLASS: a test can tell that a walk never asked a
 * question it had no reason to ask, where timing alone would be flaky.
 */
final class QueryCountingStatement extends PDOStatement
{
    /** @var list<string> the SQL of every statement executed since the last {@see reset} */
    public static array $executed = [];

    /** PDO constructs these itself; the constructor exists only to satisfy the attribute. */
    protected function __construct() {}

    /** Forget what ran so far. */
    public static function reset(): void
    {
        self::$executed = [];
    }

    /** How many statements whose SQL holds `$fragment` ran. */
    public static function count(string $fragment): int
    {
        return count(array_filter(self::$executed, static fn(string $sql): bool => str_contains($sql, $fragment)));
    }

    /** Record the statement, then run it. */
    public function execute(?array $params = null): bool
    {
        self::$executed[] = $this->queryString;

        return parent::execute($params);
    }
}
