<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

/**
 * The check every contribution a worker sends passes first: its params are a
 * JSON object, never a list or a scalar.
 */
final class ContributionParams
{
    /** The refusal for params that are not an object, whichever session received them. */
    public const NOT_AN_OBJECT = 'Contribution params must be an object.';

    private function __construct() {}

    /**
     * $params as the object it must be.
     *
     * @return array<string, mixed>
     *
     * @throws WorkerException as WORKER_CONTRIBUTION_INVALID when they are not one
     */
    public static function of(mixed $params): array
    {
        if (!is_array($params) || array_is_list($params)) {
            throw new WorkerException(WorkerErrorCode::ContributionInvalid->value, self::NOT_AN_OBJECT);
        }

        /** @var array<string, mixed> $params */
        return $params;
    }
}
