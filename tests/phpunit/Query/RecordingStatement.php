<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use PDOStatement;

/**
 * Records each statement's SQL and parameters as it runs.
 *
 * Values bound one at a time with bindValue() are recorded as the statement's
 * parameters too: a list for positions, a map for names, an integer bound as
 * one kept an integer, where execute() sends every value it is given as text. {@see self::plan()} binds them back the same way, since
 * SQLite can plan a statement differently for an integer than for its text.
 */
final class RecordingStatement extends PDOStatement
{
    /** @var list<array{0: string, 1: array<mixed>|null}> */
    private static array $executed = [];

    /** @var array<int|string, mixed> */
    private array $bound = [];

    protected function __construct() {}

    /**
     * What ran since the last call, which this forgets.
     *
     * @return list<array{0: string, 1: array<mixed>|null}>
     */
    public static function drain(): array
    {
        $executed = self::$executed;
        self::$executed = [];
        return $executed;
    }

    /**
     * The query plan of `$sql` with `$params` bound as they were recorded, one detail line each.
     *
     * @param array<mixed>|null $params
     * @return list<string>
     */
    public static function plan(\PDO $pdo, string $sql, ?array $params): array
    {
        $plan = $pdo->prepare('EXPLAIN QUERY PLAN ' . $sql);
        foreach ($params ?? [] as $key => $value) {
            $plan->bindValue(is_int($key) ? $key + 1 : $key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $plan->execute();

        return array_map('strval', $plan->fetchAll(\PDO::FETCH_COLUMN, 3));
    }

    public function bindValue(int|string $param, mixed $value, int $type = \PDO::PARAM_STR): bool
    {
        $this->bound[$param] = $type === \PDO::PARAM_INT ? (int) $value : $value;
        return parent::bindValue($param, $value, $type);
    }

    public function execute(?array $params = null): bool
    {
        // execute() binds every value it is given as text, an integer included.
        $recorded = $params === null ? null : array_map(static fn(mixed $value): mixed => is_int($value) ? (string) $value : $value, $params);
        if ($recorded === null && $this->bound !== []) {
            ksort($this->bound);
            $recorded = is_int(array_key_first($this->bound)) ? array_values($this->bound) : $this->bound;
        }
        self::$executed[] = [$this->queryString, $recorded];
        // Passed on as given: execute() with values would rebind every one as text.
        return parent::execute($params);
    }
}
