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

    /** @var callable(string): string */
    private $resolve;

    /**
     * @param string|null $base defaults to `KNOSSOS_RELEASE_BASE`, then {@see DEFAULT_BASE}
     * @param (callable(string, ?string): string)|null $get returns the body of a URL, or writes it to the
     *        given file and returns an empty string; tests pass a fake
     * @param string|null $platform defaults to this host's
     * @param (callable(string): string)|null $resolve returns the URL a URL finally redirects to; tests pass a fake
     */
    public function __construct(?string $base = null, ?callable $get = null, private readonly ?string $platform = null, ?callable $resolve = null)
    {
        $base ??= (getenv('KNOSSOS_RELEASE_BASE') ?: self::DEFAULT_BASE);
        $this->remote = str_starts_with($base, 'http://') || str_starts_with($base, 'https://');
        $this->base = rtrim(str_starts_with($base, 'file://') ? substr($base, 7) : $base, '/');
        $this->get = $get ?? self::http(...);
        $this->resolve = $resolve ?? self::finalUrl(...);
    }

    /** {@inheritDoc} */
    public function latest(): string
    {
        if (!$this->remote) {
            return $this->latestInDirectory();
        }
        if ($this->base !== self::DEFAULT_BASE) {
            // A mirror is asked the way install.sh asks it: `<base>/latest` redirects to `<base>/tag/v<version>`.
            $final = ($this->resolve)($this->base . '/latest');
            $tag = basename((string) parse_url($final, PHP_URL_PATH));
            if (!str_starts_with($tag, 'v') || $final === $this->base . '/latest') {
                throw new InstallException(sprintf('%s/latest did not lead to a release tag; name a version with --version.', $this->base));
            }

            return ReleaseAsset::assertVersion(substr($tag, 1));
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
     * Where a URL ends up after its redirects, read from the response headers only.
     *
     * The one call that needs the network; what it does with the answer is {@see locationOf()}.
     *
     * @throws InstallException
     */
    private static function finalUrl(string $url): string
    {
        $headers = @get_headers($url, true, stream_context_create(self::httpOptions('HEAD', '')));
        if ($headers === false) {
            throw new InstallException(sprintf('Could not reach %s', $url));
        }

        return self::locationOf($headers, $url);
    }

    /**
     * The URL a response with these headers leads to: the last `Location`, resolved against `$url` when it has no host.
     *
     * @param array<int|string, mixed> $headers as `get_headers($url, true)` returns them, every hop's included
     */
    private static function locationOf(array $headers, string $url): string
    {
        $location = $headers['Location'] ?? $headers['location'] ?? null;
        $last = is_array($location) ? end($location) : $location;
        if (!is_string($last) || $last === '') {
            return $url;
        }
        if (str_starts_with($last, '/')) {
            // A path without a host: resolved against the URL that was asked.
            $parts = parse_url($url);

            return sprintf('%s://%s%s%s', $parts['scheme'] ?? 'https', $parts['host'] ?? '', isset($parts['port']) ? ':' . $parts['port'] : '', $last);
        }

        return $last;
    }

    /**
     * One HTTPS GET: the body, or written to `$to` when a file is given.
     *
     * The stream is opened here; reading it, with its limits, is {@see copy()}.
     *
     * @throws InstallException
     */
    private static function http(string $url, ?string $to): string
    {
        $in = @fopen($url, 'rb', false, stream_context_create(self::httpOptions('GET', "Accept: application/vnd.github+json, application/octet-stream\r\n")));
        if ($in === false) {
            throw new InstallException(sprintf('Could not download %s', $url));
        }
        try {
            return self::copy($in, $to, $url, $to === null ? 1_048_576 : self::MAX_BYTES, time() + self::MAX_SECONDS);
        } finally {
            fclose($in);
        }
    }

    /**
     * The options of every request: a short timeout, a few redirects and a User-Agent that names no user or project.
     *
     * @return array{http: array<string, mixed>}
     */
    private static function httpOptions(string $method, string $headers): array
    {
        return ['http' => [
            'method' => $method,
            'timeout' => 10,
            'follow_location' => 1,
            'max_redirects' => 5,
            'ignore_errors' => false,
            'header' => "User-Agent: knossos-self-update\r\n" . $headers,
        ]];
    }

    /**
     * Read `$in` to its end: into `$to` when a file is given (returning an empty string), else into the returned string.
     *
     * @param resource $in
     * @param int $limit the most bytes accepted, so a hostile or broken server cannot fill the disk or the memory
     * @param int $deadline the time by which the whole transfer must be done: a stream timeout is per read
     * @throws InstallException
     */
    private static function copy($in, ?string $to, string $url, int $limit, int $deadline): string
    {
        $out = $to === null ? fopen('php://memory', 'w+b') : @fopen($to, 'wb');
        if ($out === false) {
            throw new InstallException(sprintf('Could not write %s', $to ?? 'memory'));
        }
        try {
            $total = 0;
            while (!feof($in)) {
                $chunk = fread($in, 65_536);
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
    }
}
