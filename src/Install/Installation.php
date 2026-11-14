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

    /**
     * The directory holding the `versions/` the code sits in, whether or not its record vouches for it.
     *
     * Equal to {@see $installDir} for an installed release. Set while
     * `$installDir` is null when the code sits at `<dir>/versions/<version>`
     * but `<dir>/install.json` is missing, unreadable or records another
     * version, so a refusal can say so instead of calling it a checkout.
     */
    public ?string $layoutDir;

    /** @param string $root where the running code lives (the directory holding `bin/` and `src/`) */
    public function __construct(public string $root)
    {
        $this->layoutDir = self::layoutDirOf($root);
        $this->installDir = $this->layoutDir !== null && self::recordsVersion($this->layoutDir, (string) basename((string) realpath($root))) ? $this->layoutDir : null;
    }

    /**
     * Refuse code that sits in a `versions/` directory its record does not vouch for.
     *
     * Such code is neither a release to update nor a checkout to treat as
     * one: a plugin made from it would live inside `versions/` again. The one
     * message every command gives for that state.
     *
     * @throws InstallException when `$layoutDir` is set but `$installDir` is not
     */
    public function assertNotUnrecorded(): void
    {
        if ($this->installDir === null && $this->layoutDir !== null) {
            throw new InstallException(sprintf(
                'This code sits in %1$s/versions, but %1$s/install.json does not record it as the installed release: run the installer again, or update a source checkout with git pull.',
                $this->layoutDir,
            ));
        }
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
     * Every release writes `bin_dir`. A record without it is not guessed at:
     * writing a launcher to a default place could replace whatever is there,
     * and would leave the launcher the MCP registration names behind.
     *
     * @throws InstallException when the record does not name an absolute bin directory
     */
    public function binDir(): string
    {
        $recorded = $this->record()['bin_dir'] ?? null;
        if (!is_string($recorded) || !str_starts_with($recorded, '/')) {
            throw new InstallException(sprintf('The install record %s/install.json does not name where the launcher is; run the installer again.', $this->directory()));
        }

        return rtrim($recorded, '/') === '' ? '/' : rtrim($recorded, '/');
    }

    /**
     * The graph directory the materialised plugin's hooks were given, or null when there is no such plugin or value.
     *
     * Read back from `<install dir>/plugin/hooks/scripts/lib.sh`, where
     * `install-agent-plugin --data-dir` baked it, so that refreshing the plugin
     * keeps a directory someone chose by hand.
     */
    public function pluginDataDir(): ?string
    {
        $library = @file_get_contents($this->pluginDir() . '/hooks/scripts/lib.sh');
        if ($library === false || preg_match("/^KNOSSOS_INSTALLED_DATA_DIR='((?:[^']|'\\\\'')*)'$/m", $library, $match) !== 1) {
            return null;
        }
        $value = str_replace("'\\''", "'", $match[1]);

        return $value === '' || !str_starts_with($value, '/') ? null : $value;
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
    private static function layoutDirOf(string $root): ?string
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

    /**
     * Whether `<dir>/install.json` is a readable record of a mode and of `$version`.
     *
     * Without it the code is not a release this install made: a checkout
     * that merely sits in a directory called `versions/`, or a version that
     * is no longer the recorded one.
     */
    private static function recordsVersion(string $directory, string $version): bool
    {
        $bytes = @file_get_contents($directory . '/install.json');
        $record = $bytes === false ? null : json_decode($bytes, true);

        return is_array($record) && is_string($record['mode'] ?? null) && ($record['version'] ?? null) === $version;
    }
}
