<?php

declare(strict_types=1);

namespace Knossos\Install;

/**
 * Releases of Knossos on GitHub, or from a local directory.
 *
 * The base is the same `KNOSSOS_RELEASE_BASE` the installer honours: a
 * releases URL, a `file://` URL or a plain directory holding the tarballs and
 * SHA256SUMS flat. HTTP goes through PHP streams with a short timeout and a
 * fixed User-Agent that names neither the user nor a project.
 */
final class GitHubReleaseSource implements ReleaseSource
{
    /** The releases URL every installation defaults to. */
    public const DEFAULT_BASE = 'https://github.com/AraneaDev/knossos/releases';

    /** The API answer that names the newest release. */
    public const LATEST_API = 'https://api.github.com/repos/AraneaDev/knossos/releases/latest';

    /** Upper bound on one transfer in seconds: the stream timeout is per read, so a trickling server needs this too. */
    private const MAX_SECONDS = 600;

    /** Upper bound on one download, so a hostile or broken server cannot fill the disk. */
    private const MAX_BYTES = 536_870_912;

    private readonly string $base;
    private readonly bool $remote;

    /** @var callable(string, ?string): string */
    private $get;

    /**
     * @param string|null $base defaults to `KNOSSOS_RELEASE_BASE`, then {@see DEFAULT_BASE}
     * @param (callable(string, ?string): string)|null $get returns the body of a URL, or writes it to the
     *        given file and returns an empty string; tests pass a fake
     * @param string|null $platform defaults to this host's
     */
    public function __construct(?string $base = null, ?callable $get = null, private readonly ?string $platform = null)
    {
        $base ??= (getenv('KNOSSOS_RELEASE_BASE') ?: self::DEFAULT_BASE);
        $this->remote = str_starts_with($base, 'http://') || str_starts_with($base, 'https://');
        $this->base = rtrim(str_starts_with($base, 'file://') ? substr($base, 7) : $base, '/');
        $this->get = $get ?? self::http(...);
    }

    /** {@inheritDoc} */
    public function latest(): string
    {
        if (!$this->remote) {
            return $this->latestInDirectory();
        }
        if ($this->base !== self::DEFAULT_BASE) {
            throw new InstallException('The latest release cannot be looked up on a custom release base; name a version with --version.');
        }
        $decoded = json_decode(($this->get)(self::LATEST_API, null), true);
        $tag = is_array($decoded) && is_string($decoded['tag_name'] ?? null) ? $decoded['tag_name'] : '';

        return ReleaseAsset::assertVersion(ltrim($tag, 'v'));
    }

    /** {@inheritDoc} */
    public function fetch(string $version, string $asset, string $to): void
    {
        ReleaseAsset::assertVersion($version);
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $asset)) {
            throw new InstallException(sprintf('Not a valid asset name: %s', $asset));
        }
        if ($this->remote) {
            ($this->get)(sprintf('%s/download/v%s/%s', $this->base, $version, $asset), $to);

            return;
        }
        $from = $this->base . '/' . $asset;
        if (!is_file($from) || !@copy($from, $to)) {
            throw new InstallException(sprintf('Release asset %s not found at %s', $asset, $from));
        }
    }

    /** The highest version that has a tarball for this platform in a local directory. */
    private function latestInDirectory(): string
    {
        $suffix = '-' . ($this->platform ?? ReleaseAsset::platform()) . '.tar.gz';
        $newest = null;
        foreach (glob($this->base . '/knossos-*' . $suffix) ?: [] as $file) {
            $version = substr(basename($file), strlen('knossos-'), -strlen($suffix));
            if (preg_match('/^[0-9]+(\.[0-9]+)*(-[0-9A-Za-z.-]+)?$/', $version) === 1 && ($newest === null || version_compare($version, $newest, '>'))) {
                $newest = $version;
            }
        }
        if ($newest === null) {
            throw new InstallException(sprintf('No release asset knossos-<version>%s in %s', $suffix, $this->base));
        }

        return $newest;
    }

    /**
     * One HTTPS GET: the body, or written to `$to` when a file is given.
     *
     * @throws InstallException
     */
    private static function http(string $url, ?string $to): string
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => 10,
            'follow_location' => 1,
            'max_redirects' => 5,
            'ignore_errors' => false,
            'header' => "User-Agent: knossos-self-update\r\nAccept: application/vnd.github+json, application/octet-stream\r\n",
        ]]);
        $in = @fopen($url, 'rb', false, $context);
        if ($in === false) {
            throw new InstallException(sprintf('Could not download %s', $url));
        }
        try {
            $deadline = time() + self::MAX_SECONDS;
            $out = $to === null ? fopen('php://memory', 'w+b') : @fopen($to, 'wb');
            if ($out === false) {
                throw new InstallException(sprintf('Could not write %s', $to ?? 'memory'));
            }
            try {
                $total = 0;
                while (!feof($in)) {
                    $chunk = fread($in, 65_536);
                    $limit = $to === null ? 1_048_576 : self::MAX_BYTES;
                    if ($chunk === false || time() > $deadline || ($total += strlen($chunk)) > $limit) {
                        throw new InstallException(sprintf('Could not download %s completely', $url));
                    }
                    fwrite($out, $chunk);
                }
                if ($to !== null) {
                    return '';
                }
                rewind($out);

                return (string) stream_get_contents($out);
            } finally {
                fclose($out);
            }
        } finally {
            fclose($in);
        }
    }
}
