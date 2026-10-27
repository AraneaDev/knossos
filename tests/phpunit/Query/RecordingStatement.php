<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use PDOStatement;

/** Records each statement's SQL and parameters as it runs. */
final class RecordingStatement extends PDOStatement
{
    /** @var list<array{0: string, 1: array<mixed>|null}> */
    private static array $executed = [];

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

    public function execute(?array $params = null): bool
    {
        self::$executed[] = [$this->queryString, $params];
        return parent::execute($params);
    }
}
