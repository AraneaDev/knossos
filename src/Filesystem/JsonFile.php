<?php

declare(strict_types=1);

namespace Knossos\Filesystem;

use InvalidArgumentException;
use JsonException;

/**
 * Reads a small JSON settings file the way the roots file and the pane setting both need.
 *
 * A file that cannot be read or is not JSON is an {@see InvalidArgumentException}
 * naming the file, so a writer never replaces content it could not understand;
 * what the decoded value must look like is for the caller to say.
 */
final class JsonFile
{
    /**
     * The decoded contents of an existing file.
     *
     * @throws InvalidArgumentException when the file is unreadable or not valid JSON
     */
    private static function decode(string $path): mixed
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new InvalidArgumentException(sprintf('Unable to read %s.', $path));
        }
        try {
            return json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException(
                sprintf('%s is not valid JSON (%s). Fix or remove it before retrying.', $path, $error->getMessage()),
                previous: $error,
            );
        }
    }

    /**
     * The string entries of a `{"roots": [...]}` file, none when the file is absent.
     *
     * @param bool $acceptBare also accept a bare `[...]`, as a server reading the roots file does
     * @return list<string>
     * @throws InvalidArgumentException when the file is unreadable, not JSON, or not that shape
     */
    public static function roots(string $path, bool $acceptBare): array
    {
        if (!is_file($path)) {
            return [];
        }
        $decoded = self::decode($path);
        $listed = is_array($decoded) ? ($decoded['roots'] ?? ($acceptBare ? $decoded : null)) : null;
        if (!is_array($listed)) {
            throw new InvalidArgumentException(sprintf('%s does not have the expected {"roots": [...]} shape.', $path));
        }

        return array_values(array_filter($listed, is_string(...)));
    }

    /**
     * Make sure the directory a file goes in exists, owner-only like the data directory it sits in.
     *
     * @throws InvalidArgumentException when it cannot be created
     */
    public static function ensureDirectoryOf(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new InvalidArgumentException(sprintf('Unable to create %s.', $directory));
        }
    }

    /**
     * Write the `{"roots": [...]}` file whole, owner-only on a first write and keeping the mode of one that exists.
     *
     * @param list<string> $roots
     * @throws InvalidArgumentException when the directory or the file cannot be written
     */
    public static function writeRoots(string $path, array $roots): void
    {
        self::ensureDirectoryOf($path);
        if (!AtomicFile::writeJson($path, ['roots' => $roots], 0o600, true)) {
            throw new InvalidArgumentException(sprintf('Unable to write %s.', $path));
        }
    }
}
