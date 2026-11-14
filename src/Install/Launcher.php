<?php

declare(strict_types=1);

namespace Knossos\Install;

/**
 * The launcher in the bin directory, written from a release's `launcher/knossos` template.
 *
 * The same substitution install.sh makes in `write_launcher`, byte for byte:
 * the one line `INSTALL_DIR='__KNOSSOS_INSTALL_DIR__'` gets the install
 * directory between single quotes (a quote in the path closed, escaped and
 * reopened), every line ends in a newline, and nothing else changes.
 */
final class Launcher
{
    /** The template line that names the install directory. */
    public const PLACEHOLDER_LINE = "INSTALL_DIR='__KNOSSOS_INSTALL_DIR__'";

    /**
     * The launcher a template becomes for an install directory.
     *
     * @throws InstallException when the template has no placeholder line, which would make a launcher that finds no release
     */
    public static function render(string $template, string $installDir): string
    {
        if (str_contains($installDir, "\n") || str_contains($installDir, "\0")) {
            throw new InstallException('The install directory must not contain a newline or NUL');
        }
        $lines = explode("\n", $template);
        if (end($lines) === '') {
            array_pop($lines);
        }
        $baked = false;
        foreach ($lines as $index => $line) {
            if ($line === self::PLACEHOLDER_LINE) {
                $lines[$index] = "INSTALL_DIR='" . str_replace("'", "'\\''", $installDir) . "'";
                $baked = true;
            }
        }
        if (!$baked) {
            throw new InstallException('The launcher template has no install directory line');
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Replace the launcher at `$path` with one rendered from `$templatePath`, in one rename.
     *
     * @throws InstallException
     */
    public static function write(string $templatePath, string $installDir, string $path): void
    {
        $template = @file_get_contents($templatePath);
        if ($template === false) {
            throw new InstallException('The release has no launcher template at ' . $templatePath);
        }
        $bytes = self::render($template, $installDir);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new InstallException('Could not create ' . $directory);
        }
        $temporary = $directory . '/.knossos.tmp.' . getmypid();
        if (@file_put_contents($temporary, $bytes) === false || !@chmod($temporary, 0o755) || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new InstallException('Could not write the launcher ' . $path);
        }
    }

    /**
     * Put back a launcher as {@see read()} found it: its bytes, or nothing when there was none.
     *
     * @throws InstallException
     */
    public static function restore(string $path, ?string $bytes): void
    {
        if ($bytes === null) {
            @unlink($path);

            return;
        }
        $temporary = dirname($path) . '/.knossos.tmp.' . getmypid();
        if (@file_put_contents($temporary, $bytes) === false || !@chmod($temporary, 0o755) || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new InstallException('Could not restore the launcher ' . $path);
        }
    }

    /** The launcher's bytes now, or null when there is none. */
    public static function read(string $path): ?string
    {
        $bytes = is_file($path) ? @file_get_contents($path) : false;

        return $bytes === false ? null : $bytes;
    }
}
