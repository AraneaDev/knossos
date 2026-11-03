<?php

declare(strict_types=1);

namespace Knossos\Runtime;

use InvalidArgumentException;
use JsonException;
use Knossos\Filesystem\AtomicFile;
use Knossos\Roots\AllowedRoots;
use Knossos\Roots\StartClaim;

/**
 * The projects whose session start opens the pane, kept in `pane-autostart.json`.
 *
 * The file holds `{"roots": [...]}`, one project toplevel per entry, and sits
 * beside the roots file. Reading is for `startUp`, which runs on every session
 * and must never fail it: no lock, and an absent, unreadable or corrupt file
 * simply reads as off. Writing refuses a file it cannot understand, so a
 * switch is never set by discarding what somebody else put there.
 */
final class PaneAutostart
{
    public function __construct(private readonly string $path) {}

    /** Whether the project around a folder opens the pane at session start; false for anything unreadable. */
    public function isOn(string $root): bool
    {
        try {
            return in_array(self::key($root), $this->read(), true);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Switch the project around a folder on or off.
     *
     * @throws InvalidArgumentException when the file exists but cannot be understood, or cannot be written
     */
    public function set(string $root, bool $on): void
    {
        $key = self::key($root);
        $others = array_values(array_filter($this->read(), static fn(string $entry): bool => $entry !== $key));
        $roots = $on ? [...$others, $key] : $others;
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true)) {
            throw new InvalidArgumentException(sprintf('Unable to create %s.', $directory));
        }
        if (!AtomicFile::writeJson($this->path, ['roots' => $roots], 0o600, true)) {
            throw new InvalidArgumentException(sprintf('Unable to write %s.', $this->path));
        }
    }

    /** The key a folder is stored under: the toplevel of the repository around it. */
    private static function key(string $root): string
    {
        return AllowedRoots::normaliseRoot(StartClaim::targetOf($root, null)['root']);
    }

    /**
     * The stored keys, none when the file is absent.
     *
     * @return list<string>
     * @throws InvalidArgumentException when the file is unreadable or not the expected shape
     */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $contents = @file_get_contents($this->path);
        if ($contents === false) {
            throw new InvalidArgumentException(sprintf('Unable to read %s.', $this->path));
        }
        try {
            $decoded = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException(
                sprintf('%s is not valid JSON (%s). Fix or remove it before retrying.', $this->path, $error->getMessage()),
                previous: $error,
            );
        }
        $roots = is_array($decoded) ? ($decoded['roots'] ?? null) : null;
        if (!is_array($roots)) {
            throw new InvalidArgumentException(sprintf('%s does not have the expected {"roots": [...]} shape.', $this->path));
        }

        return array_values(array_filter($roots, is_string(...)));
    }
}
