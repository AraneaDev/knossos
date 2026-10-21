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

/**
 * The options of the script being run, for a script that takes options only.
 *
 * Each of $valued is `--name=VALUE` with a value that is not empty; each of
 * $flags is a bare `--name`, read back as an empty string. A repeated option
 * keeps its last value. Anything else (an option not named, an empty value, a
 * positional argument) prints $usage to STDERR and exits with $usageExit, so
 * a mistyped option never runs against a default the caller did not mean.
 *
 * @param list<string> $valued names of the options that take a value
 * @param list<string> $flags names of the options that take none
 * @return array<string, string> the options given, by name without the dashes
 */
function cliOptions(string $usage, array $valued, array $flags = [], int $usageExit = 2): array
{
    $options = [];
    foreach (array_slice(cliArguments(), 1) as $argument) {
        $name = substr((string) strtok($argument, '='), 2);
        if (str_starts_with($argument, '--') && in_array($name, $flags, true) && $argument === '--' . $name) {
            $options[$name] = '';
            continue;
        }
        $prefix = '--' . $name . '=';
        if (str_starts_with($argument, '--') && in_array($name, $valued, true) && str_starts_with($argument, $prefix) && $argument !== $prefix) {
            $options[$name] = substr($argument, strlen($prefix));
            continue;
        }
        fwrite(STDERR, $usage . "\n");
        exit($usageExit);
    }

    return $options;
}

/**
 * Write $report as pretty-printed JSON, one trailing newline, creating the
 * directory it goes in first.
 *
 * @param array<mixed> $report
 * @param string $what what the directory holds, for the error when it cannot be made
 * @throws RuntimeException when the directory cannot be created
 */
function writeJsonReport(string $path, array $report, string $what, int $mode = 0o775): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, $mode, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('Unable to create %s directory.', $what));
    }
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
