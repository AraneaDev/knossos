<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * The hash of a component's own lines, by their text rather than their
 * place: a note rests on it, so an edit elsewhere in the file, or one that
 * only moves the component down, leaves the note standing.
 *
 * A file's lines are read only when the file on disk is the one the scan
 * read, so the scan's line numbers describe it. Only a component declared in
 * part of its file has a span: a module, a package or an external name stands
 * for its whole file (a worker gives a file's module its first line alone),
 * so a note on one rests on the whole file.
 */
final class ComponentSpan
{
    /** The node kinds that declare a component in some lines of its file. */
    public const DECLARATION_KINDS = ['class', 'interface', 'trait', 'enum', 'function', 'method', 'property', 'constant', 'namespace', 'type_alias', 'object', 'variable'];

    /**
     * An SQL aggregate that is 1 when every node `$alias` of the group is of
     * a {@see self::DECLARATION_KINDS} kind, so the group's lines are a span.
     */
    public static function declaredOnly(string $alias): string
    {
        return sprintf("MIN(%s.kind IN ('%s'))", $alias, implode("', '", self::DECLARATION_KINDS));
    }

    /**
     * The sha256 of lines `$startLine` to `$endLine` (1-based, inclusive) of
     * `$fileContents`, joined by "\n"; lines past the end are the lines there are.
     */
    public static function hash(string $fileContents, int $startLine, int $endLine): string
    {
        return self::hashLines(self::lines($fileContents), $startLine, $endLine);
    }

    /**
     * {@see self::hash()} of each span, keyed as given, the file split into lines once.
     *
     * @template K of array-key
     * @param array<K, array{0: int, 1: int}> $spans start and end line of each
     * @return array<K, string>
     */
    public static function hashes(string $fileContents, array $spans): array
    {
        $lines = self::lines($fileContents);

        return array_map(static fn(array $span): string => self::hashLines($lines, $span[0], $span[1]), $spans);
    }

    /** @return list<string> */
    private static function lines(string $fileContents): array
    {
        $text = str_ends_with($fileContents, "\n") ? substr($fileContents, 0, -1) : $fileContents;

        return $text === '' ? [] : explode("\n", $text);
    }

    /** @param list<string> $lines */
    private static function hashLines(array $lines, int $startLine, int $endLine): string
    {
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
