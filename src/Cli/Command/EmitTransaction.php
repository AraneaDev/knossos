<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;

/**
 * The writes one plugin materialisation makes under a target directory, and how to undo them.
 *
 * Every directory it creates, every file it creates and the original bytes of
 * every file it replaces are recorded as the write happens, so a failure half
 * way can put the target back exactly as it was found. When the target did not
 * exist at all beforehand, everything under it is ours and rollback removes
 * the whole tree.
 */
final class EmitTransaction
{
    /** Whether the target existed before this transaction began. */
    private readonly bool $existed;

    /** @var list<string> */
    private array $createdDirectories = [];

    /** @var list<string> */
    private array $createdFiles = [];

    /**
     * Contents of files that were already there and have been replaced.
     * Tracking only what was CREATED left an overwritten file holding the new
     * bytes after a later step failed, which is exactly what a rollback must
     * not do.
     *
     * @var array<string, string> path => contents as found
     */
    private array $replacedFiles = [];

    /** Begin a transaction against $out, noting whether it is already there. */
    public function __construct(private readonly string $out)
    {
        $this->existed = file_exists($out);
    }

    /** Whether the target directory existed before this transaction began. */
    public function existed(): bool
    {
        return $this->existed;
    }

    /** Create $path and its parents unless it is already a directory. */
    public function mkdir(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!@mkdir($path, 0o755, true)) {
            throw new InvalidArgumentException(sprintf('Unable to create %s.', $path));
        }
        $this->createdDirectories[] = $path;
    }

    /** Copy $source over $target, naming $label if the copy fails. */
    public function copy(string $source, string $target, string $label): void
    {
        $isNew = $this->prepare($target);
        if (!@copy($source, $target)) {
            throw new InvalidArgumentException(sprintf('Unable to copy %s.', $label));
        }
        $this->recordCreated($target, $isNew);
    }

    /** Write $contents to $target. */
    public function write(string $target, string $contents): void
    {
        $isNew = $this->prepare($target);
        if (@file_put_contents($target, $contents) === false) {
            throw new InvalidArgumentException(sprintf('Unable to write %s.', $target));
        }
        $this->recordCreated($target, $isNew);
    }

    /**
     * Undo exactly what this transaction wrote.
     *
     * When the target did not exist at all beforehand, the whole tree is
     * removed. Otherwise replaced files get their original bytes back first,
     * so a path that was both is put back rather than deleted, then the
     * created files are removed, then the created directories in reverse
     * order, leaving anything that predates the transaction untouched.
     */
    public function rollback(): void
    {
        if (!$this->existed) {
            self::removeTree($this->out);
            return;
        }
        foreach ($this->replacedFiles as $file => $original) {
            @file_put_contents($file, $original);
        }
        foreach ($this->createdFiles as $file) {
            if (array_key_exists($file, $this->replacedFiles)) {
                continue;
            }
            @unlink($file);
        }
        foreach (array_reverse($this->createdDirectories) as $directory) {
            @rmdir($directory);
        }
    }

    /** Recursively remove a path, best-effort. */
    public static function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }

    /**
     * Note whether $target is new and keep its bytes if it is about to be replaced.
     *
     * @return bool whether $target did not exist before this write
     */
    private function prepare(string $target): bool
    {
        $isNew = !file_exists($target);
        if (is_file($target)) {
            $original = @file_get_contents($target);
            if ($original !== false) {
                $this->replacedFiles[$target] = $original;
            }
        }

        return $isNew;
    }

    /** Record $target as created by this transaction when it did not exist before. */
    private function recordCreated(string $target, bool $isNew): void
    {
        if ($isNew) {
            $this->createdFiles[] = $target;
        }
    }
}
