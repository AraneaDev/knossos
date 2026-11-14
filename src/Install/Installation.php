<?php

declare(strict_types=1);

namespace Knossos\Install;

/**
 * What kind of Knossos this is: a release installed by `install.sh`, or a source checkout.
 *
 * The answer comes from where the running code lives, never from the presence
 * of `.git`: a release lives under `<data dir>/versions/`, anything else is a
 * checkout (or an image) that something other than self-update maintains.
 */
final readonly class Installation
{
    /** @param string $root where the running code lives (the directory holding `bin/` and `src/`) */
    public function __construct(public string $dataDir, public string $root) {}

    /** Whether the running code is outside `<data dir>/versions/`. */
    public function isSourceCheckout(): bool
    {
        $versions = realpath($this->dataDir . '/versions');
        $root = realpath($this->root);

        return $versions === false || $root === false || !str_starts_with($root . '/', $versions . '/');
    }

    /**
     * The decoded `install.json`, or null when it is missing or unreadable.
     *
     * @return array<string, mixed>|null
     */
    public function record(): ?array
    {
        $bytes = @file_get_contents($this->dataDir . '/install.json');
        $decoded = $bytes === false ? null : json_decode($bytes, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** `native` or `container` as `install.json` names it, or null when there is no usable record. */
    public function mode(): ?string
    {
        $mode = $this->record()['mode'] ?? null;

        return $mode === 'native' || $mode === 'container' ? $mode : null;
    }

    /** One line for `knossos doctor`: the mode, the version and where it lives. */
    public function describe(): string
    {
        if ($this->isSourceCheckout()) {
            return sprintf('source checkout at %s', $this->root);
        }

        return sprintf(
            '%s %s at %s',
            $this->mode() ?? 'unrecorded mode',
            (string) ($this->record()['version'] ?? 'unknown version'),
            $this->root,
        );
    }
}
