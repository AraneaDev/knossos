<?php

declare(strict_types=1);

namespace Knossos\Install;

/**
 * The cached answer to "is there a newer release", in `<data dir>/update-check.json`.
 *
 * Written only by `knossos self-update --check`. The session brief reads it
 * and nothing else, so the brief never waits for the network.
 */
final readonly class UpdateCheck
{
    /** How long an answer stays fresh. */
    public const MAX_AGE_SECONDS = 86400;

    public function __construct(private string $dataDir) {}

    /**
     * The cached answer, or null when there is none or it is not readable.
     *
     * @return array{checked_at: string, latest: string}|null
     */
    public function read(): ?array
    {
        $bytes = @file_get_contents($this->path());
        $decoded = $bytes === false ? null : json_decode($bytes, true);
        if (!is_array($decoded) || !is_string($decoded['checked_at'] ?? null) || !is_string($decoded['latest'] ?? null)) {
            return null;
        }

        return ['checked_at' => $decoded['checked_at'], 'latest' => $decoded['latest']];
    }

    /** Whether a new look is due: no usable answer, or one older than 24 hours. */
    public function due(?int $now = null): bool
    {
        $cached = $this->read();
        $at = $cached === null ? false : strtotime($cached['checked_at']);

        return $at === false || ($now ?? time()) - $at >= self::MAX_AGE_SECONDS;
    }

    /** Remember that `$latest` was the newest release at `$now`. */
    public function record(string $latest, ?int $now = null): void
    {
        if (!is_dir($this->dataDir) && !@mkdir($this->dataDir, 0o700, true) && !is_dir($this->dataDir)) {
            return;
        }
        $bytes = json_encode(['checked_at' => gmdate('Y-m-d\TH:i:s\Z', $now ?? time()), 'latest' => $latest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $temporary = $this->path() . '.tmp.' . getmypid();
        if (@file_put_contents($temporary, $bytes) === false || !@rename($temporary, $this->path())) {
            @unlink($temporary);
        }
    }

    /** The cached latest version when it is newer than `$current`, else null. */
    public function newerThan(string $current): ?string
    {
        $latest = $this->read()['latest'] ?? null;

        return $latest !== null && version_compare($latest, $current, '>') ? $latest : null;
    }

    /** The cache file. */
    private function path(): string
    {
        return rtrim($this->dataDir, '/') . '/update-check.json';
    }
}
