<?php

declare(strict_types=1);

namespace Knossos\Install;

/**
 * What kind of Knossos this is: a release installed by `install.sh`, or a source checkout.
 *
 * The answer comes from where the running code lives, never from the presence
 * of `.git` and never from `KNOSSOS_DATA_DIR`: that variable says where the
 * graph lives, which may be anywhere. A release lives at
 * `<install dir>/versions/<version>/`, so the install directory, the one
 * holding `versions/`, `current`, `install.json`, `update-check.json` and the
 * plugin, is derived from the real path of the running code. Anything else is
 * a checkout (or an image) that something other than self-update maintains.
 */
final readonly class Installation
{
    /** The directory under the install directory that holds the materialised plugin. */
    public const PLUGIN_DIRECTORY = 'plugin';

    /** Where the release lives (the directory holding `versions/`), or null for a source checkout. */
    public ?string $installDir;

    /** @param string $root where the running code lives (the directory holding `bin/` and `src/`) */
    public function __construct(public string $root)
    {
        $this->installDir = self::installDirOf($root);
    }

    /** Whether the running code is anything other than `<install dir>/versions/<version>`. */
    public function isSourceCheckout(): bool
    {
        return $this->installDir === null;
    }

    /**
     * The install directory, for code that only makes sense in an installed release.
     *
     * @throws InstallException in a source checkout
     */
    public function directory(): string
    {
        return $this->installDir ?? throw new InstallException('This is a source checkout: update it with git pull.');
    }

    /**
     * The decoded `install.json`, or null in a checkout or when it is missing or unreadable.
     *
     * @return array<string, mixed>|null
     */
    public function record(): ?array
    {
        if ($this->installDir === null) {
            return null;
        }
        $bytes = @file_get_contents($this->installDir . '/install.json');
        $decoded = $bytes === false ? null : json_decode($bytes, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** `native` or `container` as `install.json` names it, or null when there is no usable record. */
    public function mode(): ?string
    {
        $mode = $this->record()['mode'] ?? null;

        return $mode === 'native' || $mode === 'container' ? $mode : null;
    }

    /**
     * The directory the launcher was written to, as `install.json` records it.
     *
     * A record without `bin_dir` was written before the field existed, when the
     * launcher could only be in the default place, so that is the answer then.
     *
     * @throws InstallException when there is no record of it and HOME is not set either
     */
    public function binDir(): string
    {
        $recorded = $this->record()['bin_dir'] ?? null;
        if (is_string($recorded) && str_starts_with($recorded, '/')) {
            return rtrim($recorded, '/') === '' ? '/' : rtrim($recorded, '/');
        }
        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            throw new InstallException('install.json names no bin directory and HOME is not set; run the installer again');
        }

        return rtrim($home, '/') . '/.local/bin';
    }

    /** The launcher's path: `<bin dir>/knossos`. @throws InstallException see {@see binDir()} */
    public function launcher(): string
    {
        return rtrim($this->binDir(), '/') . '/knossos';
    }

    /** Where the plugin of this installation is materialised. @throws InstallException in a source checkout */
    public function pluginDir(): string
    {
        return $this->directory() . '/' . self::PLUGIN_DIRECTORY;
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

    /**
     * `<dir>` when the real path of `$root` is `<dir>/versions/<version>`, else null.
     *
     * A partial unpack (`<version>.partial.<pid>`), which runs its doctor
     * before it is a version, is not one.
     */
    private static function installDirOf(string $root): ?string
    {
        $real = realpath($root);
        if ($real === false) {
            return null;
        }
        $version = basename($real);
        $versions = dirname($real);
        if (basename($versions) !== 'versions' || preg_match('/^[0-9][0-9A-Za-z.-]*$/', $version) !== 1 || str_contains($version, '.partial.')) {
            return null;
        }

        return dirname($versions);
    }
}
