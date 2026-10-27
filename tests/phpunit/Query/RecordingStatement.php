<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use PDOStatement;

/**
 * Records each statement's SQL and parameters as it runs.
 *
 * Values bound one at a time with bindValue() are recorded as the statement's
 * parameters too, in a form execute() takes again: a list for positions, a map
 * for names.
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

    public function bindValue(int|string $param, mixed $value, int $type = \PDO::PARAM_STR): bool
    {
        $this->bound[$param] = $value;
        return parent::bindValue($param, $value, $type);
    }

    public function execute(?array $params = null): bool
    {
        $recorded = $params;
        if ($recorded === null && $this->bound !== []) {
            ksort($this->bound);
            $recorded = is_int(array_key_first($this->bound)) ? array_values($this->bound) : $this->bound;
        }
        self::$executed[] = [$this->queryString, $recorded];
        // Passed on as given: execute() with values would rebind every one as text.
        return parent::execute($params);
    }
}
