<?php

declare(strict_types=1);

namespace Knossos\Filesystem;

/**
 * Replaces a file whole: written to a temporary file beside it, then renamed
 * into place.
 *
 * rename() is atomic only within one filesystem, so the temporary file sits in
 * the target's directory under a random name; a reader sees the old file or
 * the new one, never half of either, and two writers never share a temporary
 * file. The mode is set on the temporary file before the rename, so the target
 * is never in place, even briefly, with the wrong permissions. Every failure
 * removes the temporary file, so a failed write leaves no litter.
 */
final class AtomicFile
{
    /**
     * Write $data as pretty-printed JSON with a trailing newline.
     *
     * @param array<array-key, mixed> $data
     * @param int|null $mode the mode for the file; null leaves the umask default
     * @param bool $preserveMode keep the mode of a target that already exists,
     *        so a file the operator chmod'd is not widened or narrowed by a rewrite;
     *        $mode then applies only to a file created for the first time
     *
     * @throws \JsonException when $data cannot be encoded
     */
    public static function writeJson(string $path, array $data, ?int $mode = null, bool $preserveMode = false): bool
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return self::write($path, $encoded . PHP_EOL, $mode, $preserveMode);
    }

    /**
     * Write $contents to $path atomically; false when any step failed, with
     * the target untouched.
     *
     * With a mode, the temporary file is created under umask 077, so it is
     * never readable by anyone else before the chmod narrows or sets it.
     *
     * @param int|null $mode the mode for the file; null leaves the umask default
     * @param bool $preserveMode keep the mode of a target that already exists
     */
    public static function write(string $path, string $contents, ?int $mode = null, bool $preserveMode = false): bool
    {
        $mode = ($preserveMode ? self::existingMode($path) : null) ?? $mode;
        $temporary = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $umask = $mode === null ? null : umask(0o077);
        try {
            $written = @file_put_contents($temporary, $contents);
        } finally {
            if ($umask !== null) {
                umask($umask);
            }
        }
        if ($written === false || ($mode !== null && !@chmod($temporary, $mode)) || !@rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    /**
     * The permission bits of an existing file, or null when there is none.
     *
     * A file whose fileperms() call fails (a stat race, say) counts as none:
     * an unknown mode is not a reason to invent one, and `false & 0o777`
     * would otherwise coerce to 0 and lock the file to mode 0000.
     */
    private static function existingMode(string $path): ?int
    {
        if (!is_file($path)) {
            return null;
        }
        $mode = @fileperms($path);

        return $mode === false ? null : $mode & 0o777;
    }
}
