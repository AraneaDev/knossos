<?php

declare(strict_types=1);

/**
 * The command-line arguments of the script being run, program name first.
 *
 * The global $argv exists only where register_argc_argv is on. The quality
 * image's PHP loads no php.ini, so the setting is off there and PHPStan reports
 * every top-level $argv as possibly undefined, while a developer's php.ini
 * usually turns it on and hides the difference. The CLI fills
 * $_SERVER['argv'] either way, so the tools read their arguments from here.
 *
 * @return list<string>
 */
function cliArguments(): array
{
    $arguments = $_SERVER['argv'] ?? [];

    return is_array($arguments) ? array_values(array_map(static fn(mixed $argument): string => (string) $argument, $arguments)) : [];
}
