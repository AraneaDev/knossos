<?php

declare(strict_types=1);

namespace Fixture;

final class SpreadLaunch
{
    /** @param list<string> $options */
    public function withOptions(array $options): void
    {
        proc_open([PHP_BINARY, ...$options, __DIR__ . '/../bin/tool'], [], $pipes);
    }

    /** @param list<string> $cmd */
    public function allSpread(array $cmd): void
    {
        proc_open([...$cmd], [], $pipes);
    }
}
