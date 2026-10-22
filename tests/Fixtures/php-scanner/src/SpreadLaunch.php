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

    /** @param list<string> $prefix */
    public function leadingSpread(array $prefix): void
    {
        proc_open([...$prefix, __DIR__ . '/../bin/lead'], [], $pipes);
    }

    /** @param list<string> $prefix */
    public function commandAfterLeadingSpread(array $prefix): void
    {
        proc_open([PHP_BINARY, ...$prefix, 'artisan', 'orders:poll'], [], $pipes);
    }

    /** @param list<string> $args */
    public function commandHiddenBehindSpread(array $args): void
    {
        proc_open([PHP_BINARY, 'artisan', ...$args, 'orders:hidden'], [], $pipes);
    }
}
