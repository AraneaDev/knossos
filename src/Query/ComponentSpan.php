<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * The hash of a component's own lines, by their text rather than their
 * place: a note rests on it, so an edit elsewhere in the file, or one that
 * only moves the component down, leaves the note standing.
 *
 * A file's lines are read only when the file on disk is the one the scan
 * read, so the scan's line numbers describe it.
 */
final class ComponentSpan
{
    /**
     * The sha256 of lines `$startLine` to `$endLine` (1-based, inclusive) of
     * `$fileContents`, joined by "\n"; lines past the end are the lines there are.
     */
    public static function hash(string $fileContents, int $startLine, int $endLine): string
    {
        $text = str_ends_with($fileContents, "\n") ? substr($fileContents, 0, -1) : $fileContents;
        $lines = $text === '' ? [] : explode("\n", $text);
        $start = max(1, $startLine);

        return hash('sha256', implode("\n", array_slice($lines, $start - 1, max(0, $endLine - $start + 1))));
    }

    /**
     * The contents of `$relative` under `$root` when they hash to
     * `$scannedHash`, the hash the scan read it at; null when the disk holds
     * something else, or nothing a span can be read from.
     */
    public static function scannedContents(string $root, string $relative, string $scannedHash): ?string
    {
        $path = $root . '/' . $relative;
        if (!is_file($path) || filesize($path) > ProjectFileHash::MAX_BYTES) {
            return null;
        }
        $contents = @file_get_contents($path);

        return is_string($contents) && hash('sha256', $contents) === $scannedHash ? $contents : null;
    }
}
