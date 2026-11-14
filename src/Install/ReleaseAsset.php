<?php

declare(strict_types=1);

namespace Knossos\Install;

/** The names a release publishes, and which build belongs to this host. */
final class ReleaseAsset
{
    /** The checksum list every release carries. */
    public const CHECKSUMS = 'SHA256SUMS';

    /** The platform tag of this host, such as `linux-x86_64` or `darwin-arm64`. */
    public static function platform(?string $family = null, ?string $machine = null): string
    {
        $family ??= PHP_OS_FAMILY;
        $machine ??= php_uname('m');
        $os = match ($family) {
            'Linux' => 'linux',
            'Darwin' => 'darwin',
            default => throw new InstallException(sprintf('Unsupported operating system: %s', $family)),
        };
        $arch = match ($machine) {
            'x86_64', 'amd64' => 'x86_64',
            'arm64', 'aarch64' => 'arm64',
            default => throw new InstallException(sprintf('Unsupported architecture: %s', $machine)),
        };

        return $os . '-' . $arch;
    }

    /** The tarball's file name for a version on a platform. */
    public static function tarball(string $version, string $platform): string
    {
        return sprintf('knossos-%s-%s.tar.gz', $version, $platform);
    }

    /**
     * Refuse anything that is not a plain version: it ends up in paths and URLs.
     *
     * @throws InstallException
     */
    public static function assertVersion(string $version): string
    {
        if (preg_match('/^[0-9]+(\.[0-9]+)*(-[0-9A-Za-z.-]+)?$/', $version) !== 1 || str_contains($version, '..')) {
            throw new InstallException(sprintf('Not a valid version: %s', $version));
        }

        return $version;
    }

    /**
     * The checksum a SHA256SUMS list holds for one asset, or null when it has no line for it.
     *
     * Lines are `<hash>  <name>`, as sha256sum and shasum print them.
     */
    public static function expectedChecksum(string $sums, string $asset): ?string
    {
        foreach (preg_split('/\R/', $sums) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{64})[ ]{1,2}\*?(.+)$/', $line, $match) === 1 && $match[2] === $asset) {
                return $match[1];
            }
        }

        return null;
    }
}
